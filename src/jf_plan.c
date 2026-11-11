#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_exceptions.h"

#include "yyjson.h"

#include "jf_errors.h"
#include "jf_plan.h"

#define JF_DEFAULT_RECORD_BYTES  ((size_t)1024 * 1024)

zend_class_entry *jf_ce_Plan;
static zend_object_handlers jf_plan_handlers;

/* SPEC.md section 6 output escape table. NULL means the byte is written raw. */
static const char *const jf_escape_table[256] = {
	[0x00] = "\\u0000", [0x01] = "\\u0001", [0x02] = "\\u0002", [0x03] = "\\u0003",
	[0x04] = "\\u0004", [0x05] = "\\u0005", [0x06] = "\\u0006", [0x07] = "\\u0007",
	[0x08] = "\\b",     [0x09] = "\\t",     [0x0a] = "\\n",     [0x0b] = "\\u000b",
	[0x0c] = "\\f",     [0x0d] = "\\r",     [0x0e] = "\\u000e", [0x0f] = "\\u000f",
	[0x10] = "\\u0010", [0x11] = "\\u0011", [0x12] = "\\u0012", [0x13] = "\\u0013",
	[0x14] = "\\u0014", [0x15] = "\\u0015", [0x16] = "\\u0016", [0x17] = "\\u0017",
	[0x18] = "\\u0018", [0x19] = "\\u0019", [0x1a] = "\\u001a", [0x1b] = "\\u001b",
	[0x1c] = "\\u001c", [0x1d] = "\\u001d", [0x1e] = "\\u001e", [0x1f] = "\\u001f",
	['"'] = "\\\"", ['\\'] = "\\\\",
};

static zend_object *jf_plan_create_object(zend_class_entry *ce)
{
	jf_plan_object *o = zend_object_alloc(sizeof(jf_plan_object), ce);

	memset(&o->data, 0, sizeof(o->data));
	o->compiled = false;
	zend_object_std_init(&o->std, ce);
	object_properties_init(&o->std, ce);
	o->std.handlers = &jf_plan_handlers;
	return &o->std;
}

static void jf_path_free(jf_path *path)
{
	uint8_t k;

	for (k = 0; k < path->depth; k++) {
		efree(path->segment[k]);
	}
}

/* An operator counted in op_count owns its string and, for in, its members counted so far. */
static void jf_operator_free(jf_operator *o)
{
	size_t k;

	if (o->value.str != NULL) {
		efree(o->value.str);
	}
	for (k = 0; k < o->member_count; k++) {
		if (o->members[k].str != NULL) {
			efree(o->members[k].str);
		}
	}
	if (o->members != NULL) {
		efree(o->members);
	}
}

/* Only entries counted in field_count/filter_count own their allocations. */
static void jf_plan_free_obj(zend_object *obj)
{
	jf_plan_data *d = &jf_plan_from_obj(obj)->data;
	size_t i;

	for (i = 0; i < d->field_count; i++) {
		efree(d->fields[i].name);
		if (d->fields[i].escaped != NULL) {
			efree(d->fields[i].escaped);
		}
		if (d->fields[i].prefix != NULL) {
			efree(d->fields[i].prefix);
		}
		if (d->fields[i].default_out != NULL) {
			efree(d->fields[i].default_out);
		}
		jf_path_free(&d->fields[i].path);
	}
	if (d->fields != NULL) {
		efree(d->fields);
	}
	for (i = 0; i < d->filter_count; i++) {
		jf_filter_spec *q = &d->filters[i];
		uint8_t k;

		efree(q->name);
		if (q->escaped != NULL) {
			efree(q->escaped);
		}
		for (k = 0; k < q->op_count; k++) {
			jf_operator_free(&q->op[k]);
		}
		jf_path_free(&q->path);
	}
	if (d->filters != NULL) {
		efree(d->filters);
	}
	if (d->nodes != NULL) {
		efree(d->nodes);
	}
	if (d->slots != NULL && d->slots != d->root_slots) {
		efree(d->slots);
	}
	zend_object_std_dtor(obj);
}

void jf_plan_minit(void)
{
	jf_ce_Plan->create_object = jf_plan_create_object;
	memcpy(&jf_plan_handlers, zend_get_std_object_handlers(), sizeof(zend_object_handlers));
	jf_plan_handlers.offset = offsetof(jf_plan_object, std);
	jf_plan_handlers.free_obj = jf_plan_free_obj;
	jf_plan_handlers.clone_obj = NULL;
}

