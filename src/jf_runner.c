#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"

#include <math.h>

#include "jf_errors.h"
#include "jf_parse.h"
#include "jf_plan.h"
#include "jf_runner.h"
#include "jf_stream.h"
#include "jf_writer.h"

zend_class_entry *jf_ce_Stats;
bool jf_fiber_switches_observed;

typedef struct {
	jf_pos at;
	jf_reader reader;
	jf_parser parser;
	jf_writer writer;
	const jf_plan_data *plan;
	yyjson_val *values[JF_MAX_NODES + 1];   /* this record's value per path node, NULL if absent */
#ifndef JF_DISABLE_SCAN
	yyjson_val scanned[JF_MAX_FIELDS + JF_MAX_FILTERS + 1];    /* jf_scan_record's values; a flat plan has at most one node per entry */
	uint64_t key_filter[JF_KEY_FILTER_BITS / 64];   /* see jf_key_filter_bit */
#endif
	zend_long matched;
	jf_failure recorded;                /* a flushing failure while its buffered records are written */
	uint64_t fiber_switches;            /* JF_G(fiber_switches) when the flags were set */
	bool succeeded;
} jf_run;

static void jf_clear_no_fclose(zend_resource *res)
{
	php_stream *s = zend_fetch_resource2(res, NULL, php_file_le_stream(), php_file_le_pstream());

	if (s != NULL) {
		s->flags &= ~PHP_STREAM_FLAG_NO_FCLOSE;
	}
}

/*
 * Runs on every exit path, including after a bailout. After a fiber switch during
 * the run, a fiber may be suspended inside a filter or wrapper callback of either
 * stream, where PHP core relies on the flag and this frame cannot see it. The flags
 * then stay set: fclose() is refused, and the resource destructor still frees the
 * stream with its last reference. Without the observer, every run counts as switched.
 */
static void jf_run_release(jf_run *run)
{
	if (jf_fiber_switches_observed && JF_G(fiber_switches) == run->fiber_switches) {
		jf_clear_no_fclose(run->reader.in);
		jf_clear_no_fclose(run->reader.out);
	}
	jf_reader_release(&run->reader);
	jf_parser_release(&run->parser);
	jf_writer_release(&run->writer);
	jf_failure_release(&run->at.failure);
	jf_failure_release(&run->recorded);
}

static bool jf_field_accepts(jf_type type, yyjson_val *v, const yyjson_alc *alc)
{
	yyjson_val number;

	switch (type) {
		case JF_INT64:
			return yyjson_is_sint(v) || (yyjson_is_uint(v) && yyjson_get_uint(v) <= (uint64_t)INT64_MAX);
		case JF_FLOAT64:
			/* Flags 0: yyjson refuses a value that overflows binary64, so success means finite. */
			return yyjson_is_num(v) || (yyjson_is_raw(v) && yyjson_read_number(yyjson_get_raw(v), &number, 0, alc, NULL) != NULL);
		case JF_BOOL:
			return yyjson_is_bool(v);
		case JF_STRING:
			return yyjson_is_str(v);
	}
	return false;
}

/*
 * A UINT reaches here for an int64 field only above INT64_MAX, so every integer
 * int64 cannot hold gets one word, whether yyjson tagged it UINT or RAW.
 */
static const char *jf_kind_of(jf_type declared, yyjson_val *v)
{
	switch (yyjson_get_type(v)) {
		case YYJSON_TYPE_BOOL: return "bool";
		case YYJSON_TYPE_STR: return "string";
		case YYJSON_TYPE_ARR: return "array";
		case YYJSON_TYPE_OBJ: return "object";
		case YYJSON_TYPE_RAW: return "out-of-range number";
		case YYJSON_TYPE_NUM:
			if (yyjson_is_real(v)) {
				return "real";
			}
			return declared == JF_INT64 && yyjson_is_uint(v) ? "out-of-range number" : "integer";
		default: return "null";
	}
}

