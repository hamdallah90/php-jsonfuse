#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"

#include "jf_errors.h"
#include "jf_writer.h"

/* Small records batch up to this many bytes per write. */
#define JF_WRITE_BATCH   ((size_t)65536)
/* yyjson_write_number's minimum buffer; bool and null fit too. */
#define JF_NUMBER_BYTES  40

/*
 * The largest overhead a plan can have: per field an escaped name with its comma, quotes
 * and colon, an escaped string default with its quotes, and a value slot. With the record
 * cap it is far below SIZE_MAX / 2, so neither the bound nor fill + rec_len + overhead in
 * jf_writer_reserve can wrap, for any plan Plan::compile accepts.
 */
#define JF_MAX_OVERHEAD ((size_t)JF_MAX_FIELDS * ((4 + JF_MAX_ESCAPE * JF_MAX_NAME_BYTES) \
	+ (2 + JF_MAX_ESCAPE * JF_MAX_NAME_BYTES) + JF_NUMBER_BYTES) + 2)
_Static_assert(JF_MAX_RECORD_BYTES + JF_MAX_OVERHEAD <= SIZE_MAX / 2, "writer bound arithmetic can wrap");

void jf_writer_init(jf_writer *w, const jf_plan_data *plan)
{
	w->plan = plan;
	/*
	 * Prefixes carry the commas: braces and LF are 2 more once the first comma is dropped.
	 * A value is a distinct token of the record or fits JF_NUMBER_BYTES; a default is
	 * neither, so each default's exact output length is added on top (default_total).
	 * Nested paths keep the tokens distinct: each field has its own leaf node, no leaf lies
	 * under another (see jf_intern_path), and a record gives each node at most one
	 * value, so the fields' values are disjoint byte ranges of the record.
	 */
	w->overhead = plan->prefix_total + plan->default_total + JF_NUMBER_BYTES * plan->field_count + 2;
	w->bound = plan->max_record_bytes + w->overhead;
}

void jf_writer_release(jf_writer *w)
{
	if (w->buf != NULL) {
		efree(w->buf);
	}
}

/* Complete lines among the first n buffered bytes: a record's LF is its only raw LF byte. */
static zend_long jf_lines_in(const char *p, size_t n)
{
	const char *end = p + n;
	zend_long lines = 0;

	while ((p = memchr(p, '\n', (size_t)(end - p))) != NULL) {
		lines++;
		p++;
	}
	return lines;
}

bool jf_writer_drain(jf_writer *w, const jf_reader *r)
{
	size_t off = 0;
	bool ok = true;

	while (ok && off < w->fill) {
		php_stream *out = jf_stream_of(r, r->out);
		ssize_t n;

		if (out == NULL) {
			ok = false;
			break;
		}
		n = php_stream_write(out, w->buf + off, w->fill - off);
		/* Counted before the exception check: the stream layer may accept bytes, then a callback throws. */
		if (n > 0) {
			/* A php_user_filter sets this count itself and PHP returns it unchecked. */
			if ((size_t)n > w->fill - off) {
				n = (ssize_t)(w->fill - off);
			}
			if ((zend_ulong)ZEND_LONG_MAX - (zend_ulong)w->written < (zend_ulong)n) {
				jf_fail(r->at, jf_ce_StreamException, JF_R_COUNTER_OVERFLOW, NULL, 0, "byte counter overflow");
				ok = false;
				break;
			}
			w->written += (zend_long)n;
			off += (size_t)n;
		}
		if (!jf_after_stream_call(r)) {
			ok = false;
		} else if (n <= 0) {
			jf_fail(r->at, jf_ce_StreamException, JF_R_WRITE_FAILED, NULL, 0, "writing the output stream failed");
			ok = false;
		}
	}
	if (!ok) {
		w->emitted += jf_lines_in(w->buf, off);
		return false;
	}
	w->emitted += w->records;
	w->records = 0;
	w->fill = 0;
	return true;
}