static bool jf_utf8_valid(const unsigned char *s, size_t n)
{
	size_t i = 0;

	while (i < n) {
		unsigned char c = s[i++], lo = 0x80, hi = 0xBF;
		size_t more;

		if (c < 0x80) {
			continue;
		} else if (c >= 0xC2 && c <= 0xDF) {
			more = 1;
		} else if (c >= 0xE0 && c <= 0xEF) {
			more = 2;
			if (c == 0xE0) {
				lo = 0xA0;
			} else if (c == 0xED) {
				hi = 0x9F;
			}
		} else if (c >= 0xF0 && c <= 0xF4) {
			more = 3;
			if (c == 0xF0) {
				lo = 0x90;
			} else if (c == 0xF4) {
				hi = 0x8F;
			}
		} else {
			return false;
		}
		if (n - i < more || s[i] < lo || s[i] > hi) {
			return false;
		}
		for (i++; --more > 0; i++) {
			if ((s[i] & 0xC0) != 0x80) {
				return false;
			}
		}
	}
	return true;
}

/* field is the entry's own name in raw bytes, or NULL; messages never echo plan values. */
static bool jf_plan_fail(jf_reason code, const char *option, const char *field, size_t field_length, const char *reason)
{
	char *message;

	spprintf(&message, 0, "Plan option '%s': %s", option, reason);
	jf_throw_plan(code, option, field, field_length, message);
	efree(message);
	return false;
}

/* A hash key as owned bytes; integer keys become their decimal string. */
static bool jf_key_dup(const zend_string *key, zend_ulong idx, char **name, size_t *name_length)
{
	char buf[MAX_LENGTH_OF_LONG + 1];
	const char *s = buf;
	size_t n;

	if (key != NULL) {
		s = ZSTR_VAL(key);
		n = ZSTR_LEN(key);
		if (n > JF_MAX_NAME_BYTES || !jf_utf8_valid((const unsigned char *)s, n)) {
			return false;
		}
	} else {
		n = (size_t)snprintf(buf, sizeof(buf), ZEND_LONG_FMT, (zend_long)idx);
	}
	*name = estrndup(s, n);
	*name_length = n;
	return true;
}

static bool jf_parse_type(const zval *z, jf_field_spec *f)
{
	const char *s;
	size_t n;

	if (Z_TYPE_P(z) != IS_STRING) {
		return false;
	}
	s = Z_STRVAL_P(z);
	n = Z_STRLEN_P(z);
	f->nullable = n > 0 && s[n - 1] == '?';
	if (f->nullable) {
		n--;
	}
	if (n == 5 && memcmp(s, "int64", 5) == 0) {
		f->type = JF_INT64;
	} else if (n == 7 && memcmp(s, "float64", 7) == 0) {
		f->type = JF_FLOAT64;
	} else if (n == 4 && memcmp(s, "bool", 4) == 0) {
		f->type = JF_BOOL;
	} else if (n == 6 && memcmp(s, "string", 6) == 0) {
		f->type = JF_STRING;
	} else {
		return false;
	}
	return true;
}

/*
 * Binary64 holds the int exactly. (double)i rounds, and PHP_INT_MAX rounds up
 * to 2^63, whose conversion back is undefined (C11 6.3.1.4), so that case is tested first.
 * Every double from an int is at least -2^63, which converts back exactly.
 */
static bool jf_long_is_binary64(zend_long i)
{
	double d = (double)i;

	return d < 0x1p63 && (zend_long)d == i;
}

/*
 * A default of the declared type with no conversion, null only for a nullable
 * type. Its output is built here, so a run never reads the user's zval, and its exact
 * length joins the writer's per-record reservation through default_total.
 */