/* The type-strict equality, for '=' and each in member. */
static zend_always_inline bool jf_scalar_equals(const jf_scalar *s, yyjson_val *v)
{
	switch (s->kind) {
		case JF_FILTER_STRING:
			return yyjson_is_str(v) && unsafe_yyjson_get_len(v) == s->str_length
				&& memcmp(unsafe_yyjson_get_str(v), s->str, s->str_length) == 0;
		case JF_FILTER_INT:
			if (yyjson_is_sint(v)) {
				return yyjson_get_sint(v) == s->i;
			}
			return yyjson_is_uint(v) && yyjson_get_uint(v) <= (uint64_t)ZEND_LONG_MAX
				&& (zend_long)yyjson_get_uint(v) == s->i;
		case JF_FILTER_FLOAT:
			return yyjson_is_real(v) && yyjson_get_real(v) == s->d;
		case JF_FILTER_BOOL:
			return yyjson_is_bool(v) && yyjson_get_bool(v) == s->b;
		case JF_FILTER_NULL:
			return yyjson_is_null(v);
	}
	return false;
}

/*
 * An integer, the uint64 u when big (above INT64_MAX) and otherwise the int64 i, against a
 * finite double r, by exact value: the integer against r's truncation, then against r's
 * fraction. Every double in [-2^63, 2^64) truncates to an integer that int64 or
 * uint64 holds exactly, so neither conversion below rounds or is undefined; every double
 * outside that range lies beyond every integer a record or operand can hold.
 */
static int jf_cmp_int_real(bool big, uint64_t u, int64_t i, double r)
{
	double t;
	int c;

	if (r >= 0x1p64) {
		return -1;
	}
	if (r < -0x1p63) {
		return 1;
	}
	t = trunc(r);
	if (t >= 0x1p63) {
		uint64_t tu = (uint64_t)t;

		c = big ? (u > tu) - (u < tu) : -1;
	} else {
		int64_t ti = (int64_t)t;

		c = big ? 1 : (i > ti) - (i < ti);
	}
	if (c != 0) {
		return c;
	}
	/* Equal integer parts: a positive fraction puts r above the integer. -0.0 equals 0. */
	return (r < t) - (r > t);
}

/*
 * The record number v (a JSON integer or a REAL, never RAW) against a number bound: -1, 0
 * or 1. Two integers compare as integers, signed and unsigned; two reals by IEEE order.
 */
static int jf_cmp_number(yyjson_val *v, const jf_scalar *s)
{
	bool big;
	uint64_t u = 0;
	int64_t i = 0;

	if (yyjson_is_real(v)) {
		double r = unsafe_yyjson_get_real(v);

		if (s->kind == JF_FILTER_FLOAT) {
			return (r > s->d) - (r < s->d);
		}
		return -jf_cmp_int_real(false, 0, (int64_t)s->i, r);
	}
	big = yyjson_is_uint(v) && unsafe_yyjson_get_uint(v) > (uint64_t)INT64_MAX;
	if (big) {
		u = unsafe_yyjson_get_uint(v);
	} else {
		i = yyjson_is_uint(v) ? (int64_t)unsafe_yyjson_get_uint(v) : unsafe_yyjson_get_sint(v);
	}
	if (s->kind == JF_FILTER_FLOAT) {
		return jf_cmp_int_real(big, u, i, s->d);
	}
	if (big) {
		return 1;
	}
	return (i > (int64_t)s->i) - (i < (int64_t)s->i);
}

/* The record string v against a string bound, unescaped bytes as unsigned, a common prefix putting the shorter first. */
static int jf_cmp_string(yyjson_val *v, const jf_scalar *s)
{
	size_t n = unsafe_yyjson_get_len(v);
	int c = memcmp(unsafe_yyjson_get_str(v), s->str, MIN(n, s->str_length));

	if (c != 0) {
		return c < 0 ? -1 : 1;
	}
	return (n > s->str_length) - (n < s->str_length);
}

/* Whether one operator holds for the present scalar v. A range of the other kind gives no match and no error. */
static bool jf_operator_holds(const jf_operator *o, yyjson_val *v)
{
	int c;
	size_t k;

	switch (o->op) {
		case JF_OP_EQ:
			return jf_scalar_equals(&o->value, v);
		case JF_OP_IN:
			for (k = 0; k < o->member_count; k++) {
				if (jf_scalar_equals(&o->members[k], v)) {
					return true;
				}
			}
			return false;
		default:
			break;
	}
	if (o->value.kind == JF_FILTER_STRING) {
		if (!yyjson_is_str(v)) {
			return false;
		}
		c = jf_cmp_string(v, &o->value);
	} else {
		/* yyjson_is_num is false for RAW: a big number never matches a range. */
		if (!yyjson_is_num(v)) {
			return false;
		}
		c = jf_cmp_number(v, &o->value);
	}
	switch (o->op) {
		case JF_OP_GT: return c > 0;
		case JF_OP_GE: return c >= 0;
		case JF_OP_LT: return c < 0;
		default:       return c <= 0;
	}
}