/* Room for need more bytes. Growing frees the emptied buffer before the preflight. */
static bool jf_writer_reserve(jf_writer *w, const jf_reader *r, size_t need)
{
	size_t cap;

	if (w->fill + need <= w->cap) {
		return true;
	}
	if (!jf_writer_drain(w, r)) {
		return false;
	}
	if (need <= w->cap) {
		return true;
	}
	cap = MAX(need, MIN(MAX(JF_WRITE_BATCH, 2 * w->cap), w->bound));
	if (w->buf != NULL) {
		efree(w->buf);
		w->buf = NULL;
		w->cap = 0;
	}
	if (!jf_mem_fits(r->at, cap)) {
		return false;
	}
	w->buf = emalloc(cap);
	w->cap = cap;
	return true;
}

static char *jf_put_value(char *p, jf_type type, yyjson_val *v, const yyjson_alc *alc)
{
	yyjson_val real;

	if (yyjson_is_null(v)) {
		memcpy(p, "null", 4);
		return p + 4;
	}
	switch (type) {
		case JF_INT64:
			return yyjson_write_number(v, p);
		case JF_FLOAT64:
			if (yyjson_is_raw(v)) {
				/* Validation already proved this conversion succeeds. */
				yyjson_read_number(yyjson_get_raw(v), &real, 0, alc, NULL);
			} else {
				unsafe_yyjson_set_real(&real, yyjson_get_num(v));
			}
			return yyjson_write_number(&real, p);
		case JF_BOOL:
			if (yyjson_get_bool(v)) {
				memcpy(p, "true", 4);
				return p + 4;
			}
			memcpy(p, "false", 5);
			return p + 5;
		case JF_STRING:
			/* A string's output never outgrows its input token, quotes included. */
			*p++ = '"';
			p = jf_escape_bytes(p, unsafe_yyjson_get_str(v), unsafe_yyjson_get_len(v));
			*p++ = '"';
			return p;
	}
	return p;
}

bool jf_writer_record(jf_writer *w, const jf_reader *r, yyjson_val *const *values, size_t rec_len, const yyjson_alc *alc)
{
	const jf_plan_data *plan = w->plan;
	char *p;
	size_t i;

	/*
	 * Limit: the reservation is the worst case of SPEC.md section 6, so a small output
	 * from a large record still reserves the record's whole length. A sizing pass over
	 * the projected values would avoid that at the cost of walking them twice.
	 */
	if (!jf_writer_reserve(w, r, rec_len + w->overhead)) {
		return false;
	}
	p = w->buf + w->fill;
	*p++ = '{';
	for (i = 0; i < plan->field_count; i++) {
		const jf_field_spec *f = &plan->fields[i];
		size_t skip = i == 0;
		yyjson_val *v = values[f->leaf];

		memcpy(p, f->prefix + skip, f->prefix_length - skip);
		p += f->prefix_length - skip;
		if (v == NULL) {
			/* Validation lets a path be missing only from a field with a default. */
			memcpy(p, f->default_out, f->default_length);
			p += f->default_length;
		} else {
			p = jf_put_value(p, f->type, v, alc);
		}
	}
	*p++ = '}';
	*p++ = '\n';
	w->fill = (size_t)(p - w->buf);
	w->records++;
	return true;
}

bool jf_writer_finish(jf_writer *w, const jf_reader *r)
{
	php_stream *out;
	bool userland;
	int result;

	if (!jf_writer_drain(w, r)) {
		return false;
	}
	if (w->written == 0) {
		return true;
	}
	out = jf_stream_of(r, r->out);
	if (out == NULL) {
		return false;
	}
	/* Read before the call, which may close the stream: its ops are gone afterwards. */
	userland = php_stream_is(out, PHP_STREAM_IS_USERSPACE);
	result = php_stream_flush(out);
	if (!jf_after_stream_call(r)) {
		return false;
	}
	if (result != 0 && !userland) {
		jf_fail(r->at, jf_ce_StreamException, JF_R_FLUSH_FAILED, NULL, 0, "flushing the output stream failed");
		return false;
	}
	return true;
}