static bool jf_compile_default(jf_plan_data *d, jf_field_spec *f, const zval *v)
{
	char number[40];            /* yyjson_write_number's minimum buffer */
	const char *text = number;
	size_t length = 0;
	yyjson_val num;

	if (Z_TYPE_P(v) == IS_NULL && f->nullable) {
		text = "null";
		length = 4;
	} else if (f->type == JF_INT64 && Z_TYPE_P(v) == IS_LONG) {
		unsafe_yyjson_set_sint(&num, Z_LVAL_P(v));
		length = (size_t)(yyjson_write_number(&num, number) - number);
	} else if (f->type == JF_FLOAT64 && ((Z_TYPE_P(v) == IS_DOUBLE && zend_finite(Z_DVAL_P(v)))
			|| (Z_TYPE_P(v) == IS_LONG && jf_long_is_binary64(Z_LVAL_P(v))))) {
		/* An int default is stored as a REAL, so it is written in float form: 1 gives 1.0. */
		unsafe_yyjson_set_real(&num, Z_TYPE_P(v) == IS_DOUBLE ? Z_DVAL_P(v) : (double)Z_LVAL_P(v));
		length = (size_t)(yyjson_write_number(&num, number) - number);
	} else if (f->type == JF_BOOL && (Z_TYPE_P(v) == IS_TRUE || Z_TYPE_P(v) == IS_FALSE)) {
		text = Z_TYPE_P(v) == IS_TRUE ? "true" : "false";
		length = strlen(text);
	} else if (f->type == JF_STRING && Z_TYPE_P(v) == IS_STRING && Z_STRLEN_P(v) <= JF_MAX_NAME_BYTES
			&& jf_utf8_valid((const unsigned char *)Z_STRVAL_P(v), Z_STRLEN_P(v))) {
		/* Counted byte by byte through the escape table: at most 2 + 6 * 1024. */
		length = jf_escaped_length(Z_STRVAL_P(v), Z_STRLEN_P(v)) + 2;
		f->default_out = emalloc(length);
		f->default_out[0] = '"';
		jf_escape_bytes(f->default_out + 1, Z_STRVAL_P(v), Z_STRLEN_P(v));
		f->default_out[length - 1] = '"';
	} else {
		return jf_plan_fail(JF_R_INVALID_VALUE, "fields", f->name, f->name_length,
			"a default must have the declared type, and may be null only for a nullable type");
	}
	if (f->default_out == NULL) {
		f->default_out = estrndup(text, length);
	}
	f->default_length = length;
	d->default_total += length;
	return true;
}

static bool jf_invalid_type(const jf_field_spec *f)
{
	return jf_plan_fail(JF_R_INVALID_TYPE, "fields", f->name, f->name_length,
		"each type must be int64, float64, bool or string, optionally with a trailing ?");
}

/*
 * A list of 1 to 8 strings, each literal key bytes of valid UTF-8 and at
 * most 1024 bytes, copied into the plan. The shape is checked first, then each segment in
 * order: one that is not a string is invalid_path, so ['items', 0] is never an index.
 */
static bool jf_parse_path(jf_path *path, zval *z, const char *option, const char *name, size_t name_length)
{
	zval *seg;

	if (Z_TYPE_P(z) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(z)) == 0
			|| zend_hash_num_elements(Z_ARRVAL_P(z)) > JF_MAX_PATH || !zend_array_is_list(Z_ARRVAL_P(z))) {
		return jf_plan_fail(JF_R_INVALID_PATH, option, name, name_length, "a path must be a list of 1 to 8 strings");
	}
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(z), seg) {
		ZVAL_DEREF(seg);
		if (Z_TYPE_P(seg) != IS_STRING) {
			return jf_plan_fail(JF_R_INVALID_PATH, option, name, name_length, "a path must be a list of 1 to 8 strings");
		}
		if (Z_STRLEN_P(seg) > JF_MAX_NAME_BYTES || !jf_utf8_valid((const unsigned char *)Z_STRVAL_P(seg), Z_STRLEN_P(seg))) {
			return jf_plan_fail(JF_R_INVALID_NAME, option, NULL, 0, "path segments must be valid UTF-8 of at most 1024 bytes");
		}
		path->segment[path->depth] = estrndup(Z_STRVAL_P(seg), Z_STRLEN_P(seg));
		path->length[path->depth] = Z_STRLEN_P(seg);
		path->depth++;
	} ZEND_HASH_FOREACH_END();
	return true;
}

/* A missing path means [name]. */
static void jf_path_of_name(jf_path *path, const char *name, size_t name_length)
{
	path->segment[0] = estrndup(name, name_length);
	path->length[0] = name_length;
	path->depth = 1;
}

/*
 * The array form of a field spec. Every key is checked before any value, so an unknown key
 * is reported even when the type is missing too; then the type, the path and the default.
 */