/* Every operator of the entry holds for v, the entry's present scalar leaf). */
static zend_always_inline bool jf_entry_holds(const jf_filter_spec *q, yyjson_val *v)
{
	uint8_t k;

	/* The scalar shorthand, the filter, without the operator loop. */
	if (q->op_count == 1 && q->op[0].op == JF_OP_EQ) {
		return jf_scalar_equals(&q->op[0].value, v);
	}
	for (k = 0; k < q->op_count; k++) {
		if (!jf_operator_holds(&q->op[k], v)) {
			return false;
		}
	}
	return true;
}

/*
 * Step 4: the keys of the root object, and of every object reached through an
 * inner path node, in document order, each looked up under its parent node only.
 * The first node met twice is the duplicate whose second occurrence has the lowest byte
 * position. An object is entered only below an inner node, and inner nodes are at most
 * JF_MAX_PATH - 1 levels deep, so the stack below never holds more than JF_MAX_PATH objects,
 * however deep the record nests; each record key is looked up at most once.
 */
static bool jf_duplicate_key(jf_run *run, uint16_t id)
{
	const jf_node *n = &run->plan->nodes[id];

	jf_fail(&run->at, jf_ce_RecordException, JF_R_DUPLICATE_KEY, n->owner, n->owner_length,
		n->named ? "duplicate key \"%s\"" : "duplicate key on the path of \"%s\"", n->owner_escaped);
	return false;
}

static bool jf_walk(jf_run *run, yyjson_val *root)
{
	const jf_plan_data *p = run->plan;
	yyjson_obj_iter iter[JF_MAX_PATH];
	uint16_t parent[JF_MAX_PATH];
	size_t depth = 1;

	memset(run->values, 0, (p->node_count + 1) * sizeof(run->values[0]));
	if (p->flat) {
		/* No inner node, so only root keys can name one: the flat loop, without the stack. */
		yyjson_val *key, *val;
		size_t idx, max;

		yyjson_obj_foreach(root, idx, max, key, val) {
			uint16_t id = jf_root_find(p, unsafe_yyjson_get_str(key), unsafe_yyjson_get_len(key));

			if (id != 0) {
				if (run->values[id] != NULL) {
					return jf_duplicate_key(run, id);
				}
				run->values[id] = val;
			}
		}
		return true;
	}
	yyjson_obj_iter_init(root, &iter[0]);
	parent[0] = 0;
	/* Every key, even once all nodes are found: a later key may still duplicate one. */
	while (depth > 0) {
		yyjson_val *key = yyjson_obj_iter_next(&iter[depth - 1]), *val;
		uint16_t id;

		if (key == NULL) {
			depth--;
			continue;
		}
		id = jf_node_find(p, parent[depth - 1], unsafe_yyjson_get_str(key), unsafe_yyjson_get_len(key));
		if (id == 0) {
			continue;
		}
		if (run->values[id] != NULL) {
			return jf_duplicate_key(run, id);
		}
		val = yyjson_obj_iter_get_val(key);
		run->values[id] = val;
		if (p->nodes[id].inner && yyjson_is_obj(val)) {
			yyjson_obj_iter_init(val, &iter[depth]);
			parent[depth] = id;
			depth++;
		}
	}
	return true;
}

/*
 * The value at a path's leaf, or NULL when a key on it is missing. False when an
 * intermediate is present but not an object: null is not missing.
 */
static bool jf_resolve(const jf_run *run, const jf_path *path, yyjson_val **leaf)
{
	uint8_t k;

	*leaf = NULL;
	for (k = 0; k + 1 < path->depth; k++) {
		yyjson_val *v = run->values[path->node[k]];

		if (v == NULL) {
			return true;
		}
		if (!yyjson_is_obj(v)) {
			return false;
		}
	}
	*leaf = run->values[path->node[path->depth - 1]];
	return true;
}

