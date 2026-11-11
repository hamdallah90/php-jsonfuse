#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"

#include "yyjson.h"

#include "jf_errors.h"
#include "jf_stream.h"

#define JF_READ_CHUNK ((size_t)65536)

php_stream *jf_stream_of(const jf_reader *r, zend_resource *res)
{
	php_stream *s = zend_fetch_resource2(res, NULL, php_file_le_stream(), php_file_le_pstream());

	if (s == NULL) {
		jf_fail(r->at, jf_ce_StreamException, JF_R_STREAM_CLOSED, NULL, 0, "a stream was closed during processing");
	}
	return s;
}

bool jf_after_stream_call(const jf_reader *r)
{
	return !EG(exception) && jf_stream_of(r, r->in) != NULL && jf_stream_of(r, r->out) != NULL;
}

static bool jf_reader_fill(jf_reader *r)
{
	size_t need;
	php_stream *in;
	ssize_t n;
	bool eof;

	if (zend_atomic_bool_load_ex(&EG(timed_out))) {
		jf_fail(r->at, jf_ce_StreamException, JF_R_TIMEOUT, NULL, 0, "stopped by execution timeout");
		return false;
	}

	if (r->pos > 0) {
		size_t avail = r->fill - r->pos;

		if (avail > 0) {
			memmove(r->buf, r->buf + r->pos, avail);
		}
		r->fill = avail;
		r->scan -= r->pos;
		r->pos = 0;
	}

	/* fill <= max_record + 2 here, so this never exceeds the documented bound. */
	need = r->fill + JF_READ_CHUNK + YYJSON_PADDING_SIZE;
	if (need > r->cap) {
		size_t bound = r->max_record + 2 + JF_READ_CHUNK + YYJSON_PADDING_SIZE;
		size_t cap = MAX(need, MIN(2 * r->cap, bound));

		if (!jf_mem_fits(r->at, cap)) {
			return false;
		}
		r->buf = erealloc(r->buf, cap);
		r->cap = cap;
	}

	in = jf_stream_of(r, r->in);
	if (in == NULL) {
		return false;
	}
	n = php_stream_read(in, r->buf + r->fill, JF_READ_CHUNK);
	if (!jf_after_stream_call(r)) {
		return false;
	}
	if (n < 0) {
		jf_fail(r->at, jf_ce_StreamException, JF_R_READ_FAILED, NULL, 0, "reading the input stream failed");
		return false;
	}
	if (n == 0) {
		in = jf_stream_of(r, r->in);
		if (in == NULL) {
			return false;
		}
		eof = php_stream_eof(in);
		if (!jf_after_stream_call(r)) {
			return false;
		}
		if (!eof) {
			jf_fail(r->at, jf_ce_StreamException, JF_R_NO_DATA, NULL, 0, "input stream returned no data before EOF");
			return false;
		}
		r->eof = true;
	}
	r->fill += (size_t)n;
	memset(r->buf + r->fill, 0, YYJSON_PADDING_SIZE);
	return true;
}

/* Hands out `raw` buffered bytes of a physical line, `terminator` of them being LF. */
static jf_line_status jf_reader_take(jf_reader *r, jf_line *line, size_t raw, size_t terminator)
{
	char *base = r->buf + r->pos;
	size_t length = raw;

	if (length > 0 && base[length - 1] == '\r') {
		length--;
	}
	if (length > r->max_record) {
		jf_fail(r->at, jf_ce_RecordException, JF_R_RECORD_TOO_LARGE, NULL, 0, "record exceeds maxRecordBytes");
		return JF_LINE_ERROR;
	}
	line->text = base;
	line->length = length;
	line->consumed = raw + terminator;
	r->pos += line->consumed;
	r->scan = r->pos;
	return JF_LINE_OK;
}

jf_line_status jf_reader_next(jf_reader *r, jf_line *line)
{
	for (;;) {
		size_t avail = r->fill - r->pos;
		char *lf = r->scan < r->fill ? memchr(r->buf + r->scan, '\n', r->fill - r->scan) : NULL;

		if (lf != NULL) {
			return jf_reader_take(r, line, (size_t)(lf - (r->buf + r->pos)), 1);
		}
		r->scan = r->fill;
		/* A CRLF straddling a chunk boundary may leave max_record + CR buffered without LF. */
		if (avail > r->max_record + 2) {
			jf_fail(r->at, jf_ce_RecordException, JF_R_RECORD_TOO_LARGE, NULL, 0, "record exceeds maxRecordBytes");
			return JF_LINE_ERROR;
		}
		if (r->eof) {
			return avail == 0 ? JF_LINE_EOF : jf_reader_take(r, line, avail, 0);
		}
		if (!jf_reader_fill(r)) {
			return JF_LINE_ERROR;
		}
	}
}

void jf_reader_release(jf_reader *r)
{
	if (r->buf != NULL) {
		efree(r->buf);
	}
}