static bool jf_parse_field_spec(jf_plan_data *d, jf_field_spec *f, HashTable *spec)
{
	zval *type = NULL, *path = NULL, *dflt = NULL, *val;
	zend_string *key;

	ZEND_HASH_FOREACH_STR_KEY_VAL(spec, key, val) {
		ZVAL_DEREF(val);
		if (key != NULL && zend_string_equals_literal(key, "type")) {
			type = val;
		} else if (key != NULL && zend_string_equals_literal(key, "path")) {
			path = val;
		} else if (key != NULL && zend_string_equals_literal(key, "default")) {
			dflt = val;
		} else {
			return jf_plan_fail(JF_R_UNKNOWN_OPTION, "fields", f->name, f->name_length,
				"a field spec takes only the keys type, path and default");
		}
	} ZEND_HASH_FOREACH_END();
	if (type == NULL || !jf_parse_type(type, f)) {
		return jf_invalid_type(f);
	}
	if (path != NULL && !jf_parse_path(&f->path, path, "fields", f->name, f->name_length)) {
		return false;
	}
	/* A 'default' key counts even when it holds null. */
	return dflt == NULL || jf_compile_default(d, f, dflt);
}

static bool jf_compile_fields(jf_plan_data *d, zval *fields)
{
	zend_string *key;
	zend_ulong idx;
	zval *val;

	if (Z_TYPE_P(fields) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(fields)) == 0) {
		return jf_plan_fail(JF_R_INVALID_OPTION, "fields", NULL, 0, "must be a non-empty array");
	}
	if (zend_hash_num_elements(Z_ARRVAL_P(fields)) > JF_MAX_FIELDS) {
		return jf_plan_fail(JF_R_TOO_MANY, "fields", NULL, 0, "has too many entries (maximum 128)");
	}
	d->fields = ecalloc(zend_hash_num_elements(Z_ARRVAL_P(fields)), sizeof(jf_field_spec));

	ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(fields), idx, key, val) {
		jf_field_spec *f = &d->fields[d->field_count];

		ZVAL_DEREF(val);
		if (!jf_key_dup(key, idx, &f->name, &f->name_length)) {
			return jf_plan_fail(JF_R_INVALID_NAME, "fields", NULL, 0, "field names must be valid UTF-8 of at most 1024 bytes");
		}
		d->field_count++;
		if (Z_TYPE_P(val) == IS_ARRAY) {
			if (!jf_parse_field_spec(d, f, Z_ARRVAL_P(val))) {
				return false;
			}
		} else if (!jf_parse_type(val, f)) {
			return jf_invalid_type(f);
		}
		if (f->path.depth == 0) {
			jf_path_of_name(&f->path, f->name, f->name_length);
		}
	} ZEND_HASH_FOREACH_END();
	return true;
}

/*
 * A scalar for '=' and in members: NULL and stored, or the reason it is not
 * one. A string is copied only once it is known to be valid.
 */
static const char *jf_parse_scalar(jf_scalar *s, const zval *v)
{
	switch (Z_TYPE_P(v)) {
		case IS_STRING:
			if (Z_STRLEN_P(v) > JF_MAX_NAME_BYTES || !jf_utf8_valid((const unsigned char *)Z_STRVAL_P(v), Z_STRLEN_P(v))) {
				return "string values must be valid UTF-8 of at most 1024 bytes";
			}
			s->kind = JF_FILTER_STRING;
			s->str = estrndup(Z_STRVAL_P(v), Z_STRLEN_P(v));
			s->str_length = Z_STRLEN_P(v);
			return NULL;
		case IS_LONG:
			s->kind = JF_FILTER_INT;
			s->i = Z_LVAL_P(v);
			return NULL;
		case IS_DOUBLE:
			if (!zend_finite(Z_DVAL_P(v))) {
				return "float values must be finite";
			}
			s->kind = JF_FILTER_FLOAT;
			s->d = Z_DVAL_P(v);
			return NULL;
		case IS_TRUE:
		case IS_FALSE:
			s->kind = JF_FILTER_BOOL;
			s->b = Z_TYPE_P(v) == IS_TRUE;
			return NULL;
		case IS_NULL:
			s->kind = JF_FILTER_NULL;
			return NULL;
		default:
			return "values must be string, int, bool, null or finite float";
	}
}