/* Step 5 of the validation order, on values[] found by jf_walk or jf_scan_record; a recorded failure is false. */
static bool jf_check_fields(jf_run *run)
{
	const jf_plan_data *p = run->plan;
	size_t i;

	for (i = 0; i < p->field_count; i++) {
		const jf_field_spec *f = &p->fields[i];
		yyjson_val *v;

		if (!jf_resolve(run, &f->path, &v)) {
			jf_fail(&run->at, jf_ce_RecordException, JF_R_PATH_NOT_OBJECT, f->name, f->name_length,
				"field \"%s\": a value on its path is not an object", f->escaped);
			return false;
		}
		if (v == NULL) {
			/* Only a missing key takes the default; a present null never does. */
			if (f->default_out != NULL) {
				continue;
			}
			jf_fail(&run->at, jf_ce_RecordException, JF_R_MISSING_FIELD, f->name, f->name_length, "missing field \"%s\"", f->escaped);
			return false;
		}
		if (yyjson_is_null(v)) {
			if (!f->nullable) {
				jf_fail(&run->at, jf_ce_RecordException, JF_R_NULL_FIELD, f->name, f->name_length, "field \"%s\" must not be null", f->escaped);
				return false;
			}
			continue;
		}
		if (!jf_field_accepts(f->type, v, &run->parser.alc)) {
			static const char *const type_names[] = {"int64", "float64", "bool", "string"};

			jf_fail(&run->at, jf_ce_RecordException, JF_R_TYPE_MISMATCH, f->name, f->name_length,
				"field \"%s\": expected %s, got %s", f->escaped, type_names[f->type], jf_kind_of(f->type, v));
			return false;
		}
	}
	return true;
}

/* Steps 4 and 5 for a yyjson document. */
static bool jf_check_keys_and_fields(jf_run *run, yyjson_val *root)
{
	return jf_walk(run, root) && jf_check_fields(run);
}

static bool jf_where_not_scalar(jf_run *run, const jf_filter_spec *q)
{
	jf_fail(&run->at, jf_ce_RecordException, JF_R_WHERE_NOT_SCALAR, q->name, q->name_length,
		"where key \"%s\" must be a JSON scalar", q->escaped);
	return false;
}

/*
 * Steps 6 and 7 for a plan with a path of two or more segments: every where entry, in
 * declared order, resolves its path and has a scalar leaf or none, before any entry decides
 * the match. A missing leaf never matches. Out of line, so the flat walk's code stays as it
 * was before where paths existed.
 */
static zend_never_inline bool jf_check_filters_paths(jf_run *run, bool *matched)
{
	const jf_plan_data *p = run->plan;
	yyjson_val *leaf[JF_MAX_FILTERS];
	size_t i;

	for (i = 0; i < p->filter_count; i++) {
		const jf_filter_spec *q = &p->filters[i];
		yyjson_val *v;

		if (!jf_resolve(run, &q->path, &v)) {
			jf_fail(&run->at, jf_ce_RecordException, JF_R_PATH_NOT_OBJECT, q->name, q->name_length,
				"where key \"%s\": a value on its path is not an object", q->escaped);
			return false;
		}
		if (v != NULL && yyjson_is_ctn(v)) {
			return jf_where_not_scalar(run, q);
		}
		leaf[i] = v;
	}
	*matched = true;
	for (i = 0; i < p->filter_count && *matched; i++) {
		*matched = leaf[i] != NULL && jf_entry_holds(&p->filters[i], leaf[i]);
	}
	return true;
}

/* Steps 6 and 7. A flat plan's paths have one segment: each leaf is a root value. */
static bool jf_check_filters(jf_run *run, bool *matched)
{
	const jf_plan_data *p = run->plan;
	size_t i;

	if (!p->flat) {
		return jf_check_filters_paths(run, matched);
	}
	for (i = 0; i < p->filter_count; i++) {
		yyjson_val *v = run->values[p->filters[i].leaf];

		if (v != NULL && yyjson_is_ctn(v)) {
			return jf_where_not_scalar(run, &p->filters[i]);
		}
	}
	*matched = true;
	for (i = 0; i < p->filter_count && *matched; i++) {
		yyjson_val *v = run->values[p->filters[i].leaf];

		*matched = v != NULL && jf_entry_holds(&p->filters[i], v);
	}
	return true;
}

static bool jf_process_line(jf_run *run, const jf_line *line)
{
	yyjson_doc *doc;
	yyjson_val *root;
	bool ok = false, matched = false;

	if (line->length == 0) {
		if (!run->plan->skip_empty_lines) {
			jf_fail(&run->at, jf_ce_RecordException, JF_R_BLANK_LINE, NULL, 0, "blank line");
			return false;
		}
		return true;
	}

#ifndef JF_DISABLE_SCAN
	/* A flat plan reads only root keys, which the scan finds; any record it does not accept goes to yyjson below. */
	if (run->plan->flat) {
		if (!jf_parser_fit(&run->parser, line->length, &run->at)) {
			return false;
		}
		if (jf_scan_record(run->plan, run->key_filter, line->text, line->length, run->values, run->scanned, &run->parser.alc)) {
			ok = jf_check_fields(run) && jf_check_filters(run, &matched)
				&& (!matched || jf_writer_record(&run->writer, &run->reader, run->values, line->length, &run->parser.alc));
			goto done;
		}
	}
#endif
	doc = jf_parse_record(&run->parser, line->text, line->length, &run->at);
	if (doc == NULL) {
		return false;
	}
	root = yyjson_doc_get_root(doc);
	if (!yyjson_is_obj(root)) {
		jf_fail(&run->at, jf_ce_RecordException, JF_R_NOT_OBJECT, NULL, 0, "root is not an object");
	} else {
		ok = jf_check_keys_and_fields(run, root) && jf_check_filters(run, &matched)
			&& (!matched || jf_writer_record(&run->writer, &run->reader, run->values, line->length, &run->parser.alc));
	}
	yyjson_doc_free(doc);
#ifndef JF_DISABLE_SCAN
done:
#endif
	if (!ok) {
		return false;
	}
	run->matched += matched;
	run->at.record++;
	return true;
}

static void jf_run_loop(jf_run *run)
{
	jf_line_status status;
	jf_line line;

	while ((status = jf_reader_next(&run->reader, &line)) == JF_LINE_OK) {
		if (!jf_process_line(run, &line)) {
			return;
		}
		/* line, record and matched advance by at most one per consumed byte, so offset bounds them. */
		if ((zend_ulong)ZEND_LONG_MAX - (zend_ulong)run->at.offset < line.consumed) {
			jf_fail(&run->at, jf_ce_StreamException, JF_R_COUNTER_OVERFLOW, NULL, 0, "byte counter overflow");
			return;
		}
		run->at.offset += (zend_long)line.consumed;
		run->at.line++;
	}
	run->succeeded = status == JF_LINE_EOF && jf_writer_finish(&run->writer, &run->reader);
}

void jf_stats_new(zval *rv, const jf_progress *p)
{
	zend_object *stats;

	object_init_ex(rv, jf_ce_Stats);
	stats = Z_OBJ_P(rv);
	zend_update_property_long(jf_ce_Stats, stats, ZEND_STRL("recordsRead"), p->read);
	zend_update_property_long(jf_ce_Stats, stats, ZEND_STRL("recordsMatched"), p->matched);
	zend_update_property_long(jf_ce_Stats, stats, ZEND_STRL("recordsEmitted"), p->emitted);
	zend_update_property_long(jf_ce_Stats, stats, ZEND_STRL("bytesRead"), p->bytes_read);
	zend_update_property_long(jf_ce_Stats, stats, ZEND_STRL("bytesWritten"), p->written);
}

/* Counts now: a failing record has not completed, so it is not counted as read. */
static void jf_run_progress(const jf_run *run, jf_progress *p)
{
	p->read = run->at.record - 1;
	p->matched = run->matched;
	p->emitted = run->writer.emitted;
	p->bytes_read = run->at.offset;
	p->written = run->writer.written;
}

static void jf_run_throw(const jf_run *run, const jf_failure *f)
{
	jf_progress p;

	jf_run_progress(run, &p);
	zend_throw_exception_internal(jf_failure_exception(f, &run->at, &p));
}

/*
 * A flushing failure first writes the buffered complete records, with no
 * exception pending and no php_stream_flush, then raises with the progress after
 * that write. If the write fails, its failure leads the chain and the recorded one
 * follows it. Any other failure raises at once and its buffered records are dropped.
 * Nothing recorded means a PHP exception is already pending.
 */