/* In takes a list of 1 to 256 scalars; members are copied in order, each owned once counted. */
static bool jf_parse_members(jf_filter_spec *q, jf_operator *o, const zval *v)
{
	zval *m;

	if (Z_TYPE_P(v) != IS_ARRAY || zend_hash_num_elements(Z_ARRVAL_P(v)) == 0 || !zend_array_is_list(Z_ARRVAL_P(v))) {
		return jf_plan_fail(JF_R_INVALID_VALUE, "where", q->name, q->name_length, "in takes a non-empty list of scalars");
	}
	if (zend_hash_num_elements(Z_ARRVAL_P(v)) > JF_MAX_MEMBERS) {
		return jf_plan_fail(JF_R_TOO_MANY, "where", NULL, 0, "in has too many members (maximum 256)");
	}
	o->members = ecalloc(zend_hash_num_elements(Z_ARRVAL_P(v)), sizeof(jf_scalar));
	ZEND_HASH_FOREACH_VAL(Z_ARRVAL_P(v), m) {
		const char *bad;

		ZVAL_DEREF(m);
		bad = jf_parse_scalar(&o->members[o->member_count], m);
		if (bad != NULL) {
			return jf_plan_fail(JF_R_INVALID_VALUE, "where", q->name, q->name_length, bad);
		}
		o->member_count++;
	} ZEND_HASH_FOREACH_END();
	return true;
}

/* A range bound: an int, a finite float, or a string of valid UTF-8 of at most 1024 bytes. */
static const char *jf_parse_bound(jf_scalar *s, const zval *v)
{
	if (Z_TYPE_P(v) != IS_LONG && Z_TYPE_P(v) != IS_DOUBLE && Z_TYPE_P(v) != IS_STRING) {
		return "range operands must be int, finite float or string";
	}
	return jf_parse_scalar(s, v);
}

static bool jf_op_of_key(const zend_string *key, jf_op *op)
{
	static const struct { const char *name; size_t length; jf_op op; } ops[] = {
		{"=", 1, JF_OP_EQ}, {">", 1, JF_OP_GT}, {">=", 2, JF_OP_GE}, {"<", 1, JF_OP_LT}, {"<=", 2, JF_OP_LE}, {"in", 2, JF_OP_IN},
	};
	size_t i;

	for (i = 0; i < sizeof(ops) / sizeof(ops[0]); i++) {
		if (ZSTR_LEN(key) == ops[i].length && memcmp(ZSTR_VAL(key), ops[i].name, ops[i].length) == 0) {
			*op = ops[i].op;
			return true;
		}
	}
	return false;
}

/*
 * The array form of a where entry. Every key is checked before any value,
 * as for field specs; then the path, then that an operator is present, then each operand in
 * declared order, then the one-kind rule: range operands of both kinds (number and string)
 * are invalid_value; '=' and in are not part of that rule.
 */
static bool jf_parse_where_entry(jf_filter_spec *q, HashTable *spec)
{
	zval *path = NULL, *operand[JF_MAX_OPS], *val;
	jf_op op[JF_MAX_OPS];
	jf_filter_kind range = JF_FILTER_NULL;   /* no range operand yet */
	bool mixed = false;
	uint8_t n = 0, k;
	zend_string *key;
	jf_op this_op;

	ZEND_HASH_FOREACH_STR_KEY_VAL(spec, key, val) {
		ZVAL_DEREF(val);
		if (key != NULL && zend_string_equals_literal(key, "path")) {
			path = val;
		} else if (key != NULL && jf_op_of_key(key, &this_op)) {
			/* Hash keys are unique, so each of the six operators appears at most once. */
			ZEND_ASSERT(n < JF_MAX_OPS);
			op[n] = this_op;
			operand[n++] = val;
		} else {
			return jf_plan_fail(JF_R_UNKNOWN_OPTION, "where", q->name, q->name_length,
				"a where entry takes only the keys path, =, >, >=, <, <= and in");
		}
	} ZEND_HASH_FOREACH_END();
	if (path != NULL && !jf_parse_path(&q->path, path, "where", q->name, q->name_length)) {
		return false;
	}
	if (n == 0) {
		return jf_plan_fail(JF_R_INVALID_VALUE, "where", q->name, q->name_length, "a where entry needs at least one operator");
	}
	for (k = 0; k < n; k++) {
		jf_operator *o = &q->op[q->op_count++];
		const char *bad = NULL;

		o->op = op[k];
		if (op[k] == JF_OP_IN) {
			if (!jf_parse_members(q, o, operand[k])) {
				return false;
			}
			continue;
		}
		bad = op[k] == JF_OP_EQ ? jf_parse_scalar(&o->value, operand[k]) : jf_parse_bound(&o->value, operand[k]);
		if (bad != NULL) {
			return jf_plan_fail(JF_R_INVALID_VALUE, "where", q->name, q->name_length, bad);
		}
		if (op[k] != JF_OP_EQ) {
			jf_filter_kind kind = o->value.kind == JF_FILTER_STRING ? JF_FILTER_STRING : JF_FILTER_INT;

			if (range != JF_FILTER_NULL && range != kind) {
				mixed = true;
			}
			range = kind;
		}
	}
	/* Last, after every operand: a later in count or bad operand wins. */
	if (mixed) {
		return jf_plan_fail(JF_R_INVALID_VALUE, "where", q->name, q->name_length,
			"the range operators of one entry must all take numbers or all take strings");
	}
	return true;
}