static void jf_run_raise(jf_run *run)
{
	jf_progress p;
	zend_object *ex;

	if (run->at.failure.ce == NULL) {
		return;
	}
	if (EG(exception) != NULL || !(run->at.failure.ce == jf_ce_RecordException
			|| run->at.failure.reason == JF_R_READ_FAILED || run->at.failure.reason == JF_R_NO_DATA)) {
		jf_run_throw(run, &run->at.failure);
		return;
	}
	/* Kept in run, not on this stack, so the catch path frees it after a bailout in the write. */
	run->recorded = run->at.failure;
	memset(&run->at.failure, 0, sizeof(run->at.failure));
	if (jf_writer_drain(&run->writer, &run->reader)) {
		jf_run_throw(run, &run->recorded);
		return;
	}
	if (run->at.failure.ce != NULL) {
		/* The engine makes the exception pending at a throw the previous of the new one. */
		jf_run_throw(run, &run->recorded);
		jf_run_throw(run, &run->at.failure);
		return;
	}
	/* A wrapper or filter threw during the write: its exception propagates, the recorded one ends its chain. */
	jf_run_progress(run, &p);
	ex = jf_failure_exception(&run->recorded, &run->at, &p);
	if (zend_is_unwind_exit(EG(exception)) || zend_is_graceful_exit(EG(exception))) {
		OBJ_RELEASE(ex);
	} else {
		zend_exception_set_previous(EG(exception), ex);
	}
}

/*
 * The setjmp of zend_try lives here, apart from the caller that owns run: a local
 * changed between setjmp and longjmp is indeterminate after it (C11 7.13.2.1), and
 * the pointer is the only local of this function, never changed.
 */
static void jf_run_guarded(jf_run *run)
{
	zend_try {
		jf_run_loop(run);
		if (!run->succeeded) {
			jf_run_raise(run);
		}
	} zend_catch {
		jf_run_release(run);
		zend_bailout();
	} zend_end_try();
}

ZEND_METHOD(JsonFuse_Runner, process)
{
	zval *input, *output;
	zend_object *plan_obj;
	const jf_plan_object *plan;
	php_stream *in, *out;
	jf_progress progress;
	jf_run run;

	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_RESOURCE(input)
		Z_PARAM_RESOURCE(output)
		Z_PARAM_OBJ_OF_CLASS(plan_obj, jf_ce_Plan)
	ZEND_PARSE_PARAMETERS_END();

	php_stream_from_zval(in, input);
	php_stream_from_zval(out, output);

	plan = jf_plan_from_obj(plan_obj);
	if (!plan->compiled) {
		jf_throw_before_run(jf_ce_JsonFuseException, JF_R_INTERNAL_ERROR, "Plan is not initialized");
		RETURN_THROWS();
	}
	if (Z_RES_P(input) == Z_RES_P(output)) {
		jf_throw_before_run(jf_ce_StreamException, JF_R_SAME_STREAM, "input and output must be different streams");
		RETURN_THROWS();
	}
	/*
	 * Limit: NO_FCLOSE doubles as the in-use marker, so anything else php-src
	 * flags (directory handles, SPL, filter and 8.5 wrapper callbacks) is refused
	 * too. A per-request registry of held streams would refuse only real runs.
	 */
	if (((in->flags | out->flags) & PHP_STREAM_FLAG_NO_FCLOSE) != 0) {
		jf_throw_before_run(jf_ce_StreamException, JF_R_STREAM_IN_USE,
			"a stream is already in use by another Runner::process call or cannot be closed from PHP");
		RETURN_THROWS();
	}

	/* Set up before the guarded run: its catch path releases this run. */
	memset(&run, 0, sizeof(run));
	run.at.line = 1;
	run.at.record = 1;
	run.plan = &plan->data;
	run.reader.in = Z_RES_P(input);
	run.reader.out = Z_RES_P(output);
	run.reader.max_record = plan->data.max_record_bytes;
	run.reader.at = &run.at;
#ifndef JF_DISABLE_SCAN
	if (plan->data.flat) {
		for (size_t i = 1; i <= plan->data.node_count; i++) {
			uint32_t bit = jf_key_filter_bit(plan->data.nodes[i].key, plan->data.nodes[i].key_length);

			run.key_filter[bit >> 6] |= (uint64_t)1 << (bit & 63);
		}
	}
#endif
	jf_parser_init(&run.parser, plan->data.max_record_bytes);
	jf_writer_init(&run.writer, &plan->data);

	run.fiber_switches = JF_G(fiber_switches);
	in->flags |= PHP_STREAM_FLAG_NO_FCLOSE;
	out->flags |= PHP_STREAM_FLAG_NO_FCLOSE;

	jf_run_guarded(&run);
	jf_run_release(&run);
	if (!run.succeeded) {
		RETURN_THROWS();
	}
	jf_run_progress(&run, &progress);
	jf_stats_new(return_value, &progress);
}