static bool jf_compile_filters(jf_plan_data *d, zval *where)
{
	zend_string *key;
	zend_ulong idx;
	zval *val;

	if (Z_TYPE_P(where) != IS_ARRAY) {
		return jf_plan_fail(JF_R_INVALID_OPTION, "where", NULL, 0, "must be an array");
	}
	if (zend_hash_num_elements(Z_ARRVAL_P(where)) > JF_MAX_FILTERS) {
		return jf_plan_fail(JF_R_TOO_MANY, "where", NULL, 0, "has too many keys (maximum 16)");
	}
	if (zend_hash_num_elements(Z_ARRVAL_P(where)) == 0) {
		return true;
	}
	d->filters = ecalloc(zend_hash_num_elements(Z_ARRVAL_P(where)), sizeof(jf_filter_spec));

	ZEND_HASH_FOREACH_KEY_VAL(Z_ARRVAL_P(where), idx, key, val) {
		jf_filter_spec *f = &d->filters[d->filter_count];

		ZVAL_DEREF(val);
		if (!jf_key_dup(key, idx, &f->name, &f->name_length)) {
			return jf_plan_fail(JF_R_INVALID_NAME, "where", NULL, 0, "keys must be valid UTF-8 of at most 1024 bytes");
		}
		d->filter_count++;
		if (Z_TYPE_P(val) == IS_ARRAY) {
			if (!jf_parse_where_entry(f, Z_ARRVAL_P(val))) {
				return false;
			}
		} else {
			/* A scalar entry is ['=' => value]. */
			const char *bad = jf_parse_scalar(&f->op[0].value, val);

			if (bad != NULL) {
				return jf_plan_fail(JF_R_INVALID_VALUE, "where", f->name, f->name_length, bad);
			}
			f->op[0].op = JF_OP_EQ;
			f->op_count = 1;
		}
		if (f->path.depth == 0) {
			jf_path_of_name(&f->path, f->name, f->name_length);
		}
	} ZEND_HASH_FOREACH_END();
	return true;
}

static bool jf_compile_options(jf_plan_data *d, zval *output, zval *max_record_bytes, zval *skip_empty_lines)
{
	if (output != NULL && !(Z_TYPE_P(output) == IS_STRING && zend_string_equals_literal(Z_STR_P(output), "ndjson"))) {
		return jf_plan_fail(JF_R_INVALID_OPTION, "output", NULL, 0, "only ndjson is supported");
	}

	d->max_record_bytes = JF_DEFAULT_RECORD_BYTES;
	if (max_record_bytes != NULL) {
		if (Z_TYPE_P(max_record_bytes) != IS_LONG
				|| Z_LVAL_P(max_record_bytes) < 1 || Z_LVAL_P(max_record_bytes) > JF_MAX_RECORD_BYTES) {
			return jf_plan_fail(JF_R_INVALID_OPTION, "maxRecordBytes", NULL, 0, "must be an int from 1 to 67108864");
		}
		d->max_record_bytes = (size_t)Z_LVAL_P(max_record_bytes);
	}

	if (skip_empty_lines != NULL) {
		if (Z_TYPE_P(skip_empty_lines) != IS_TRUE && Z_TYPE_P(skip_empty_lines) != IS_FALSE) {
			return jf_plan_fail(JF_R_INVALID_OPTION, "skipEmptyLines", NULL, 0, "must be a bool");
		}
		d->skip_empty_lines = Z_TYPE_P(skip_empty_lines) == IS_TRUE;
	}
	return true;
}

/*
 * Limit: one table lookup per byte. Scanning for runs of plain bytes would be
 * faster on long strings with few escapes; not measured separately.
 */
char *jf_escape_bytes(char *p, const char *s, size_t n)
{
	size_t i;

	for (i = 0; i < n; i++) {
		const char *e = jf_escape_table[(unsigned char)s[i]];

		if (e != NULL) {
			size_t len = strlen(e);

			memcpy(p, e, len);
			p += len;
		} else {
			*p++ = s[i];
		}
	}
	return p;
}

size_t jf_escaped_length(const char *s, size_t n)
{
	size_t i, length = 0;

	for (i = 0; i < n; i++) {
		const char *e = jf_escape_table[(unsigned char)s[i]];

		length += e != NULL ? strlen(e) : 1;
	}
	return length;
}

/* Bytes escaped for a message: at most 6 per input byte, and NUL becomes text. */
static char *jf_escape_name(const char *name, size_t length)
{
	char *escaped = safe_emalloc(length, 6, 1);

	*jf_escape_bytes(escaped, name, length) = '\0';
	return escaped;
}

/*
 * A power of two at least three times the node count, so the load factor stays at most a third
 * and never under the flat table's 512 slots: in a table of 16, a record key that names no node
 * meets an occupied slot one time in five, and on wide records those probes cost more than
 * the 1 KiB the floor adds.
 */
static uint32_t jf_table_size(size_t nodes)
{
	uint32_t size = JF_ROOT_SLOTS;

	while (size < 3 * nodes) {
		size <<= 1;
	}
	return size;
}

/* An empty table for nodes: root_slots when that is its size, so a plan object never moves it. */
static void jf_table_alloc(jf_plan_data *d, size_t nodes)
{
	d->slot_mask = jf_table_size(nodes) - 1;
	if (d->slot_mask + 1 == JF_ROOT_SLOTS) {
		memset(d->root_slots, 0, sizeof(d->root_slots));
		d->slots = d->root_slots;
	} else {
		d->slots = ecalloc((size_t)d->slot_mask + 1, sizeof(uint16_t));
	}
}

static void jf_slot_insert(jf_plan_data *d, uint16_t id)
{
	const jf_node *n = &d->nodes[id];
	uint32_t i;

	for (i = jf_key_hash(n->parent, n->key, n->key_length) & d->slot_mask; d->slots[i] != 0; i = (i + 1) & d->slot_mask) {
	}
	d->slots[i] = id;
}

/* The node of key under parent; a new one is owned, for getField(), by the entry that creates it. */
static uint16_t jf_node_intern(jf_plan_data *d, uint16_t parent, const char *key, size_t length,
	const char *owner, size_t owner_length, const char *owner_escaped)
{
	uint16_t id = jf_node_find(d, parent, key, length);
	jf_node *n;

	if (id != 0) {
		return id;
	}
	id = (uint16_t)++d->node_count;
	n = &d->nodes[id];
	n->key = key;
	n->key_length = length;
	n->parent = parent;
	n->owner = owner;
	n->owner_length = owner_length;
	n->owner_escaped = owner_escaped;
	n->named = parent == 0 && length == owner_length && memcmp(key, owner, length) == 0;
	jf_slot_insert(d, id);
	return id;
}

/*
 * Adds an entry's path to the node tree, after every earlier entry, fields before
 * where. A conflict is reported for this, the later, entry: a path that is a strict prefix of
 * another, or has one as its prefix, in either section, is invalid_path; a path equal to an
 * earlier one of the same section is duplicate_name. A fields path and a where path may be
 * equal. So no leaf is inner and no two fields share a leaf: the projected values are
 * disjoint tokens of the record, which the writer's reservation relies on.
 */
static bool jf_intern_path(jf_plan_data *d, jf_path *path, bool field, const char *name, size_t name_length, const char *escaped)
{
	const char *option = field ? "fields" : "where";
	uint16_t parent = 0;
	uint8_t k;

	for (k = 0; k < path->depth; k++) {
		bool leaf = k + 1 == path->depth;
		uint16_t id = jf_node_intern(d, parent, path->segment[k], path->length[k], name, name_length, escaped);
		jf_node *n = &d->nodes[id];

		if (leaf ? n->inner : (n->field_leaf || n->filter_leaf)) {
			return jf_plan_fail(JF_R_INVALID_PATH, option, name, name_length,
				"a path must not be a strict prefix of another plan path");
		}
		if (leaf && (field ? n->field_leaf : n->filter_leaf)) {
			return jf_plan_fail(JF_R_DUPLICATE_NAME, option, name, name_length,
				field ? "two fields must not have the same path" : "two where keys must not have the same path");
		}
		if (!leaf) {
			n->inner = true;
		} else if (field) {
			n->field_leaf = true;
		} else {
			n->filter_leaf = true;
		}
		path->node[k] = id;
		parent = id;
	}
	return true;
}

/*
 * Names, then paths, entry by entry in declared order. A PHP array can hold both the string
 * key "1" and the int key 1, which name the same key: two fields so named would write one
 * output key twice, so equal names are refused as well as equal paths. The node table is
 * built from an upper bound of the node count, then sized again from the real count.
 */
static bool jf_compile_paths(jf_plan_data *d)
{
	size_t bound = 0, i, j;
	uint16_t id;

	for (i = 0; i < d->field_count; i++) {
		bound += d->fields[i].path.depth;
	}
	for (i = 0; i < d->filter_count; i++) {
		bound += d->filters[i].path.depth;
	}
	d->nodes = ecalloc(bound + 1, sizeof(jf_node));
	jf_table_alloc(d, bound);

	for (i = 0; i < d->field_count; i++) {
		jf_field_spec *f = &d->fields[i];

		f->escaped = jf_escape_name(f->name, f->name_length);
		for (j = 0; j < i; j++) {
			if (d->fields[j].name_length == f->name_length && memcmp(d->fields[j].name, f->name, f->name_length) == 0) {
				return jf_plan_fail(JF_R_DUPLICATE_NAME, "fields", f->name, f->name_length, "field names must be unique");
			}
		}
		if (!jf_intern_path(d, &f->path, true, f->name, f->name_length, f->escaped)) {
			return false;
		}
		f->leaf = f->path.node[f->path.depth - 1];
		/* The escaped name has no NUL: the table turns one into text. */
		f->prefix_length = spprintf(&f->prefix, 0, ",\"%s\":", f->escaped);
		d->prefix_total += f->prefix_length;
	}
	for (i = 0; i < d->filter_count; i++) {
		jf_filter_spec *q = &d->filters[i];

		q->escaped = jf_escape_name(q->name, q->name_length);
		for (j = 0; j < i; j++) {
			if (d->filters[j].name_length == q->name_length && memcmp(d->filters[j].name, q->name, q->name_length) == 0) {
				return jf_plan_fail(JF_R_DUPLICATE_NAME, "where", q->name, q->name_length, "keys must be unique");
			}
		}
		if (!jf_intern_path(d, &q->path, false, q->name, q->name_length, q->escaped)) {
			return false;
		}
		q->leaf = q->path.node[q->path.depth - 1];
	}

	if (d->slots != d->root_slots) {
		efree(d->slots);
	}
	jf_table_alloc(d, d->node_count);
	d->flat = true;
	for (id = 1; id <= d->node_count; id++) {
		jf_slot_insert(d, id);
		d->flat = d->flat && !d->nodes[id].inner;
	}
	/* jf_root_find reads root_slots: a flat plan has at most JF_MAX_FIELDS + JF_MAX_FILTERS nodes. */
	ZEND_ASSERT(!d->flat || d->slots == d->root_slots);
	return true;
}

static bool jf_plan_build(jf_plan_data *d, HashTable *spec)
{
	zval *fields = NULL, *where = NULL, *output = NULL, *max = NULL, *skip = NULL;
	zend_string *key;
	zval *val;

	ZEND_HASH_FOREACH_STR_KEY_VAL(spec, key, val) {
		ZVAL_DEREF(val);
		if (key != NULL && zend_string_equals_literal(key, "fields")) {
			fields = val;
		} else if (key != NULL && zend_string_equals_literal(key, "where")) {
			where = val;
		} else if (key != NULL && zend_string_equals_literal(key, "output")) {
			output = val;
		} else if (key != NULL && zend_string_equals_literal(key, "maxRecordBytes")) {
			max = val;
		} else if (key != NULL && zend_string_equals_literal(key, "skipEmptyLines")) {
			skip = val;
		} else {
			jf_throw_plan(JF_R_UNKNOWN_OPTION, NULL, NULL, 0, "Plan spec contains an unknown option");
			return false;
		}
	} ZEND_HASH_FOREACH_END();

	if (fields == NULL) {
		return jf_plan_fail(JF_R_MISSING_OPTION, "fields", NULL, 0, "is required");
	}
	if (!jf_compile_fields(d, fields)
			|| (where != NULL && !jf_compile_filters(d, where))
			|| !jf_compile_options(d, output, max, skip)) {
		return false;
	}
	return jf_compile_paths(d);
}

ZEND_METHOD(JsonFuse_Plan, compile)
{
	HashTable *spec;
	jf_plan_object *o;

	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_ARRAY_HT(spec)
	ZEND_PARSE_PARAMETERS_END();

	object_init_ex(return_value, jf_ce_Plan);
	o = jf_plan_from_obj(Z_OBJ_P(return_value));
	if (!jf_plan_build(&o->data, spec)) {
		RETURN_THROWS();
	}
	o->compiled = true;
}
