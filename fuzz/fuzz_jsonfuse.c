/*
 * libFuzzer target for the native JsonFuse core: Plan compilation, NDJSON framing,
 * parsing, typed validation, filters, projection and the writer. It links the real
 * src/jf_*.c against the fake Zend layer in fuzz/shim/php.h and calls the real
 * Runner::process body, so one process handles millions of inputs.
 *
 * Input layout (bytes):
 *   0  skipEmptyLines (bit 0), cap index (bits 1-3), repeat shift (bits 4-7)
 *   1  field count, low nibble; where count, high nibble
 *   2  read chunk selector    3  write chunk selector
 *   4  fault kind             5  fault position
 *   then 2 bytes per field, 3 bytes per where key, then the NDJSON body
 *
 * An input that starts with "FD" uses the field-spec layout:
 * the magic, the 6 bytes above, a mode byte (bit 0 limit mode, bits 1-2 its variant),
 * then 3 bytes per field (name, type and form, default) unless in limit mode, which
 * builds 128 fields with defaults itself; then where keys and body as above. Older
 * inputs keep their meaning.
 *
 * An input that starts with "FP" uses the FD layout with 5 bytes per field: name, type and
 * form, default, then a path byte and a path seed (see
 * build_path). Its limit mode builds 128 fields of 8-segment paths and puts one record
 * holding every path in front of the body.
 *
 * An input that starts with "FW" uses the FP field layout with where entries
 * (see build_where9): the magic, the 6 header bytes (in byte 1 the high nibble is the
 * count of generated records, bit 7 forcing a mutation into each; the cap index takes two
 * bits and picks from the four largest caps), the mode byte (bit 0 limit mode, bits 1-2 its
 * variant, bits 3-7 the where count modulo 18), two record-seed bytes, 5 bytes per field,
 * 10 bytes per where entry, then the body. Generated records (gen_records) go in front of the
 * body. Its limit mode builds 16 entries of 8-segment paths with all six operators, 256 in
 * members and 1024-byte operands, 1 or 128 fields, and two records, one that matches and
 * one that fails a later operator of one entry.
 *
 * Each input runs through: a reference model, a clean run compared with that model,
 * a run with short reads and writes that must match the clean run exactly, and a
 * fault-injected run that must fail safely.
 */
#include "php.h"

#include <inttypes.h>

#include "yyjson.h"

#include "jf_errors.h"
#include "jf_plan.h"
#include "jf_runner.h"
#include "jf_writer.h"

void zim_JsonFuse_Plan_compile(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_Runner_process(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_JsonFuseException_getReason(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_JsonFuseException_getField(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_PlanException_getOption(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_RecordException_getInputLine(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_RecordException_getRecordNumber(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_RecordException_getByteOffset(shim_call *execute_data, zval *return_value);
void zim_JsonFuse_RecordException_getProgress(shim_call *execute_data, zval *return_value);
extern zend_class_entry *jf_ce_Stats;

/* Fields per generated plan (the byte layout's modulus), and the most a limit-mode plan has. */
#define GEN_FIELDS 6
#define MAX_FIELDS 128
/* Where entries: the public limit, and one more so that compile meets too_many. */
#define WHERE_LIMIT 16
#define MAX_WHERE (WHERE_LIMIT + 1)
/* Where entries of the layouts before FW. */
#define LEGACY_WHERE 4
/* in members: the public limit; storage has one more, for too_many. */
#define MEMBER_LIMIT 256
#define MAX_BODY (1u << 20)
/* A limit-mode line can be 787 KB of output: its body is cut after this many lines. */
#define LIMIT_LINES 8
/* An escaped string default of 1024 bytes, with its quotes. */
#define MAX_DEFAULT_OUT (2 + 6 * 1024)
/* The FP limit record: per field and level at most a key of 7 bytes, quotes, colon, comma, braces. */
#define LIMIT_RECORD_MAX (MAX_FIELDS * 8 * 16 + 64)

#define FAIL(...) do { fprintf(stderr, "ORACLE FAILURE: " __VA_ARGS__); fputc('\n', stderr); abort(); } while (0)

/* ---- shim allocator and engine state ---- */

shim_executor_globals shim_eg;
shim_core_globals shim_pg;
zend_jsonfuse_globals jsonfuse_globals;
/* When set, the input's read callback counts as a fiber switch, as the MINIT observer would. */
static bool switch_on_read;
static bool switched;
static size_t live_blocks;
static size_t live_bytes;
zend_long shim_long_max = INT64_MAX;
/* Output length when the core first saw EG(timed_out) set; SIZE_MAX until then. */
static size_t timeout_out_len = SIZE_MAX;
static size_t fake_out_len_now(void);

typedef struct {
	size_t size;
	max_align_t pad;
} block_head;

void *shim_emalloc(size_t n)
{
	block_head *h = malloc(sizeof(block_head) + n);

	if (h == NULL) {
		abort();
	}
	h->size = n;
	live_blocks++;
	live_bytes += n;
	return h + 1;
}

void *shim_ecalloc(size_t n, size_t size)
{
	void *p = shim_emalloc(n * size);

	memset(p, 0, n * size);
	return p;
}

void *shim_erealloc(void *p, size_t n)
{
	block_head *h;

	if (p == NULL) {
		return shim_emalloc(n);
	}
	h = (block_head *)p - 1;
	live_bytes -= h->size;
	h = realloc(h, sizeof(block_head) + n);
	if (h == NULL) {
		abort();
	}
	h->size = n;
	live_bytes += n;
	return h + 1;
}

void shim_efree(void *p)
{
	block_head *h = (block_head *)p - 1;

	live_blocks--;
	live_bytes -= h->size;
	free(h);
}

void *shim_safe_emalloc(size_t n, size_t size, size_t offset)
{
	return shim_emalloc(n * size + offset);
}

char *shim_estrndup(const char *s, size_t n)
{
	char *p = shim_emalloc(n + 1);

	memcpy(p, s, n);
	p[n] = '\0';
	return p;
}

size_t zend_vspprintf(char **out, size_t max, const char *fmt, va_list args)
{
	va_list copy;
	int n;

	(void)max;
	va_copy(copy, args);
	n = vsnprintf(NULL, 0, fmt, copy);
	va_end(copy);
	*out = shim_emalloc((size_t)n + 1);
	vsnprintf(*out, (size_t)n + 1, fmt, args);
	return (size_t)n;
}

size_t spprintf(char **out, size_t max, const char *fmt, ...)
{
	va_list args;
	size_t n;

	va_start(args, fmt);
	n = zend_vspprintf(out, max, fmt, args);
	va_end(args);
	return n;
}

size_t zend_memory_usage(int real)
{
	return real ? (live_bytes + ZEND_MM_CHUNK_SIZE) & ~(ZEND_MM_CHUNK_SIZE - 1) : live_bytes;
}

const zend_object_handlers *zend_get_std_object_handlers(void)
{
	static const zend_object_handlers std = {0, NULL, NULL};

	return &std;
}

/*
 * Stats and exception objects: a few named properties and a reference count, all
 * through the counting allocator, so an exception or Stats the core leaks, or frees
 * twice, is caught like any other block.
 */
#define SHIM_PROPS 10

typedef struct {
	zend_object std;
	unsigned refcount;
	size_t count;
	struct {
		const char *name;   /* a literal from the caller */
		zval v;
	} props[SHIM_PROPS];
} shim_obj;

static zval shim_unset_prop = {IS_NULL, {0}};

void object_init_ex(zval *z, zend_class_entry *ce)
{
	z->type = IS_OBJECT;
	if (ce->create_object != NULL) {
		z->value.obj = ce->create_object(ce);
	} else {
		shim_obj *o = shim_ecalloc(1, sizeof(*o));

		o->std.ce = ce;
		o->refcount = 1;
		z->value.obj = &o->std;
	}
}

void zval_ptr_dtor(zval *z)
{
	if (z->type == IS_OBJECT) {
		shim_obj_release(z->value.obj);
	} else if (z->type == IS_STRING) {
		shim_efree(z->value.str->val);
		shim_efree(z->value.str);
	}
	z->type = IS_NULL;
}

void shim_obj_release(zend_object *obj)
{
	shim_obj *o = (shim_obj *)obj;

	if (o->refcount == 0) {
		FAIL("object released after it was freed");
	}
	if (--o->refcount == 0) {
		for (size_t i = 0; i < o->count; i++) {
			zval_ptr_dtor(&o->props[i].v);
		}
		shim_efree(o);
	}
}

static zval *shim_prop(zend_object *obj, const char *name, bool add)
{
	shim_obj *o = (shim_obj *)obj;

	for (size_t i = 0; i < o->count; i++) {
		if (strcmp(o->props[i].name, name) == 0) {
			return &o->props[i].v;
		}
	}
	if (!add) {
		return NULL;
	}
	if (o->count == SHIM_PROPS) {
		FAIL("too many properties on a %s", obj->ce->name);
	}
	o->props[o->count].name = name;
	o->props[o->count].v.type = IS_NULL;
	return &o->props[o->count++].v;
}

void zend_update_property(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, zval *value)
{
	zval *slot = shim_prop(obj, name, true);

	(void)ce;
	(void)length;
	zval_ptr_dtor(slot);
	*slot = *value;
	if (value->type == IS_OBJECT) {
		((shim_obj *)value->value.obj)->refcount++;
	} else if (value->type == IS_STRING) {
		FAIL("string property set by zval");
	}
}

void zend_update_property_long(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, zend_long value)
{
	zval z = {IS_LONG, {.lval = value}};

	zend_update_property(ce, obj, name, length, &z);
}

void zend_update_property_stringl(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, const char *value, size_t value_len)
{
	zval *slot = shim_prop(obj, name, true);
	zend_string *s = shim_emalloc(sizeof(*s));

	(void)ce;
	(void)length;
	s->len = value_len;
	s->val = shim_emalloc(value_len + 1);
	memcpy(s->val, value, value_len);
	s->val[value_len] = '\0';
	zval_ptr_dtor(slot);
	slot->type = IS_STRING;
	slot->value.str = s;
}

void zend_update_property_string(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, const char *value)
{
	zend_update_property_stringl(ce, obj, name, length, value, strlen(value));
}

zval *zend_read_property(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, bool silent, zval *rv)
{
	zval *v = shim_prop(obj, name, false);

	(void)ce;
	(void)length;
	(void)silent;
	(void)rv;
	return v != NULL ? v : &shim_unset_prop;
}

static const char *obj_str(zend_object *obj, const char *name, size_t *len)
{
	zval *v = shim_prop(obj, name, false);

	if (v == NULL || v->type != IS_STRING) {
		*len = 0;
		return NULL;
	}
	*len = v->value.str->len;
	return v->value.str->val;
}

static zend_long obj_long(zend_object *obj, const char *name)
{
	zval *v = shim_prop(obj, name, false);

	if (v == NULL || v->type != IS_LONG) {
		FAIL("%s has no int property %s", obj->ce->name, name);
	}
	return v->value.lval;
}

void zend_exception_set_previous(zend_object *exception, zend_object *add_previous)
{
	zend_object *x = exception;
	zval *prev;

	if (exception == NULL || add_previous == NULL || exception == add_previous) {
		FAIL("zend_exception_set_previous misused");
	}
	while ((prev = shim_prop(x, "previous", false)) != NULL && prev->type == IS_OBJECT) {
		x = prev->value.obj;
	}
	/* Takes over the caller's reference, as the engine does. */
	prev = shim_prop(x, "previous", true);
	prev->type = IS_OBJECT;
	prev->value.obj = add_previous;
}

bool shim_atomic_bool_load(zend_atomic_bool *b)
{
	if (b->value && timeout_out_len == SIZE_MAX) {
		timeout_out_len = fake_out_len_now();
	}
	return b->value;
}

/* ---- fake streams with chunking and faults ---- */

typedef enum {
	FAULT_NONE, FAULT_READ_FAIL, FAULT_READ_THROW, FAULT_READ_EMPTY, FAULT_EOF_THROW,
	FAULT_WRITE_FAIL, FAULT_WRITE_ZERO, FAULT_WRITE_THROW, FAULT_WRITE_OVERCLAIM,
	FAULT_FLUSH_FAIL, FAULT_FLUSH_THROW, FAULT_FLUSH_FAIL_USERLAND,
	FAULT_CLOSE_OTHER_ON_READ, FAULT_TIMED_OUT, FAULT_INPUT_HELD, FAULT_LOW_MEMORY,
	FAULT_COUNTER,
	FAULT_KINDS
} fault_kind;

typedef struct {
	const char *data;
	size_t length, pos;
	uint32_t chunk_rng;
	size_t max_chunk;
	char *out;
	size_t out_len, out_cap;
	unsigned calls;
	unsigned writes_after_failure;
	bool failed;
	bool flushed;
} fake_stream;

static fault_kind fault;
static unsigned fault_at;
static zend_resource res_in, res_out;
static php_stream stream_in, stream_out;
static fake_stream fake_in, fake_out;
static zend_class_entry ce_exception = {"JsonFuseException", NULL}, ce_plan = {"PlanException", NULL};
static zend_class_entry ce_record = {"RecordException", NULL}, ce_stream = {"StreamException", NULL};
static zend_class_entry ce_plan_class = {"Plan", NULL}, ce_stats = {"Stats", NULL};
static zend_class_entry ce_user = {"UserException", NULL}, ce_php_exception = {"Exception", NULL};
zend_class_entry *zend_ce_exception = &ce_php_exception;

static size_t fake_out_len_now(void)
{
	return fake_out.out_len;
}

static bool is_jf_exception(const zend_class_entry *ce)
{
	return ce == &ce_exception || ce == &ce_plan || ce == &ce_record || ce == &ce_stream;
}

void zend_throw_exception_internal(zend_object *ex)
{
	if (!is_jf_exception(ex->ce)) {
		FAIL("the core threw a %s", ex->ce->name);
	}
	if (shim_eg.exception != NULL) {
		zend_exception_set_previous(ex, shim_eg.exception);
	}
	shim_eg.exception = ex;
}

/* An exception thrown by a user callback (wrapper, filter): its own class, never chained. */
static void set_exception(const char *message)
{
	zval z;

	/* PHP skips user callbacks while an exception is pending, so the core must never call one then. */
	if (shim_eg.exception != NULL) {
		FAIL("stream callback ran with an exception pending (%s)", message);
	}
	object_init_ex(&z, &ce_user);
	zend_update_property_string(zend_ce_exception, z.value.obj, ZEND_STRL("message"), message);
	shim_eg.exception = z.value.obj;
}

/* Releases a whole getPrevious() chain, which the exception at its head owns. */
static void release_exception(void)
{
	if (shim_eg.exception != NULL) {
		shim_obj_release(shim_eg.exception);
		shim_eg.exception = NULL;
	}
}

static size_t next_chunk(fake_stream *f, size_t asked)
{
	uint32_t x = f->chunk_rng;

	if (f->max_chunk == 0) {
		return asked;
	}
	x ^= x << 13;
	x ^= x >> 17;
	x ^= x << 5;
	f->chunk_rng = x;
	return MIN(asked, 1 + (size_t)x % f->max_chunk);
}

ssize_t php_stream_read(php_stream *s, char *buf, size_t n)
{
	fake_stream *f = s->impl;
	size_t take;

	if (shim_eg.exception != NULL) {
		FAIL("%s called with an exception pending", __func__);
	}
	f->calls++;
	if (switch_on_read) {
		jsonfuse_globals.fiber_switches++;
		switched = true;
	}
	if (f->calls == fault_at) {
		switch (fault) {
			case FAULT_READ_FAIL:
				return -1;
			case FAULT_READ_THROW:
				set_exception("reader callback threw");
				return -1;
			case FAULT_READ_EMPTY:
				return 0;
			case FAULT_CLOSE_OTHER_ON_READ:
				/* What pclose() leaves behind: the resource no longer resolves to a stream. */
				res_out.ptr = NULL;
				stream_out.flags = 0;
				break;
			case FAULT_TIMED_OUT:
				shim_eg.timed_out.value = true;
				break;
			default:
				break;
		}
	}
	take = next_chunk(f, MIN(n, f->length - f->pos));
	memcpy(buf, f->data + f->pos, take);
	f->pos += take;
	return (ssize_t)take;
}

bool php_stream_eof(php_stream *s)
{
	fake_stream *f = s->impl;

	if (shim_eg.exception != NULL) {
		FAIL("%s called with an exception pending", __func__);
	}
	if (fault == FAULT_EOF_THROW) {
		set_exception("eof callback threw");
		return false;
	}
	if (fault == FAULT_READ_EMPTY && f->calls == fault_at) {
		return false;
	}
	return f->pos == f->length;
}

ssize_t php_stream_write(php_stream *s, const char *buf, size_t n)
{
	fake_stream *f = s->impl;
	size_t take;

	if (shim_eg.exception != NULL) {
		FAIL("%s called with an exception pending", __func__);
	}
	if (f->failed) {
		f->writes_after_failure++;
	}
	f->calls++;
	if (f->calls == fault_at) {
		switch (fault) {
			case FAULT_WRITE_FAIL:
				f->failed = true;
				return -1;
			case FAULT_WRITE_ZERO:
				f->failed = true;
				return 0;
			case FAULT_WRITE_THROW:
				f->failed = true;
				set_exception("writer callback threw");
				return -1;
			default:
				break;
		}
	}
	take = next_chunk(f, n);
	if (f->out_len + take > f->out_cap) {
		f->out_cap = MAX(f->out_len + take, f->out_cap * 2);
		f->out = realloc(f->out, f->out_cap);
		if (f->out == NULL) {
			abort();
		}
	}
	memcpy(f->out + f->out_len, buf, take);
	f->out_len += take;
	if (fault == FAULT_WRITE_OVERCLAIM) {
		return (ssize_t)(take + 1000 + f->calls);
	}
	return (ssize_t)take;
}

int php_stream_flush(php_stream *s)
{
	fake_stream *f = s->impl;

	if (shim_eg.exception != NULL) {
		FAIL("%s called with an exception pending", __func__);
	}
	f->flushed = true;
	switch (fault) {
		case FAULT_FLUSH_FAIL:
		case FAULT_FLUSH_FAIL_USERLAND:
			return -1;
		case FAULT_FLUSH_THROW:
			set_exception("flush callback threw");
			return -1;
		default:
			return 0;
	}
}

/* ---- plan built from the input, independently of src/jf_plan.c ---- */

/* A default as the plan gives it: a PHP value of any kind, valid or not for its field. */
typedef enum { D_INT, D_DOUBLE, D_BOOL, D_NULL, D_STR, D_ARRAY } dflt_kind;

typedef struct {
	dflt_kind kind;
	int64_t i;
	double d;
	bool b;
	const char *s;
	size_t len;
} dflt_val;

/* A path as the plan gives it: [name] when the spec has none; where entries always [name]. */
typedef struct {
	size_t depth;
	const char *seg[8];
	size_t len[8];
} ref_path;

typedef struct {
	const char *name;
	size_t name_length;
	int type;           /* 0 int64, 1 float64, 2 bool, 3 string */
	bool nullable;
	bool bad_name, bad_type;
	bool bad_key;       /* a key other than type and default in its array spec */
	bool bad_default;   /* a default its type does not admit */
	bool has_default;
	bool bad_path;      /* not a list of 1 to 8 strings: invalid_path */
	bool bad_seg;       /* a segment not UTF-8 or over 1024 bytes: invalid_name */
	char *dflt_out;     /* the default as output: dflt_store, filled by the reference */
	size_t dflt_len;
	ref_path path;
} ref_field;

/* The keys of an array where entry, in this order for op_names; K_UNKNOWN is any other key, an int key included. */
enum { K_EQ, K_GT, K_GE, K_LT, K_LE, K_IN, K_PATH, K_UNKNOWN };
/* An in operand: a list, not an array, an empty array, a list with one string key, a list with a hole. */
enum { IN_LIST, IN_SCALAR, IN_EMPTY, IN_STRKEY, IN_HOLE };

/* One key of an array where entry, as the plan gives it. */
typedef struct {
	uint8_t key;
	uint8_t in_shape;
	uint16_t in_count;      /* in: the array's element count */
	dflt_val v;             /* the operand of =, >, >=, <, <= */
} ref_wop;

/* A where entry as the plan gives it: a scalar shorthand, or an array of keys in declared order. */
typedef struct {
	const char *name;
	size_t name_length;
	bool bad_name;          /* a name that is not UTF-8: invalid_name */
	bool shorthand;         /* a value that is not an array, which means ['=' => v] */
	dflt_val v;             /* the shorthand's value */
	size_t key_count;
	ref_wop keys[8];
	const dflt_val *members[MEMBER_LIMIT + 1];  /* the in operand's elements */
	bool bad_path;          /* 'path' not a list of 1 to 8 strings: invalid_path */
	bool bad_seg;           /* a 'path' segment not UTF-8 or over 1024 bytes: invalid_name */
	ref_path path;          /* [name] without a 'path' key */
} ref_where;

typedef struct {
	size_t cap;
	bool skip_blank;
	bool invalid;       /* a deliberately bad spec: compile must raise PlanException */
	bool limit;         /* 128 fields with defaults (limit mode) */
	bool paths;         /* the FP layout: field paths */
	bool fw;            /* the FW layout: where entries */
	uint8_t mode;       /* the mode byte of the FD, FP and FW layouts */
	size_t records;     /* FW: records generated in front of the body */
	bool rec_forced;    /* FW: every generated record carries a mutation */
	uint16_t rec_seed;
	size_t field_count, where_count;
	ref_field fields[MAX_FIELDS];
	ref_where wheres[MAX_WHERE];
} ref_plan;

static const char *const names[] = {
	"a", "b", "id", "s", "n", "x", "event", "amount", "\xc3\xa9", "__proto__",
	"0", "123", "\"q", "a\\b", "t\tb", "\x01", "", "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
	"\xe2\x82\xac", "\xf0\x9f\x98\x80",
};
#define NAME_COUNT (sizeof(names) / sizeof(names[0]))

static const char *const type_strings[] = {"int64", "float64", "bool", "string", "int64?", "float64?", "bool?", "string?"};
static const size_t caps[] = {1, 8, 64, 256, 4096, 65536, 65537, 1048576};
static const int64_t where_ints[] = {0, 1, -1, 5, 9007199254740993, INT64_MAX, INT64_MIN, 123};
static const double where_floats[] = {0.0, -0.0, 1.5, 1e300};
static const char *const where_strs[] = {"", "a", "purchase", "\xc3\xa9", "\"", "\\", "x\ny", "a\x01"};

/* Long string defaults: 1024 bytes that each escape to six, to two, to one, and 1025 bytes. */
static char str_ctl[1024], str_quote[1024], str_plain[1024], str_over[1025];
#define DSTR(s) {D_STR, 0, 0, false, (s), sizeof(s) - 1}
/* Defaults of every kind, at the boundaries of the default rules; the reference decides which a field admits. */
static const dflt_val dflts[] = {
	{D_INT, 0, 0, false, NULL, 0}, {D_INT, 1, 0, false, NULL, 0}, {D_INT, -1, 0, false, NULL, 0}, {D_INT, -5, 0, false, NULL, 0},
	{D_INT, INT64_C(9007199254740992), 0, false, NULL, 0}, {D_INT, -INT64_C(9007199254740992), 0, false, NULL, 0},
	{D_INT, INT64_C(9007199254740993), 0, false, NULL, 0}, {D_INT, -INT64_C(9007199254740993), 0, false, NULL, 0},
	{D_INT, INT64_C(9007199254740994), 0, false, NULL, 0}, {D_INT, INT64_C(1152921504606846976), 0, false, NULL, 0},
	{D_INT, INT64_C(1152921504606846977), 0, false, NULL, 0}, {D_INT, INT64_C(9223372036854774784), 0, false, NULL, 0},
	{D_INT, INT64_C(9223372036854774785), 0, false, NULL, 0}, {D_INT, INT64_MAX, 0, false, NULL, 0},
	{D_INT, INT64_MIN, 0, false, NULL, 0}, {D_INT, INT64_MIN + 1, 0, false, NULL, 0},
	{D_DOUBLE, 0, 0.0, false, NULL, 0}, {D_DOUBLE, 0, -0.0, false, NULL, 0}, {D_DOUBLE, 0, 1.5, false, NULL, 0},
	{D_DOUBLE, 0, 2.0, false, NULL, 0}, {D_DOUBLE, 0, 1e308, false, NULL, 0}, {D_DOUBLE, 0, 5e-324, false, NULL, 0},
	{D_DOUBLE, 0, 0.1, false, NULL, 0}, {D_DOUBLE, 0, 1e21, false, NULL, 0}, {D_DOUBLE, 0, 9223372036854775808.0, false, NULL, 0},
	{D_DOUBLE, 0, NAN, false, NULL, 0}, {D_DOUBLE, 0, INFINITY, false, NULL, 0}, {D_DOUBLE, 0, -INFINITY, false, NULL, 0},
	{D_BOOL, 0, 0, true, NULL, 0}, {D_BOOL, 0, 0, false, NULL, 0}, {D_NULL, 0, 0, false, NULL, 0},
	DSTR(""), DSTR("free"), DSTR("q\"\\\n\r\t\b\f\x01\x1f/\x7f"), DSTR("\xc3\xa9"), DSTR("\xe2\x80\xa8"), DSTR("\xf0\x9f\x98\x80"),
	DSTR("a\0b"), DSTR("\xff"), DSTR("\xed\xa0\x80"), DSTR("\xc0\xaf"), DSTR("\xf4\x90\x80\x80"),
	{D_STR, 0, 0, false, str_ctl, 1024}, {D_STR, 0, 0, false, str_quote, 1024}, {D_STR, 0, 0, false, str_plain, 1024},
	{D_STR, 0, 0, false, str_over, 1025}, {D_ARRAY, 0, 0, false, NULL, 0},
};
#define DFLT_COUNT (sizeof(dflts) / sizeof(dflts[0]))
static char dflt_store[MAX_FIELDS][MAX_DEFAULT_OUT];

#define COUNT(a) (sizeof(a) / sizeof((a)[0]))
#define DINT(x) {D_INT, (int64_t)(x), 0, false, NULL, 0}
#define DDBL(x) {D_DOUBLE, 0, (x), false, NULL, 0}
/*
 * FW where operands as PHP values: numbers at the boundaries, ints then
 * floats; strings sharing prefixes with the record strings of leaf_texts; the other scalars;
 * then values that no operand admits, the array last. make_seeds.py mirrors this order. The
 * reference alone decides which operator takes which.
 */
static char str_plain_t[1024], str_e512[1024];
static const dflt_val wv_num[] = {
	DINT(0), DINT(1), DINT(-1), DINT(5), DINT(-5), DINT(123), DINT(9007199254740992), DINT(9007199254740993),
	DINT(-9007199254740993), DINT(9007199254740994), DINT(9223372036854774784), DINT(INT64_MAX - 1), DINT(INT64_MAX),
	DINT(INT64_MIN), DINT(INT64_MIN + 1),
	DDBL(0.0), DDBL(-0.0), DDBL(0.5), DDBL(-0.5), DDBL(1.0), DDBL(1.5), DDBL(5.0), DDBL(123.0), DDBL(0.1),
	DDBL(9007199254740992.0), DDBL(9007199254740994.0), DDBL(9223372036854774784.0), DDBL(9223372036854775808.0),
	DDBL(-9223372036854775808.0), DDBL(-9223372036854777856.0), DDBL(1e19), DDBL(18446744073709549568.0),
	DDBL(18446744073709551616.0), DDBL(5e-324), DDBL(-5e-324), DDBL(1e308), DDBL(-1e308),
};
static const dflt_val wv_str[] = {
	DSTR(""), DSTR("a"), DSTR("aa"), DSTR("ab"), DSTR("abc"), DSTR("b"), DSTR("s"), DSTR("\x7f"), DSTR("a\x7f"),
	DSTR("a\xc2\x80"), DSTR("\xc2\x80"), DSTR("\xc3\xa9"), DSTR("\xc3\xa9" "a"), DSTR("\xe2\x82\xac"), DSTR("\xef\xbf\xbf"),
	DSTR("\xf0\x9f\x98\x80"), DSTR("purchase"), DSTR("2026-11-09T10:00:00Z"), DSTR("2026-11-09T10:00:00.5Z"),
	DSTR("x\ny"), DSTR("\""), DSTR("\\"), DSTR("a\x01"), DSTR("a\0b"),
	{D_STR, 0, 0, false, str_plain, 1023}, {D_STR, 0, 0, false, str_plain, 1024}, {D_STR, 0, 0, false, str_plain_t, 1024},
	{D_STR, 0, 0, false, str_e512, 1024},
};
static const dflt_val wv_other[] = {{D_BOOL, 0, 0, true, NULL, 0}, {D_BOOL, 0, 0, false, NULL, 0}, {D_NULL, 0, 0, false, NULL, 0}};
static const dflt_val wv_bad[] = {
	DDBL(NAN), DDBL(INFINITY), DDBL(-INFINITY), DSTR("\xff"), DSTR("\xc3"), DSTR("\xed\xa0\x80"), DSTR("\xc0\xaf"),
	DSTR("\xf4\x90\x80\x80"), {D_STR, 0, 0, false, str_over, 1025}, {D_ARRAY, 0, 0, false, NULL, 0},
};

/*
 * Record leaf values of the FW generator as JSON text: integers and reals at the
 * range-operand boundaries, RAW numbers that no range matches among them; strings sharing prefixes with
 * the string operands, raw and escaped; the other scalars; containers last.
 */
typedef struct {
	const char *text;
	size_t len;
} leaf_text;
#define LT(s) {(s), sizeof(s) - 1}
static char leaf_long[6][3 * 1024 + 8];
static leaf_text leaf_texts[] = {
	LT("0"), LT("1"), LT("-1"), LT("5"), LT("-5"), LT("123"), LT("0.0"), LT("-0.0"), LT("-0"), LT("0.5"), LT("-0.5"), LT("1.0"),
	LT("1.5"), LT("5.0"), LT("123.0"), LT("0.1"), LT("9007199254740992"), LT("9007199254740993"), LT("-9007199254740993"),
	LT("9007199254740994"), LT("9007199254740993.0"), LT("9007199254740992.0"), LT("9223372036854774784"),
	LT("9223372036854775806"), LT("9223372036854775807"), LT("9223372036854775808"), LT("-9223372036854775807"),
	LT("-9223372036854775808"), LT("-9223372036854775809"), LT("18446744073709549568"), LT("18446744073709551615"),
	LT("18446744073709551616"), LT("1.8446744073709552e19"), LT("1.8446744073709550e19"), LT("9.223372036854775808e18"),
	LT("-9.223372036854775808e18"), LT("-9.223372036854777856e18"), LT("1e19"), LT("10000000000000000000"),
	LT("5e-324"), LT("-5e-324"), LT("1e-400"), LT("-1e-400"), LT("1e308"), LT("-1e308"), LT("1e400"), LT("-1e400"),
	LT("123456789012345678901234567890"),
	LT("\"\""), LT("\"a\""), LT("\"\\u0061\""), LT("\"aa\""), LT("\"ab\""), LT("\"a\\u0062\""), LT("\"abc\""), LT("\"b\""),
	LT("\"s\""), LT("\"\x7f\""), LT("\"a\x7f\""), LT("\"a\\u007f\""), LT("\"a\xc2\x80\""), LT("\"a\\u0080\""), LT("\"\xc2\x80\""),
	LT("\"\xc3\xa9\""), LT("\"\\u00e9\""), LT("\"\\u00E9\""), LT("\"\xc3\xa9" "a\""), LT("\"\\u00e9a\""), LT("\"\xe2\x82\xac\""),
	LT("\"\\u20ac\""), LT("\"\xef\xbf\xbf\""), LT("\"\\uffff\""), LT("\"\xf0\x9f\x98\x80\""), LT("\"\\ud83d\\ude00\""),
	LT("\"\xf0\x9f\x98\x81\""), LT("\"purchase\""), LT("\"2026-11-09T10:00:00Z\""), LT("\"2026-11-09T10:00:00.5Z\""),
	LT("\"x\\ny\""), LT("\"\\\"\""), LT("\"\\\\\""), LT("\"a\\u0001\""), LT("\"a\\u0000b\""),
	{leaf_long[0], 0}, {leaf_long[1], 0}, {leaf_long[2], 0}, {leaf_long[3], 0}, {leaf_long[4], 0}, {leaf_long[5], 0},
	LT("true"), LT("false"), LT("null"),
	LT("[]"), LT("{}"), LT("[1]"), LT("{\"a\":1}"),
};
#define LEAF_SCALARS (COUNT(leaf_texts) - 4)
/* The longest leaf text: the 512 escaped e-acutes of leaf_long[5] with their quotes. */
#define LEAF_MAX (3 * 1024 + 8)

/* ---- reference model: decides each line without any jf_ code ---- */

static void put_escaped(char **p, const char *s, size_t n)
{
	static const char hex[] = "0123456789abcdef";
	size_t i;

	for (i = 0; i < n; i++) {
		unsigned char c = (unsigned char)s[i];
		char *q = *p;

		switch (c) {
			case '"': *q++ = '\\'; *q++ = '"'; break;
			case '\\': *q++ = '\\'; *q++ = '\\'; break;
			case '\b': *q++ = '\\'; *q++ = 'b'; break;
			case '\f': *q++ = '\\'; *q++ = 'f'; break;
			case '\n': *q++ = '\\'; *q++ = 'n'; break;
			case '\r': *q++ = '\\'; *q++ = 'r'; break;
			case '\t': *q++ = '\\'; *q++ = 't'; break;
			default:
				if (c < 0x20) {
					*q++ = '\\'; *q++ = 'u'; *q++ = '0'; *q++ = '0';
					*q++ = hex[c >> 4];
					*q++ = hex[c & 15];
				} else {
					*q++ = (char)c;
				}
		}
		*p = q;
	}
}

static void put_name(char *out, const ref_field *f, size_t *len)
{
	char *p = out;

	put_escaped(&p, f->name, f->name_length);
	*len = (size_t)(p - out);
}

/* RFC 3629 by code point: no overlong form, surrogate or value above U+10FFFF. */
static bool ref_utf8_ok(const char *s, size_t n)
{
	size_t i = 0;

	while (i < n) {
		unsigned char c = (unsigned char)s[i];
		size_t len = c < 0x80 ? 1 : (c >> 5) == 6 ? 2 : (c >> 4) == 14 ? 3 : (c >> 3) == 30 ? 4 : 0;
		uint32_t cp = len == 1 ? c : len == 2 ? c & 0x1Fu : len == 3 ? c & 0x0Fu : c & 0x07u;
		static const uint32_t min_cp[] = {0, 0, 0x80, 0x800, 0x10000};

		if (len == 0 || n - i < len) {
			return false;
		}
		for (size_t k = 1; k < len; k++) {
			if (((unsigned char)s[i + k] & 0xC0) != 0x80) {
				return false;
			}
			cp = (cp << 6) | ((unsigned char)s[i + k] & 0x3Fu);
		}
		if (cp < min_cp[len] || cp > 0x10FFFF || (cp >= 0xD800 && cp <= 0xDFFF)) {
			return false;
		}
		i += len;
	}
	return true;
}

/* By the significand: |i| = m * 2^k with m odd is exact in binary64 when m < 2^53. */
static bool ref_int_fits_double(int64_t i)
{
	uint64_t m = i < 0 ? (uint64_t)0 - (uint64_t)i : (uint64_t)i;

	while (m != 0 && (m & 1) == 0) {
		m >>= 1;
	}
	return m < (UINT64_C(1) << 53);
}

/* The declared type with no conversion, null only for a nullable type. */
static bool ref_default_ok(int type, bool nullable, const dflt_val *v)
{
	switch (v->kind) {
		case D_NULL: return nullable;
		case D_INT: return type == 0 || (type == 1 && ref_int_fits_double(v->i));
		case D_DOUBLE: return type == 1 && isfinite(v->d);
		case D_BOOL: return type == 2;
		case D_STR: return type == 3 && v->len <= 1024 && ref_utf8_ok(v->s, v->len);
		default: return false;
	}
}

/* The output of an admitted default: what a record value of that type would produce. */
static size_t ref_default_out(int type, const dflt_val *v, char *out)
{
	char *p = out;
	yyjson_val real;

	switch (v->kind) {
		case D_NULL:
			memcpy(out, "null", 4);
			return 4;
		case D_BOOL:
			memcpy(out, v->b ? "true" : "false", v->b ? 4 : 5);
			return v->b ? 4 : 5;
		case D_STR:
			*p++ = '"';
			put_escaped(&p, v->s, v->len);
			*p++ = '"';
			return (size_t)(p - out);
		default:
			if (type == 0) {
				return (size_t)sprintf(out, "%" PRId64, v->i);
			}
			/* An int float64 default is a REAL: 1 gives 1.0. */
			memset(&real, 0, sizeof(real));
			yyjson_set_real(&real, v->kind == D_INT ? (double)v->i : v->d);
			return (size_t)(yyjson_write_number(&real, out) - out);
	}
}

static yyjson_val *ref_key(yyjson_val *root, const char *name, size_t name_length, int *count)
{
	yyjson_val *key, *val, *found = NULL;
	size_t idx, max;

	*count = 0;
	yyjson_obj_foreach(root, idx, max, key, val) {
		if (yyjson_get_len(key) == name_length && memcmp(yyjson_get_str(key), name, name_length) == 0) {
			(*count)++;
			if (found == NULL) {
				found = val;
			}
		}
	}
	return found;
}

/* Entry e of the plan: the fields in order, then the where entries. */
static const ref_path *entry_path(const ref_plan *plan, size_t e)
{
	return e < plan->field_count ? &plan->fields[e].path : &plan->wheres[e - plan->field_count].path;
}

static const char *entry_name(const ref_plan *plan, size_t e, size_t *len)
{
	if (e < plan->field_count) {
		*len = plan->fields[e].name_length;
		return plan->fields[e].name;
	}
	*len = plan->wheres[e - plan->field_count].name_length;
	return plan->wheres[e - plan->field_count].name;
}

static bool seg_eq(const ref_path *p, size_t k, const char *s, size_t n)
{
	return p->len[k] == n && (n == 0 || memcmp(p->seg[k], s, n) == 0);
}

/* The first `levels` segments of a and b are equal. */
static bool prefix_eq(const ref_path *a, const ref_path *b, size_t levels)
{
	for (size_t k = 0; k < levels; k++) {
		if (!seg_eq(a, k, b->seg[k], b->len[k])) {
			return false;
		}
	}
	return true;
}

/*
 * Recursively and in document order. A key of an object reached through
 * the keys of parent's path up to `level` is a node when some entry's path continues with it;
 * the node is identified by its owner (the first such entry, fields before where) and its
 * level, since both name one key sequence. Returns the owner of the first node met twice, or -1.
 */
static int ref_walk(yyjson_val *obj, size_t level, int parent, const ref_plan *plan, bool (*visited)[8], size_t *dup_level)
{
	size_t entries = plan->field_count + plan->where_count;
	yyjson_val *key, *val;
	size_t idx, max;

	yyjson_obj_foreach(obj, idx, max, key, val) {
		int owner = -1;
		bool inner = false;

		for (size_t e = 0; e < entries; e++) {
			const ref_path *p = entry_path(plan, e);

			if (p->depth > level && (parent < 0 || prefix_eq(p, entry_path(plan, (size_t)parent), level))
					&& seg_eq(p, level, yyjson_get_str(key), yyjson_get_len(key))) {
				owner = owner < 0 ? (int)e : owner;
				inner = inner || p->depth > level + 1;
			}
		}
		if (owner < 0) {
			continue;
		}
		if (visited[owner][level]) {
			*dup_level = level;
			return owner;
		}
		visited[owner][level] = true;
		if (inner && yyjson_is_obj(val)) {
			int d = ref_walk(val, level + 1, owner, plan, visited, dup_level);

			if (d >= 0) {
				return d;
			}
		}
	}
	return -1;
}

static bool ref_duplicate(yyjson_val *root, const ref_plan *plan, char *reason, size_t reason_cap, const char **field, size_t *field_length)
{
	bool visited[MAX_FIELDS + MAX_WHERE][8];
	size_t level = 0, len;
	int owner;
	const char *name;
	char *p;

	memset(visited, 0, sizeof(visited));
	owner = ref_walk(root, 0, -1, plan, visited, &level);
	if (owner < 0) {
		return false;
	}
	name = entry_name(plan, (size_t)owner, &len);
	/* The text when the repeated key is a top-level key equal to the owner's name. */
	p = reason + snprintf(reason, reason_cap, level == 0 && seg_eq(entry_path(plan, (size_t)owner), 0, name, len)
		? "duplicate key \"" : "duplicate key on the path of \"");
	put_escaped(&p, name, len);
	*p++ = '"';
	*p = '\0';
	*field = name;
	*field_length = len;
	return true;
}

/* Descends the first occurrence of each segment. -1: an intermediate is present but not an object. */
static int ref_resolve(yyjson_val *root, const ref_path *p, yyjson_val **out)
{
	yyjson_val *obj = root;
	int count;

	*out = NULL;
	for (size_t k = 0; k < p->depth; k++) {
		yyjson_val *v = ref_key(obj, p->seg[k], p->len[k], &count);

		if (v == NULL) {
			return 0;
		}
		if (k + 1 == p->depth) {
			*out = v;
			return 0;
		}
		if (!yyjson_is_obj(v)) {
			return -1;
		}
		obj = v;
	}
	return 0;
}

/* The kind word of SPEC.md section 5 for a value that failed its declared type. */
static const char *ref_kind(int type, yyjson_val *v)
{
	if (yyjson_is_raw(v)) {
		return "out-of-range number";
	}
	if (yyjson_is_null(v)) {
		return "null";
	}
	if (yyjson_is_bool(v)) {
		return "bool";
	}
	if (yyjson_is_str(v)) {
		return "string";
	}
	if (yyjson_is_arr(v)) {
		return "array";
	}
	if (yyjson_is_obj(v)) {
		return "object";
	}
	if (yyjson_is_real(v)) {
		return "real";
	}
	return (type == 0 && yyjson_is_uint(v)) ? "out-of-range number" : "integer";
}

static bool ref_type_ok(int type, yyjson_val *v)
{
	yyjson_val tmp;

	switch (type) {
		case 0:
			return yyjson_is_sint(v) || (yyjson_is_uint(v) && yyjson_get_uint(v) <= INT64_MAX);
		case 1:
			return yyjson_is_num(v) || (yyjson_is_raw(v) && yyjson_read_number(yyjson_get_raw(v), &tmp, 0, NULL, NULL) != NULL);
		case 2:
			return yyjson_is_bool(v);
		default:
			return yyjson_is_str(v);
	}
}

/* A scalar for =, in members and the shorthand: valid UTF-8 of at most 1024 bytes, an int, a finite float, a bool, null. */
static bool ref_scalar_ok(const dflt_val *v)
{
	switch (v->kind) {
		case D_STR: return v->len <= 1024 && ref_utf8_ok(v->s, v->len);
		case D_DOUBLE: return isfinite(v->d);
		case D_ARRAY: return false;
		default: return true;
	}
}

/* What a range operand takes, 0 numbers (an int or a finite float), 1 strings; -1 if it is no range operand. */
static int ref_bound_kind(const dflt_val *v)
{
	if (v->kind == D_INT || (v->kind == D_DOUBLE && isfinite(v->d))) {
		return 0;
	}
	return v->kind == D_STR && ref_scalar_ok(v) ? 1 : -1;
}

/* The type-strict equality of = and in: 1 never matches 1.0 or "1"; integers match as integers. */
static bool ref_equals(const dflt_val *s, yyjson_val *v)
{
	switch (s->kind) {
		case D_STR:
			return yyjson_is_str(v) && yyjson_get_len(v) == s->len && (s->len == 0 || memcmp(yyjson_get_str(v), s->s, s->len) == 0);
		case D_INT:
			if (yyjson_is_sint(v)) {
				return yyjson_get_sint(v) == s->i;
			}
			return yyjson_is_uint(v) && s->i >= 0 && yyjson_get_uint(v) == (uint64_t)s->i;
		case D_DOUBLE:
			return yyjson_is_real(v) && yyjson_get_real(v) == s->d;
		case D_BOOL:
			return yyjson_is_bool(v) && yyjson_get_bool(v) == s->b;
		case D_NULL:
			return yyjson_is_null(v);
		default:
			return false;
	}
}

/* A number by exact value: an integer as sign and magnitude, or a finite double. */
typedef struct {
	bool is_int, neg;
	uint64_t mag;
	double d;
} ref_num;

/*
 * An integer magnitude against a positive finite double, exactly. frexp gives d = m * 2^e with
 * m in [0.5, 1), so d = sig * 2^(e - 53) with the integer sig in [2^52, 2^53): no rounding.
 */
static int ref_cmp_mag_double(uint64_t mag, double d)
{
	int e, shift;
	uint64_t sig, whole;
	bool fraction;

	sig = (uint64_t)ldexp(frexp(d, &e), 53);
	shift = e - 53;
	if (shift >= 0) {
		if (shift > 11) {
			return -1;      /* d >= 2^64, above every magnitude */
		}
		whole = sig << shift;
		return (mag > whole) - (mag < whole);
	}
	whole = -shift >= 64 ? 0 : sig >> -shift;
	fraction = -shift >= 64 || (sig & ((UINT64_C(1) << -shift) - 1)) != 0;
	if (mag != whole) {
		return mag > whole ? 1 : -1;
	}
	return fraction ? -1 : 0;
}

/* -1, 0 or 1 by exact value; two doubles by IEEE order, so -0.0 equals 0.0 and 0. */
static int ref_cmp_num(const ref_num *a, const ref_num *b)
{
	int c;

	if (!a->is_int && !b->is_int) {
		return (a->d > b->d) - (a->d < b->d);
	}
	if (!a->is_int) {
		return -ref_cmp_num(b, a);
	}
	if (b->is_int) {
		if (a->neg != b->neg) {
			return a->neg ? -1 : 1;
		}
		c = (a->mag > b->mag) - (a->mag < b->mag);
		return a->neg ? -c : c;
	}
	if (b->d == 0) {
		return a->mag == 0 ? 0 : a->neg ? -1 : 1;
	}
	if (a->mag == 0) {
		return b->d > 0 ? -1 : 1;
	}
	if ((b->d < 0) != a->neg) {
		return a->neg ? -1 : 1;
	}
	c = ref_cmp_mag_double(a->mag, fabs(b->d));
	return a->neg ? -c : c;
}

static void ref_int_num(int64_t i, ref_num *n)
{
	n->is_int = true;
	n->neg = i < 0;
	n->mag = i < 0 ? (uint64_t)0 - (uint64_t)i : (uint64_t)i;
}

/* A record value a number range compares: a JSON integer (SINT, UINT) or a REAL, never RAW. */
static bool ref_record_num(yyjson_val *v, ref_num *n)
{
	memset(n, 0, sizeof(*n));
	if (yyjson_is_real(v)) {
		n->d = yyjson_get_real(v);
		return true;
	}
	if (yyjson_is_sint(v)) {
		ref_int_num(yyjson_get_sint(v), n);
		return true;
	}
	if (yyjson_is_uint(v)) {
		n->is_int = true;
		n->mag = yyjson_get_uint(v);
		return true;
	}
	return false;
}

/* Bytes as unsigned, the shorter first on a common prefix. */
static int ref_cmp_bytes(const char *a, size_t na, const char *b, size_t nb)
{
	for (size_t k = 0; k < na && k < nb; k++) {
		if (a[k] != b[k]) {
			return (unsigned char)a[k] < (unsigned char)b[k] ? -1 : 1;
		}
	}
	return (na > nb) - (na < nb);
}

/* For a present scalar leaf: every operator holds (AND); a range of the other kind does not. */
static bool ref_where_holds(const ref_where *w, yyjson_val *v)
{
	if (w->shorthand) {
		return ref_equals(&w->v, v);
	}
	for (size_t k = 0; k < w->key_count; k++) {
		const ref_wop *o = &w->keys[k];
		ref_num a, b;
		bool any = false;
		int c;

		switch (o->key) {
			case K_EQ:
				if (!ref_equals(&o->v, v)) {
					return false;
				}
				continue;
			case K_IN:
				for (size_t m = 0; m < o->in_count && !any; m++) {
					any = ref_equals(w->members[m], v);
				}
				if (!any) {
					return false;
				}
				continue;
			case K_PATH:
				continue;
			default:
				break;
		}
		if (ref_bound_kind(&o->v) == 1) {
			if (!yyjson_is_str(v)) {
				return false;
			}
			c = ref_cmp_bytes(yyjson_get_str(v), yyjson_get_len(v), o->v.s, o->v.len);
		} else {
			if (!ref_record_num(v, &a)) {
				return false;
			}
			memset(&b, 0, sizeof(b));
			if (o->v.kind == D_INT) {
				ref_int_num(o->v.i, &b);
			} else {
				b.d = o->v.d;
			}
			c = ref_cmp_num(&a, &b);
		}
		if (!(o->key == K_GT ? c > 0 : o->key == K_GE ? c >= 0 : o->key == K_LT ? c < 0 : c <= 0)) {
			return false;
		}
	}
	return true;
}

/*
 * Compile order inside one where entry: a shorthand is one
 * scalar. An array entry: every key first (unknown_option), then the path (invalid_path, or
 * invalid_name with getField() null), then each operand in declared order: = a scalar; a range
 * bound a number or a string; in a non-empty list (invalid_value), of at most 256 elements
 * (too_many, getField() null), each a scalar. Last, that some operator is present and that the
 * range bounds are of one kind (invalid_value). NULL when the entry is valid; *named says
 * whether getField() names it.
 */
static const char *ref_where_failure(const ref_where *w, bool *named)
{
	bool op = false, path = false, kinds[2] = {false, false};

	*named = true;
	if (w->shorthand) {
		return ref_scalar_ok(&w->v) ? NULL : "invalid_value";
	}
	for (size_t k = 0; k < w->key_count; k++) {
		if (w->keys[k].key == K_UNKNOWN) {
			return "unknown_option";
		}
		path = path || w->keys[k].key == K_PATH;
		op = op || w->keys[k].key < K_PATH;
	}
	if (path && w->bad_path) {
		return "invalid_path";
	}
	if (path && w->bad_seg) {
		*named = false;
		return "invalid_name";
	}
	for (size_t k = 0; k < w->key_count; k++) {
		const ref_wop *o = &w->keys[k];
		int kind;

		switch (o->key) {
			case K_PATH:
				break;
			case K_EQ:
				if (!ref_scalar_ok(&o->v)) {
					return "invalid_value";
				}
				break;
			case K_IN:
				if (o->in_shape != IN_LIST || o->in_count == 0) {
					return "invalid_value";
				}
				if (o->in_count > MEMBER_LIMIT) {
					*named = false;
					return "too_many";
				}
				for (size_t m = 0; m < o->in_count; m++) {
					if (!ref_scalar_ok(w->members[m])) {
						return "invalid_value";
					}
				}
				break;
			default:
				kind = ref_bound_kind(&o->v);
				if (kind < 0) {
					return "invalid_value";
				}
				kinds[kind] = true;
		}
	}
	return !op || (kinds[0] && kinds[1]) ? "invalid_value" : NULL;
}

typedef struct {
	bool failed;
	zend_long line, record, offset;
	char reason[3200];
	const char *reason_class;
	const char *code;           /* reason code */
	const char *field;          /* getField(): the plan's own name, NULL if none */
	size_t field_length;
	/* on success: */
	zend_long read, matched, bytes_read;
	char *out;
	size_t out_len;
} ref_result;

/* Expected output bytes of one validated record, appended at *p. */
static void ref_emit(char **p, const ref_plan *plan, yyjson_val *root)
{
	char *q = *p;
	size_t f;

	*q++ = '{';
	for (f = 0; f < plan->field_count; f++) {
		const ref_field *fd = &plan->fields[f];
		yyjson_val *v;

		ref_resolve(root, &fd->path, &v);
		if (f > 0) {
			*q++ = ',';
		}
		*q++ = '"';
		put_escaped(&q, fd->name, fd->name_length);
		*q++ = '"';
		*q++ = ':';
		if (v == NULL) {
			/* ref_model let the key be missing only from a field with a default. */
			memcpy(q, fd->dflt_out, fd->dflt_len);
			q += fd->dflt_len;
			continue;
		}
		if (yyjson_is_null(v)) {
			memcpy(q, "null", 4);
			q += 4;
			continue;
		}
		switch (fd->type) {
			case 0:
				q += sprintf(q, "%" PRId64, yyjson_get_sint(v));
				break;
			case 1: {
				yyjson_val real;

				memset(&real, 0, sizeof(real));
				if (yyjson_is_raw(v)) {
					yyjson_read_number(yyjson_get_raw(v), &real, 0, NULL, NULL);
				} else {
					yyjson_set_real(&real, yyjson_get_num(v));
				}
				q = yyjson_write_number(&real, q);
				break;
			}
			case 2:
				if (yyjson_get_bool(v)) {
					memcpy(q, "true", 4);
					q += 4;
				} else {
					memcpy(q, "false", 5);
					q += 5;
				}
				break;
			default:
				*q++ = '"';
				put_escaped(&q, yyjson_get_str(v), yyjson_get_len(v));
				*q++ = '"';
		}
	}
	*q++ = '}';
	*q++ = '\n';
	*p = q;
}

static size_t ref_line_bound(const ref_plan *plan, size_t rec_len)
{
	size_t f, n = rec_len + 2 + 40 * plan->field_count;

	for (f = 0; f < plan->field_count; f++) {
		n += 4 + 6 * plan->fields[f].name_length + (plan->fields[f].has_default ? 2 + 6 * 1024 : 0);
	}
	return n;
}

/* The writer's per-record reservation beyond the record (jf_writer_init): every output line must fit rec_len plus this. */
static size_t code_overhead;

static void ref_model(const ref_plan *plan, const char *body, size_t body_len, ref_result *r)
{
	size_t pos = 0, out_cap = 1 << 12;
	zend_long line = 1, record = 1;
	char *q;

	r->out = malloc(out_cap);
	if (r->out == NULL) {
		abort();
	}
	q = r->out;
	r->matched = 0;

	while (pos < body_len) {
		const char *nl = memchr(body + pos, '\n', body_len - pos);
		size_t raw = nl != NULL ? (size_t)(nl - (body + pos)) : body_len - pos;
		size_t consumed = raw + (nl != NULL ? 1 : 0);
		size_t len = raw;
		yyjson_doc *doc;
		yyjson_read_err err;
		yyjson_val *root, *leaf[MAX_WHERE];
		size_t i;
		bool ok = true, matched = true;

		if (len > 0 && body[pos + len - 1] == '\r') {
			len--;
		}
		r->line = line;
		r->record = record;
		r->offset = (zend_long)pos;

		if (len > plan->cap) {
			r->failed = true;
			snprintf(r->reason, sizeof(r->reason), "record exceeds maxRecordBytes");
			r->reason_class = "RecordException";
			r->code = "record_too_large";
			return;
		}
		if (len == 0) {
			if (!plan->skip_blank) {
				r->failed = true;
				snprintf(r->reason, sizeof(r->reason), "blank line");
				r->reason_class = "RecordException";
				r->code = "blank_line";
				return;
			}
			pos += consumed;
			line++;
			continue;
		}
		doc = yyjson_read_opts((char *)(body + pos), len, YYJSON_READ_BIGNUM_AS_RAW, NULL, &err);
		if (doc == NULL) {
			r->failed = true;
			snprintf(r->reason, sizeof(r->reason), "invalid JSON");
			r->reason_class = "RecordException";
			r->code = "invalid_json";
			return;
		}
		root = yyjson_doc_get_root(doc);
		if (!yyjson_is_obj(root)) {
			ok = false;
			snprintf(r->reason, sizeof(r->reason), "root is not an object");
			r->code = "not_object";
		} else if (ref_duplicate(root, plan, r->reason, sizeof(r->reason), &r->field, &r->field_length)) {
			ok = false;
			r->code = "duplicate_key";
		} else {
			for (i = 0; i < plan->field_count && ok; i++) {
				const ref_field *fd = &plan->fields[i];
				yyjson_val *v;
				bool not_object = ref_resolve(root, &fd->path, &v) < 0;
				char name[6 * 1024 + 1];
				size_t nlen;
				static const char *const words[] = {"int64", "float64", "bool", "string"};

				put_name(name, fd, &nlen);
				name[nlen] = '\0';
				if (not_object) {
					ok = false;
					snprintf(r->reason, sizeof(r->reason), "field \"%s\": a value on its path is not an object", name);
					r->code = "path_not_object";
					r->field = fd->name;
					r->field_length = fd->name_length;
				} else if (v == NULL && fd->has_default) {
					/* A missing key takes the default; a present null never does. */
				} else if (v == NULL) {
					ok = false;
					snprintf(r->reason, sizeof(r->reason), "missing field \"%s\"", name);
					r->code = "missing_field";
					r->field = fd->name;
					r->field_length = fd->name_length;
				} else if (yyjson_is_null(v)) {
					if (!fd->nullable) {
						ok = false;
						snprintf(r->reason, sizeof(r->reason), "field \"%s\" must not be null", name);
						r->code = "null_field";
						r->field = fd->name;
						r->field_length = fd->name_length;
					}
				} else if (!ref_type_ok(fd->type, v)) {
					ok = false;
					snprintf(r->reason, sizeof(r->reason), "field \"%s\": expected %s, got %s", name, words[fd->type], ref_kind(fd->type, v));
					r->code = "type_mismatch";
					r->field = fd->name;
					r->field_length = fd->name_length;
				}
			}
			/* Step 6 for every entry in declared order, then step 7: a missing leaf never matches, defaults never count. */
			for (i = 0; i < plan->where_count && ok; i++) {
				const ref_where *w = &plan->wheres[i];
				bool not_object = ref_resolve(root, &w->path, &leaf[i]) < 0;
				char name[6 * 1024 + 1], *p = name;

				if (!not_object && (leaf[i] == NULL || !yyjson_is_ctn(leaf[i]))) {
					continue;
				}
				put_escaped(&p, w->name, w->name_length);
				*p = '\0';
				ok = false;
				if (not_object) {
					snprintf(r->reason, sizeof(r->reason), "where key \"%s\": a value on its path is not an object", name);
					r->code = "path_not_object";
				} else {
					snprintf(r->reason, sizeof(r->reason), "where key \"%s\" must be a JSON scalar", name);
					r->code = "where_not_scalar";
				}
				r->field = w->name;
				r->field_length = w->name_length;
			}
			for (i = 0; i < plan->where_count && ok && matched; i++) {
				matched = leaf[i] != NULL && ref_where_holds(&plan->wheres[i], leaf[i]);
			}
		}
		if (!ok) {
			r->failed = true;
			r->reason_class = "RecordException";
			yyjson_doc_free(doc);
			return;
		}
		if (matched) {
			size_t need = ref_line_bound(plan, len);
			size_t used = (size_t)(q - r->out);

			if (used + need > out_cap) {
				out_cap = MAX(used + need, out_cap * 2);
				r->out = realloc(r->out, out_cap);
				if (r->out == NULL) {
					abort();
				}
				q = r->out + used;
			}
			ref_emit(&q, plan, root);
			if ((size_t)(q - (r->out + used)) > need) {
				FAIL("reference line exceeded its own bound");
			}
			/* The run's output equals these bytes, so this is the writer's line against its reservation. */
			if ((size_t)(q - (r->out + used)) > len + code_overhead) {
				FAIL("a %zu-byte line from a %zu-byte record exceeds the writer's reservation of %zu",
					(size_t)(q - (r->out + used)), len, len + code_overhead);
			}
			r->out_len = (size_t)(q - r->out);
			r->matched++;
		}
		yyjson_doc_free(doc);
		pos += consumed;
		line++;
		record++;
	}
	r->read = record - 1;
	r->bytes_read = (zend_long)body_len;
	r->out_len = (size_t)(q - r->out);
	r->line = line;
	r->record = record;
	r->offset = (zend_long)body_len;
}

/* ---- one run through the real Runner::process body ---- */

/* One exception of a getPrevious() chain, read through the real getters. */
typedef struct {
	zend_class_entry *ce;
	char reason[32];
	char field[1100];
	size_t field_length;
	bool has_field;
	zend_long line, record, offset;
	zend_long progress[5];      /* read, matched, emitted, bytesRead, bytesWritten */
} exc_info;

typedef struct {
	bool threw;
	char message[8192];
	zend_class_entry *ce;
	int chain;
	exc_info exc[3];
	size_t timeout_out_len;
	char *out;
	size_t out_len;
	zend_long read, matched, emitted, bytes_read, bytes_written;
	size_t bytes_read_bound;    /* body length, for the fault checks */
	unsigned out_writes_after_failure;
	bool flushed;
} run_result;

static void capture_exception(zend_object *ex, exc_info *e)
{
	zval self = {IS_OBJECT, {.obj = ex}}, rv;
	shim_call call = {{NULL, NULL, NULL}, &self};

	memset(e, 0, sizeof(*e));
	e->ce = ex->ce;
	if (!is_jf_exception(ex->ce)) {
		return;
	}
	zim_JsonFuse_JsonFuseException_getReason(&call, &rv);
	if (rv.type != IS_STRING || rv.value.str->len == 0 || rv.value.str->len >= sizeof(e->reason)) {
		FAIL("getReason() of a thrown %s is not a reason code", ex->ce->name);
	}
	memcpy(e->reason, rv.value.str->val, rv.value.str->len);
	{
		/* Each reason belongs to one class; internal_error keeps the two original ones. */
		static const char *const record_reasons = " blank_line record_too_large memory_limit invalid_json not_object duplicate_key"
			" missing_field null_field type_mismatch where_not_scalar path_not_object internal_error ";
		static const char *const stream_reasons = " same_stream stream_in_use stream_closed timeout read_failed no_data write_failed"
			" flush_failed counter_overflow ";
		static const char *const plan_reasons = " unknown_option missing_option invalid_option too_many invalid_name invalid_type"
			" invalid_value invalid_path duplicate_name ";
		const char *set = ex->ce == &ce_record ? record_reasons : ex->ce == &ce_stream ? stream_reasons
			: ex->ce == &ce_plan ? plan_reasons : " internal_error ";
		char word[sizeof(e->reason) + 2];

		snprintf(word, sizeof(word), " %s ", e->reason);
		if (strstr(set, word) == NULL) {
			FAIL("reason %s on a %s", e->reason, ex->ce->name);
		}
	}
	zim_JsonFuse_JsonFuseException_getField(&call, &rv);
	if (rv.type == IS_STRING) {
		if (rv.value.str->len > sizeof(e->field)) {
			FAIL("getField() too long");
		}
		memcpy(e->field, rv.value.str->val, rv.value.str->len);
		e->field_length = rv.value.str->len;
		e->has_field = true;
	} else if (rv.type != IS_NULL) {
		FAIL("getField() is neither a string nor null");
	}
	if (ex->ce == &ce_record || ex->ce == &ce_stream) {
		static const char *const names_[] = {"recordsRead", "recordsMatched", "recordsEmitted", "bytesRead", "bytesWritten"};

		zim_JsonFuse_RecordException_getInputLine(&call, &rv);
		e->line = rv.value.lval;
		zim_JsonFuse_RecordException_getRecordNumber(&call, &rv);
		e->record = rv.value.lval;
		zim_JsonFuse_RecordException_getByteOffset(&call, &rv);
		e->offset = rv.value.lval;
		zim_JsonFuse_RecordException_getProgress(&call, &rv);
		if (rv.type != IS_OBJECT || rv.value.obj->ce != &ce_stats) {
			FAIL("getProgress() is not a Stats");
		}
		for (int i = 0; i < 5; i++) {
			e->progress[i] = obj_long(rv.value.obj, names_[i]);
		}
		/* Invariants of the exception getters, on every exception of a run. */
		if (e->line < 1 || e->record < 1 || e->progress[0] != e->record - 1 || e->progress[3] != e->offset) {
			FAIL("%s %s at %" PRId64 "/%" PRId64 "/%" PRId64 " breaks the progress invariants", ex->ce->name, e->reason, e->line, e->record, e->offset);
		}
		if (e->progress[2] > e->progress[1] || e->progress[1] > e->progress[0]) {
			FAIL("progress counts out of order: emitted %" PRId64 " matched %" PRId64 " read %" PRId64, e->progress[2], e->progress[1], e->progress[0]);
		}
	}
}

static zval zv_in, zv_out, zv_plan;

static void run_once(const char *body, size_t body_len, size_t read_chunk, size_t write_chunk, uint32_t seed, run_result *r, bool held_input)
{
	size_t bytes_before = live_bytes;
	size_t blocks_before = live_blocks;
	shim_call call = {{&zv_in, &zv_out, &zv_plan}, NULL};
	zval ret;

	memset(&ret, 0, sizeof(ret));
	memset(&fake_in, 0, sizeof(fake_in));
	memset(&fake_out, 0, sizeof(fake_out));
	fake_in.data = body;
	fake_in.length = body_len;
	fake_in.chunk_rng = seed | 1;
	fake_in.max_chunk = read_chunk;
	fake_out.chunk_rng = (seed << 8) | 1;
	fake_out.max_chunk = write_chunk;

	memset(&stream_in, 0, sizeof(stream_in));
	memset(&stream_out, 0, sizeof(stream_out));
	stream_in.impl = &fake_in;
	stream_out.impl = &fake_out;
	stream_out.userspace = fault == FAULT_FLUSH_FAIL_USERLAND;
	if (held_input) {
		stream_in.flags = PHP_STREAM_FLAG_NO_FCLOSE;
	}
	res_in.ptr = &stream_in;
	res_out.ptr = &stream_out;
	zv_in.type = zv_out.type = IS_RESOURCE;
	zv_in.value.res = &res_in;
	zv_out.value.res = &res_out;

	shim_eg.exception = NULL;
	shim_eg.timed_out.value = false;
	timeout_out_len = SIZE_MAX;
	switched = false;

	zim_JsonFuse_Runner_process(&call, &ret);

	r->threw = shim_eg.exception != NULL;
	r->ce = r->threw ? shim_eg.exception->ce : NULL;
	r->timeout_out_len = timeout_out_len;
	if (r->threw) {
		size_t len;
		const char *m = obj_str(shim_eg.exception, "message", &len);

		snprintf(r->message, sizeof(r->message), "%.*s", (int)len, m != NULL ? m : "");
		r->chain = 0;
		for (zend_object *x = shim_eg.exception; x != NULL; r->chain++) {
			zval *prev = shim_prop(x, "previous", false);

			if (r->chain == 3) {
				FAIL("getPrevious() chain longer than 3");
			}
			capture_exception(x, &r->exc[r->chain]);
			x = prev != NULL && prev->type == IS_OBJECT ? prev->value.obj : NULL;
		}
		release_exception();
	} else {
		r->message[0] = '\0';
	}
	r->out = fake_out.out;
	r->out_len = fake_out.out_len;
	r->out_writes_after_failure = fake_out.writes_after_failure;
	r->flushed = fake_out.flushed;

	if (!r->threw) {
		if (ret.type != IS_OBJECT || ret.value.obj->ce != &ce_stats) {
			FAIL("success without a Stats object");
		}
		r->read = obj_long(ret.value.obj, "recordsRead");
		r->matched = obj_long(ret.value.obj, "recordsMatched");
		r->emitted = obj_long(ret.value.obj, "recordsEmitted");
		r->bytes_read = obj_long(ret.value.obj, "bytesRead");
		r->bytes_written = obj_long(ret.value.obj, "bytesWritten");
		shim_obj_release(ret.value.obj);
	} else if (ret.type == IS_OBJECT) {
		FAIL("Stats object returned together with an exception");
	}
	/* Every per-run allocation is gone on every path; no flag is left set unless a fiber switched. */
	if (live_bytes != bytes_before || live_blocks != blocks_before) {
		FAIL("run leaked: %zu bytes, %zu blocks", live_bytes - bytes_before, live_blocks - blocks_before);
	}
	if (!held_input && !switched && ((stream_in.flags | stream_out.flags) & PHP_STREAM_FLAG_NO_FCLOSE) != 0) {
		FAIL("NO_FCLOSE left set after a run without a fiber switch");
	}
	if (!held_input && switched && ((stream_in.flags & stream_out.flags) & PHP_STREAM_FLAG_NO_FCLOSE) == 0) {
		FAIL("NO_FCLOSE cleared after a run during which a fiber switched");
	}
	if (held_input && (stream_out.flags & PHP_STREAM_FLAG_NO_FCLOSE) != 0) {
		FAIL("run touched the output flag before refusing a held input");
	}
	if (r->threw && r->out_writes_after_failure > 0) {
		FAIL("write issued after a failed write");
	}
}

static bool reference_decides(const ref_result *ref, const run_result *run, const ref_plan *plan)
{
	char expected[sizeof(ref->reason) + 128];

	if (ref->failed != run->threw) {
		FAIL("verdict differs: reference failed=%d run threw=%d (%s; expected %s)", ref->failed, run->threw, run->message, ref->reason);
	}
	if (ref->failed) {
		snprintf(expected, sizeof(expected), "line %" PRId64 " (record %" PRId64 ", offset %" PRId64 "): ", ref->line, ref->record, ref->offset);
		if (strncmp(run->message, expected, strlen(expected)) != 0) {
			FAIL("message context differs: got \"%s\", expected prefix \"%s\" reason \"%s\"", run->message, expected, ref->reason);
		}
		if (strcmp(ref->reason, "invalid JSON") == 0) {
			if (strncmp(run->message + strlen(expected), "invalid JSON at byte ", 21) != 0) {
				FAIL("reason differs: \"%s\"", run->message);
			}
		} else if (strcmp(run->message + strlen(expected), ref->reason) != 0) {
			FAIL("reason differs: got \"%s\", expected \"%s\"", run->message + strlen(expected), ref->reason);
		}
		if (strcmp(run->ce->name, ref->reason_class) != 0) {
			FAIL("exception class differs");
		}
		if (run->chain != 1 || strcmp(run->exc[0].reason, ref->code) != 0) {
			FAIL("reason differs: got %s (chain %d), expected %s", run->exc[0].reason, run->chain, ref->code);
		}
		if (run->exc[0].has_field != (ref->field != NULL) || (ref->field != NULL
				&& (run->exc[0].field_length != ref->field_length || memcmp(run->exc[0].field, ref->field, ref->field_length) != 0))) {
			FAIL("getField() differs for %s", ref->code);
		}
		if (run->exc[0].line != ref->line || run->exc[0].record != ref->record || run->exc[0].offset != ref->offset) {
			FAIL("position getters differ from the message prefix");
		}
		/* Every record matched before the failure is written, then it is raised. */
		if (run->exc[0].progress[1] != ref->matched || run->exc[0].progress[2] != ref->matched
				|| run->exc[0].progress[4] != (zend_long)ref->out_len) {
			FAIL("progress differs: matched %" PRId64 "/%" PRId64 " emitted %" PRId64 " written %" PRId64 "/%zu",
				run->exc[0].progress[1], ref->matched, run->exc[0].progress[2], run->exc[0].progress[4], ref->out_len);
		}
		if (run->out_len != ref->out_len || (ref->out_len > 0 && memcmp(run->out, ref->out, ref->out_len) != 0)) {
			FAIL("output before the throw is not exactly the matched records: %zu bytes, expected %zu", run->out_len, ref->out_len);
		}
		if (run->flushed) {
			FAIL("a failed run flushed");
		}
		for (size_t i = 0; run->message[i] != '\0'; i++) {
			if ((unsigned char)run->message[i] < 0x20) {
				FAIL("control byte in an exception message");
			}
		}
		return false;
	}
	if (run->read != ref->read || run->matched != ref->matched || run->emitted != ref->matched) {
		FAIL("record counters differ: read %" PRId64 "/%" PRId64 " matched %" PRId64 "/%" PRId64 " emitted %" PRId64, run->read, ref->read, run->matched, ref->matched, run->emitted);
	}
	if (run->bytes_read != ref->bytes_read) {
		FAIL("bytesRead differs");
	}
	if (run->bytes_written != (zend_long)ref->out_len || run->out_len != ref->out_len) {
		FAIL("bytesWritten differs: stat %" PRId64 ", output %zu, expected %zu", run->bytes_written, run->out_len, ref->out_len);
	}
	if (ref->out_len > 0 && memcmp(run->out, ref->out, ref->out_len) != 0) {
		FAIL("output bytes differ from the reference");
	}
	if (ref->out_len > 0 && !run->flushed) {
		FAIL("a run that wrote output did not flush");
	}
	if (ref->out_len == 0 && run->flushed) {
		FAIL("a run that wrote nothing flushed");
	}
	(void)plan;
	return true;
}

static void same_as_golden(const run_result *golden, const run_result *other, const char *what)
{
	if (golden->threw != other->threw || strcmp(golden->message, other->message) != 0) {
		FAIL("%s changed the outcome: \"%s\" vs \"%s\"", what, golden->message, other->message);
	}
	if (golden->threw && (golden->chain != other->chain || memcmp(&golden->exc[0], &other->exc[0], sizeof(golden->exc[0])) != 0)) {
		FAIL("%s changed the exception's reason, getters or progress", what);
	}
	if (golden->out_len != other->out_len || (golden->out_len > 0 && memcmp(golden->out, other->out, golden->out_len) != 0)) {
		FAIL("%s changed the output", what);
	}
	if (golden->read != other->read || golden->matched != other->matched || golden->emitted != other->emitted
		|| golden->bytes_read != other->bytes_read || golden->bytes_written != other->bytes_written) {
		FAIL("%s changed the counters", what);
	}
}

/* ---- plan construction from input bytes ---- */

static zend_string str_pool[24 * MAX_FIELDS + 64 + MAX_WHERE * (MEMBER_LIMIT + 32)];
static zend_string *key_pool[MAX_FIELDS + MAX_WHERE + 8];
static zval val_pool[MAX_FIELDS + MAX_WHERE + 8];
static zend_ulong idx_pool[MAX_FIELDS + MAX_WHERE + 8];
static size_t str_used;
/* One array field spec per field, of at most four entries. */
static HashTable fs_ht[MAX_FIELDS];
static zend_string *fs_keys[MAX_FIELDS][4];
static zval fs_vals[MAX_FIELDS][4];
static zend_ulong fs_idx[MAX_FIELDS][4];
static HashTable empty_ht;
static char limit_names[MAX_FIELDS][5];
/* One path value per field, then one per where entry, of at most nine segments. */
static HashTable path_ht[MAX_FIELDS + MAX_WHERE];
static zend_string *path_keys[MAX_FIELDS + MAX_WHERE][9];
static zval path_vals[MAX_FIELDS + MAX_WHERE][9];
static zend_ulong path_idx[MAX_FIELDS + MAX_WHERE][9];
static const char *const segs[] = {"a", "b", "c", "id", "x.y", "0", "", "\xc3\xa9"};
static char limit_segs[MAX_FIELDS][8][8];

static zend_string *mkstr(const char *s, size_t n)
{
	zend_string *z = &str_pool[str_used++];

	z->val = (char *)s;
	z->len = n;
	return z;
}

static void fs_put(size_t f, const char *key, zend_ulong idx, zval v)
{
	HashTable *ht = &fs_ht[f];

	fs_keys[f][ht->count] = key != NULL ? mkstr(key, strlen(key)) : NULL;
	fs_idx[f][ht->count] = idx;
	fs_vals[f][ht->count] = v;
	ht->count++;
}

static zval dflt_zval(const dflt_val *v)
{
	zval z;

	memset(&z, 0, sizeof(z));
	switch (v->kind) {
		case D_INT: z.type = IS_LONG; z.value.lval = v->i; break;
		case D_DOUBLE: z.type = IS_DOUBLE; z.value.dval = v->d; break;
		case D_BOOL: z.type = v->b ? IS_TRUE : IS_FALSE; break;
		case D_NULL: z.type = IS_NULL; break;
		case D_STR: z.type = IS_STRING; z.value.str = mkstr(v->s, v->len); break;
		default: z.type = IS_ARRAY; z.value.arr = &empty_ht; break;
	}
	return z;
}

static void path_put(size_t i, const char *s, size_t n)
{
	HashTable *ht = &path_ht[i];

	path_keys[i][ht->count] = NULL;
	path_idx[i][ht->count] = ht->count;
	path_vals[i][ht->count] = (zval){IS_STRING, {.str = mkstr(s, n)}};
	ht->count++;
}

/*
 * A field's 'path' value from its path byte pb and seed ps: depth 1 + (pb & 7);
 * kind (pb >> 3) & 7: 0 to 5 a valid path whose segments depend on the seed alone, so fields
 * with one seed share a path, and their depths decide equal or strict prefix; 6 an invalid
 * shape (ps & 7: 9 segments, empty, an int, null or array segment at some position, a string
 * key, a list with a hole, a string instead of a list); 7 a segment that is not UTF-8 or has
 * 1025 bytes.
 */
static zval build_path(ref_path *path, bool *bad_path, bool *bad_seg, size_t i, uint8_t pb, uint8_t ps)
{
	size_t depth = 1 + (pb & 7), kind = (pb >> 3) & 7, at = (size_t)(ps >> 3) % depth, k;
	zval z = {IS_ARRAY, {.arr = &path_ht[i]}};

	path_ht[i] = (HashTable){0, path_keys[i], path_idx[i], path_vals[i]};
	if (kind == 6 && (ps & 7) == 0) {
		depth = 9;
	}
	for (k = 0; k < depth && !(kind == 6 && (ps & 7) == 1); k++) {
		const char *seg = segs[(k < 3 ? ps >> (3 * k) : k + ps) & 7];

		path->seg[k % 8] = seg;
		path->len[k % 8] = strlen(seg);
		if (kind == 7 && k == at) {
			path_put(i, (ps & 1) != 0 ? "\xff" : str_over, (ps & 1) != 0 ? 1 : sizeof(str_over));
		} else {
			path_put(i, seg, strlen(seg));
		}
	}
	path->depth = depth <= 8 ? depth : 0;
	*bad_seg = kind == 7;
	*bad_path = kind == 6;
	if (kind == 6) {
		switch (ps & 7) {
			case 2: path_vals[i][at] = (zval){IS_LONG, {.lval = 0}}; break;
			case 3: path_vals[i][at] = (zval){IS_NULL, {0}}; break;
			case 4: path_vals[i][at] = (zval){IS_ARRAY, {.arr = &empty_ht}}; break;
			case 5: path_keys[i][0] = mkstr("k", 1); break;
			case 6:
				for (k = 0; k < depth; k++) {
					path_idx[i][k] = k + 1;
				}
				break;
			case 7: z = (zval){IS_STRING, {.str = mkstr("a.b", 3)}}; break;
			default: break;
		}
	}
	return z;
}

/*
 * One field's spec value and what the reference expects of it. Form (type bits
 * 4-6): 0 the type string; 1 [type]; 2 [type, default]; 3 [default, type]; 4 [type, an
 * unknown key]; 5 [default] with no type; 6 [type => not a string]; 7 the list [type].
 * With paths (the FP layout), forms 1 to 3 also take a 'path' when bit 7 of pb is set.
 */
static void build_field(ref_plan *plan, size_t i, uint8_t t, uint8_t ds, uint8_t pb, uint8_t ps, bool paths, zval *out)
{
	static const char *const unknown_keys[] = {"Default", "path", "nullable", "TYPE"};
	ref_field *f = &plan->fields[i];
	int form = (t >> 4) & 7;
	const dflt_val *dv = &dflts[ds % DFLT_COUNT];
	const char *type = (t & 0x80) != 0 ? "INT64" : type_strings[t & 7];
	zval tz = {IS_STRING, {.str = mkstr(type, strlen(type))}}, other;

	f->path = (ref_path){1, {f->name}, {f->name_length}};
	f->type = t & 3;
	f->nullable = (t & 4) != 0;
	f->bad_type = (t & 0x80) != 0;
	if (form == 0) {
		*out = tz;
		return;
	}
	fs_ht[i] = (HashTable){0, fs_keys[i], fs_idx[i], fs_vals[i]};
	memset(&other, 0, sizeof(other));
	switch (form) {
		case 1:
			fs_put(i, "type", 0, tz);
			break;
		case 2:
			fs_put(i, "type", 0, tz);
			fs_put(i, "default", 0, dflt_zval(dv));
			break;
		case 3:
			fs_put(i, "default", 0, dflt_zval(dv));
			fs_put(i, "type", 0, tz);
			break;
		case 4:
			fs_put(i, "type", 0, tz);
			if ((ds & 0xC0) == 0xC0) {
				other.type = IS_LONG;
				fs_put(i, NULL, 0, other);
			} else {
				fs_put(i, unknown_keys[(ds >> 6) & 3], 0, dflt_zval(dv));
			}
			break;
		case 5:
			fs_put(i, "default", 0, dflt_zval(dv));
			break;
		case 6:
			other.type = (ds & 3) == 0 ? IS_LONG : (ds & 3) == 1 ? IS_NULL : (ds & 3) == 2 ? IS_TRUE : IS_ARRAY;
			other.value.arr = &empty_ht;
			if (other.type == IS_LONG) {
				other.value.lval = 5;
			}
			fs_put(i, "type", 0, other);
			break;
		default:
			fs_put(i, NULL, 0, tz);
			break;
	}
	if (paths && form >= 1 && form <= 3 && (pb & 0x80) != 0) {
		fs_put(i, "path", 0, build_path(&f->path, &f->bad_path, &f->bad_seg, i, pb, ps));
	}
	/* 'path' is a spec key: a default value in its place is never a list of strings. */
	f->bad_path = f->bad_path || (form == 4 && (ds & 0xC0) == 0x40);
	f->bad_key = (form == 4 && (ds & 0xC0) != 0x40) || form == 7;
	f->bad_type = f->bad_type || form == 5 || form == 6;
	if ((form == 2 || form == 3) && !f->bad_type) {
		f->bad_default = !ref_default_ok(f->type, f->nullable, dv);
		f->has_default = !f->bad_default;
	}
	if (f->has_default) {
		f->dflt_out = dflt_store[i];
		f->dflt_len = ref_default_out(f->type, dv, f->dflt_out);
	}
	out->type = IS_ARRAY;
	out->value.arr = &fs_ht[i];
}

/*
 * Limit mode: 128 fields f000..f127, all with array specs. Variant 0: every
 * default the 1024-byte string that escapes six-fold, the writer bound's worst case; 1: a
 * default of every kind with a type that admits it; 2: as 1 but some defaults do not fit
 * their type; 3: long and escaped string defaults on string and string? fields.
 */
/* The index in dflts of the n-th (mod their count) string default a string field admits. */
static uint8_t valid_string_dflt(uint32_t n)
{
	uint8_t idx[DFLT_COUNT], count = 0;

	for (uint8_t k = 0; k < DFLT_COUNT; k++) {
		if (dflts[k].kind == D_STR && ref_default_ok(3, false, &dflts[k])) {
			idx[count++] = k;
		}
	}
	return idx[n % count];
}

static uint8_t dflt_index_of(const char *s)
{
	for (uint8_t k = 0; k < DFLT_COUNT; k++) {
		if (dflts[k].s == s) {
			return k;
		}
	}
	abort();
}

static void build_limit_plan(ref_plan *plan, uint8_t mode, uint32_t seed)
{
	uint8_t worst = dflt_index_of(str_ctl);
	uint32_t x = seed | 1;

	for (size_t i = 0; i < MAX_FIELDS; i++) {
		int variant = (mode >> 1) & 3;
		uint8_t t = 0x23, ds = worst;    /* [type string, default str_ctl] */

		x ^= x << 13;
		x ^= x >> 17;
		x ^= x << 5;
		if (variant == 1 || variant == 2) {
			const dflt_val *dv;

			ds = (uint8_t)(x % DFLT_COUNT);
			dv = &dflts[ds];
			switch (dv->kind) {
				case D_INT: t = ref_int_fits_double(dv->i) && (x & 0x100) != 0 ? 1 : 0; break;
				case D_DOUBLE: t = 1; break;
				case D_BOOL: t = 2; break;
				case D_NULL: t = 4 | ((x >> 9) & 3); break;
				default: t = 3; break;
			}
			if (variant == 1 && !ref_default_ok(t & 3, (t & 4) != 0, dv)) {
				ds = worst;
				t = 3;
			}
			t |= (x & 0x200) != 0 ? 0x20 : 0x30;
			if ((x & 0xF000) == 0) {
				t &= 0x0F;  /* a string-form field, no default */
			}
		} else if (variant == 3) {
			ds = valid_string_dflt(x);
			t = (uint8_t)(0x23 | ((x >> 8) & 4));
		}
		snprintf(limit_names[i], sizeof(limit_names[i]), "f%03zu", i);
		plan->fields[i].name = limit_names[i];
		plan->fields[i].name_length = 4;
		key_pool[i] = mkstr(limit_names[i], 4);
		idx_pool[i] = 0;
		build_field(plan, i, t, ds, 0, 0, false, &val_pool[i]);
	}
}

/*
 * FP limit mode: 128 fields f000..f127, each with an 8-segment path ending in its own name
 * Variant (mode bits 1-2) 0: a binary tree, level k taking L or R by bit k of
 * the index (382 nodes); 1: one shared 7-segment prefix, as test 135; 2: eight distinct
 * segments per field (1,024 nodes); 3: as 1, but the last path stops at the shared prefix,
 * a strict prefix of every other path, which compile must refuse. Odd fields have a default.
 */
static void build_limit_plan8(ref_plan *plan, uint8_t mode)
{
	static const char *const shared[] = {"a", "b", "c", "d", "e", "f", "g"};
	int variant = (mode >> 1) & 3;

	for (size_t i = 0; i < MAX_FIELDS; i++) {
		ref_field *f = &plan->fields[i];
		size_t depth = variant == 3 && i == MAX_FIELDS - 1 ? 7 : 8;

		snprintf(limit_names[i], sizeof(limit_names[i]), "f%03zu", i);
		f->name = limit_names[i];
		f->name_length = 4;
		key_pool[i] = mkstr(limit_names[i], 4);
		idx_pool[i] = 0;
		/* [type int64] or [type int64, default 0]: dflts[0] is the int 0. */
		build_field(plan, i, (i & 1) != 0 ? 0x20 : 0x10, 0, 0, 0, false, &val_pool[i]);
		path_ht[i] = (HashTable){0, path_keys[i], path_idx[i], path_vals[i]};
		for (size_t k = 0; k < depth; k++) {
			const char *seg = limit_names[i];

			if (k < 7 && variant == 0) {
				seg = ((i >> k) & 1) != 0 ? "R" : "L";
			} else if (k < 7 && variant == 2) {
				snprintf(limit_segs[i][k], sizeof(limit_segs[i][k]), "s%zu_%zu", k, i);
				seg = limit_segs[i][k];
			} else if (k < 7) {
				seg = shared[k];
			}
			f->path.seg[k] = seg;
			f->path.len[k] = strlen(seg);
			path_put(i, seg, strlen(seg));
		}
		f->path.depth = depth;
		fs_put(i, "path", 0, (zval){IS_ARRAY, {.arr = &path_ht[i]}});
	}
}

/* ---- FW where entries ---- */

static HashTable we_ht[MAX_WHERE];
static zend_string *we_keys[MAX_WHERE][8];
static zval we_vals[MAX_WHERE][8];
static zend_ulong we_idx[MAX_WHERE][8];
static HashTable in_ht[MAX_WHERE];
static zend_string *in_keys[MAX_WHERE][MEMBER_LIMIT + 1];
static zval in_vals[MAX_WHERE][MEMBER_LIMIT + 1];
static zend_ulong in_idx[MAX_WHERE][MEMBER_LIMIT + 1];
static char where_names[MAX_WHERE][4], where_segs[MAX_WHERE][4];
static const char *const invalid_names[] = {"\xff", "\xe2\x82", "\xed\xa0\x80", "\xf5\x80\x80\x80", "\xc0\xaf"};
/* in element counts by the count bits; 257 elements come from the invalid forms. */
static const uint16_t in_counts[] = {1, 2, 3, 5, 16, 255, 256, 128};
/* The four range operators; an entry takes them in one of 24 orders (build_where9). */
static const uint8_t range_keys[] = {K_GT, K_GE, K_LT, K_LE};

/* splitmix64: every pseudo-random choice of the FW generator, seeded from input bytes. */
static uint64_t rng_next(uint64_t *x)
{
	uint64_t z = (*x += UINT64_C(0x9E3779B97F4A7C15));

	z = (z ^ (z >> 30)) * UINT64_C(0xBF58476D1CE4E5B9);
	z = (z ^ (z >> 27)) * UINT64_C(0x94D049BB133111EB);
	return z ^ (z >> 31);
}

/* Operand sources: the operand pools of an operator, and an in operand. */
enum { S_SCALAR, S_NUM, S_STR, S_BAD_SCALAR, S_BAD_BOUND, S_IN };
/* in element sources: any scalar, numbers, strings, or three scalars repeated. */
enum { M_ANY, M_NUM, M_STR, M_FEW };

/* The operand that selector byte sel picks from source src; make_seeds.py mirrors these pools. */
static const dflt_val *pick(int src, uint64_t sel)
{
	size_t nums = COUNT(wv_num), strs = COUNT(wv_str), others = COUNT(wv_other), k;

	switch (src) {
		case S_NUM: return &wv_num[sel % nums];
		case S_STR: return &wv_str[sel % strs];
		case S_BAD_SCALAR: return &wv_bad[sel % COUNT(wv_bad)];
		case S_BAD_BOUND:
			k = sel % (others + COUNT(wv_bad));
			return k < others ? &wv_other[k] : &wv_bad[k - others];
		default:
			k = sel % (nums + strs + others);
			return k < nums ? &wv_num[k] : k < nums + strs ? &wv_str[k - nums] : &wv_other[k - nums - strs];
	}
}

/* Adds key to entry e's array and to the reference's key list. unknown names a K_UNKNOWN key; NULL makes it the int key 0. */
static ref_wop *we_add(ref_where *w, size_t e, uint8_t key, const char *unknown, zval v)
{
	static const char *const op_names[] = {"=", ">", ">=", "<", "<=", "in", "path"};
	HashTable *ht = &we_ht[e];
	const char *name = key == K_UNKNOWN ? unknown : op_names[key];
	ref_wop *o = &w->keys[w->key_count++];

	memset(o, 0, sizeof(*o));
	o->key = key;
	we_keys[e][ht->count] = name != NULL ? mkstr(name, strlen(name)) : NULL;
	we_idx[e][ht->count] = 0;
	we_vals[e][ht->count] = v;
	ht->count++;
	return o;
}

static void we_operand(ref_where *w, size_t e, uint8_t key, const dflt_val *v)
{
	we_add(w, e, key, NULL, dflt_zval(v))->v = *v;
}

/*
 * An in operand of count elements: element 0 from the selector, the rest drawn from src; a
 * non-scalar at bad_at (-1: none); shape IN_STRKEY or IN_HOLE puts one string key or one
 * hole at a drawn position, IN_SCALAR gives a scalar instead of an array.
 */
static void we_in(ref_where *w, size_t e, uint8_t shape, size_t count, int src, long bad_at, uint64_t sel, uint64_t *r)
{
	HashTable *ht = &in_ht[e];
	const dflt_val *few[3];
	size_t at = count > 0 ? rng_next(r) % count : 0;
	ref_wop *o;

	if (shape == IN_SCALAR) {
		we_add(w, e, K_IN, NULL, dflt_zval(pick(S_SCALAR, sel)))->in_shape = IN_SCALAR;
		return;
	}
	for (size_t k = 0; k < 3; k++) {
		few[k] = pick(S_SCALAR, rng_next(r));
	}
	*ht = (HashTable){0, in_keys[e], in_idx[e], in_vals[e]};
	for (size_t m = 0; m < count; m++) {
		uint64_t x = m == 0 ? sel : rng_next(r);
		const dflt_val *v = (long)m == bad_at ? pick(S_BAD_SCALAR, x)
			: src == M_NUM ? pick(S_NUM, x) : src == M_STR ? pick(S_STR, x) : src == M_FEW && m > 0 ? few[x % 3] : pick(S_SCALAR, x);

		w->members[m] = v;
		in_keys[e][m] = shape == IN_STRKEY && m == at ? mkstr("k", 1) : NULL;
		in_idx[e][m] = shape == IN_HOLE && m >= at ? m + 1 : m;
		in_vals[e][m] = dflt_zval(v);
		ht->count++;
	}
	o = we_add(w, e, K_IN, NULL, (zval){IS_ARRAY, {.arr = ht}});
	o->in_shape = count == 0 ? IN_EMPTY : shape;
	o->in_count = (uint16_t)count;
}

/* The 'path' key of entry e: build_path's shapes; bit 5 of the form gives a valid path a last segment of its own. */
static void we_path(ref_where *w, size_t e, const uint8_t *b)
{
	size_t slot = MAX_FIELDS + e;
	zval z = build_path(&w->path, &w->bad_path, &w->bad_seg, slot, b[4], b[5]);

	if ((b[1] & 0x20) != 0 && !w->bad_path && !w->bad_seg) {
		snprintf(where_segs[e], sizeof(where_segs[e]), "v%zx", e);
		w->path.seg[w->path.depth - 1] = where_segs[e];
		w->path.len[w->path.depth - 1] = strlen(where_segs[e]);
		path_vals[slot][w->path.depth - 1] = (zval){IS_STRING, {.str = mkstr(where_segs[e], strlen(where_segs[e]))}};
	}
	we_add(w, e, K_PATH, NULL, z);
}

/*
 * One FW where entry from its 10 bytes:
 *   b0 name: names[b0 % 20]; with bit 7 a name of its own ("w" and the index); 0xFB and up a
 *      name that is not UTF-8. A decimal name is an int key unless bit 6 of b4 is set.
 *   b1 form: kind (bits 0-3); bit 4 the path key after the operators; bit 5 a last path
 *      segment of the entry's own; bit 6 operators in a drawn order; bit 7 a 'path' key.
 *   b2 bits 0-1 range bounds - 1, bits 2-4 sub-form (kinds 13-15) or in element source
 *      (bits 2-3, M_*), bits 5-7 in element count (in_counts). Kind 12: bit 4 adds an in
 *      after the bounds, of 257 elements with bit 2.
 *   b3 bits 0-4 the order of the range operators (modulo 24), bits 5-6 a position, bit 7 a flip.
 *   b4, b5 the path byte and seed of build_path.  b6-b9 the operand selectors of the
 *      operators in declared order, the fifth and sixth taking b6 and b7 again; the in
 *      elements after the first are drawn.  make_seeds.py writes these bytes by name.
 * Kinds: 0 the scalar shorthand; 1 =; 2, 3 a number, string range; 4 in; 5, 6 = and a range;
 * 7, 8 in and a range; 9 = and in; 10, 11 all six operators; 12 number and string bounds
 * mixed, optionally with an in; 13 no operator or an unknown key; 14 an invalid operand of each kind; 15 a valid
 * entry of 2 to 5 operators with one operand broken.
 */
static zval build_where9(ref_plan *plan, size_t e, const uint8_t *b, size_t slot)
{
	ref_where *w = &plan->wheres[e];
	uint8_t kind = b[1] & 15, sub = (b[2] >> 2) & 7, in_shape = IN_LIST, ops[6], src[6];
	uint64_t r = (uint64_t)e << 56 | (uint64_t)b[2] << 40 | (uint64_t)b[3] << 32 | (uint64_t)b[6] << 24 | (uint64_t)b[7] << 16
		| (uint64_t)b[8] << 8 | b[9];
	size_t n = 0, bounds = 1 + (b[2] & 3), in_count = in_counts[b[2] >> 5], unknown_at = SIZE_MAX, perm = (b[3] & 31) % 24;
	size_t pos = (b[3] >> 5) & 3, k, order[4], used = 0;
	int msrc = (b[2] >> 2) & 3, num = (b[3] & 0x80) != 0 ? S_STR : S_NUM;
	long bad_at = -1;
	bool path = (b[1] & 0x80) != 0;
	const char *unknown = NULL, *name;

	if (b[0] >= 0xFB) {
		name = invalid_names[b[0] - 0xFB];
		w->bad_name = true;
	} else if ((b[0] & 0x80) != 0) {
		snprintf(where_names[e], sizeof(where_names[e]), "w%zx", e);
		name = where_names[e];
	} else {
		name = names[b[0] % NAME_COUNT];
	}
	w->name = name;
	w->name_length = strlen(name);
	w->path = (ref_path){1, {name}, {w->name_length}};
	key_pool[slot + e] = mkstr(name, w->name_length);
	idx_pool[slot + e] = 0;
	if ((strcmp(name, "0") == 0 || strcmp(name, "123") == 0) && (b[4] & 0x40) == 0) {
		key_pool[slot + e] = NULL;
		idx_pool[slot + e] = (zend_ulong)strtoul(name, NULL, 10);
	}
	if (kind == 0 || (kind == 14 && sub == 0)) {
		/* A shorthand is never an array: the last wv_bad entry is the only array. */
		w->shorthand = true;
		w->v = kind == 0 ? *pick(S_SCALAR, b[6]) : wv_bad[b[6] % (COUNT(wv_bad) - 1)];
		return dflt_zval(&w->v);
	}
	/* The range operators in order perm: digit j of perm in factorial base picks among those left. */
	for (k = 0; k < 4; k++) {
		order[k] = k;
	}
	for (k = 0; k < 4; k++) {
		size_t radix = 4 - k, j = k + perm % radix, t = order[k];

		perm /= radix;
		order[k] = order[j];
		order[j] = t;
	}
#define OP(key_, src_) do { ops[n] = (key_); src[n] = (src_); n++; } while (0)
#define RANGES(count_, src_) do { for (k = 0; k < (count_); k++) { OP(range_keys[order[k]], (src_)); } } while (0)
	switch (kind) {
		case 1: OP(K_EQ, S_SCALAR); break;
		case 2: case 3: RANGES(bounds, kind == 2 ? S_NUM : S_STR); break;
		case 4: OP(K_IN, S_IN); break;
		case 5: case 6: OP(K_EQ, kind == 5 ? S_NUM : S_STR); RANGES(bounds, kind == 5 ? S_NUM : S_STR); break;
		case 7: case 8:
			OP(K_IN, S_IN);
			RANGES(bounds, kind == 7 ? S_NUM : S_STR);
			msrc = msrc == M_FEW ? M_FEW : kind == 7 ? M_NUM : M_STR;
			break;
		case 9: OP(K_EQ, S_SCALAR); OP(K_IN, S_IN); break;
		case 10: case 11:
			OP(K_EQ, kind == 10 ? S_NUM : S_STR);
			OP(K_IN, S_IN);
			RANGES(4, kind == 10 ? S_NUM : S_STR);
			msrc = msrc == M_FEW ? M_FEW : kind == 10 ? M_NUM : M_STR;
			break;
		case 12:
			/* At least one bound of each kind: the bound at pos takes the other kind (one kind per entry). */
			bounds = MAX(bounds, 2);
			RANGES(bounds, num);
			src[1 + pos % (bounds - 1)] = num == S_NUM ? S_STR : S_NUM;
			for (k = 1; k < bounds; k++) {
				if (k != 1 + pos % (bounds - 1) && (rng_next(&r) & 1) != 0) {
					src[k] = (uint8_t)(src[0] == S_NUM ? S_STR : S_NUM);
				}
			}
			/* b2 bit 4: an in after the bounds, of 257 elements with bit 2 (too_many wins over the one-kind rule). */
			if ((b[2] & 0x10) != 0) {
				OP(K_IN, S_IN);
				in_count = (b[2] & 0x04) != 0 ? MEMBER_LIMIT + 1 : in_count;
				msrc = M_ANY;
			}
			break;
		case 13: {
			static const char *const unknown_keys[] = {"==", "IN", NULL, "Path", " >", "paths"};

			switch (sub) {
				case 0: break;                          /* [] or only a path: no operator */
				case 1: path = true; break;             /* only a path */
				case 2: OP(K_EQ, S_SCALAR); break;
				case 3: OP(K_IN, S_IN); break;
				case 4: RANGES(1, S_NUM); break;
				case 5: OP(K_GT, S_NUM); break;
				case 6: OP(K_LE, S_STR); break;
				default: OP(K_EQ, S_BAD_SCALAR); break; /* keys before values: still unknown_option */
			}
			if (sub >= 2) {
				unknown = unknown_keys[sub - 2];
				unknown_at = pos % (n + 1);
			}
			break;
		}
		case 14:
			switch (sub) {
				case 1: OP(K_EQ, S_BAD_SCALAR); break;
				case 2: RANGES(2, num); src[pos & 1] = S_BAD_BOUND; break;
				case 3: OP(K_IN, S_IN); in_shape = IN_SCALAR; break;
				case 4: OP(K_IN, S_IN); in_count = 0; break;
				case 5: OP(K_IN, S_IN); in_shape = (b[3] & 0x80) != 0 ? IN_HOLE : IN_STRKEY; break;
				case 6:
					/* 257 elements: too_many before any element, after an earlier invalid operand. */
					in_count = MEMBER_LIMIT + 1;
					bad_at = (b[3] & 0x80) != 0 ? (long)(rng_next(&r) % in_count) : -1;
					if (pos == 1) {
						OP(K_EQ, S_BAD_SCALAR);
					}
					if (pos == 3) {
						RANGES(1, num);
					}
					OP(K_IN, S_IN);
					if (pos == 2) {
						OP(K_EQ, S_BAD_SCALAR);
					}
					break;
				default:
					OP(K_IN, S_IN);
					bad_at = (long)(rng_next(&r) % in_count);
					break;
			}
			break;
		default: {
			/* 15: [=, in, four ranges] of one kind, 2 + (b2 & 3) of them, then the operand at pos broken. */
			size_t broken;

			OP(K_EQ, num);
			OP(K_IN, S_IN);
			RANGES(4, num);
			msrc = num == S_NUM ? M_NUM : M_STR;
			n = 2 + (b[2] & 3);
			broken = pos % n;
			if (ops[broken] == K_EQ) {
				src[broken] = S_BAD_SCALAR;
			} else if (ops[broken] != K_IN) {
				src[broken] = (sub & 1) != 0 ? S_BAD_BOUND : num == S_NUM ? S_STR : S_NUM;
			} else {
				switch (sub % 5) {
					case 0: in_count = MEMBER_LIMIT + 1; break;
					case 1: bad_at = (long)(rng_next(&r) % in_count); break;
					case 2: in_shape = IN_STRKEY; break;
					case 3: in_count = 0; break;
					default: in_shape = IN_SCALAR; break;
				}
			}
			break;
		}
	}
#undef OP
#undef RANGES
	if ((b[1] & 0x40) != 0) {
		for (k = n; k > 1; k--) {
			size_t j = rng_next(&r) % k;
			uint8_t t = ops[k - 1], u = src[k - 1];

			ops[k - 1] = ops[j];
			src[k - 1] = src[j];
			ops[j] = t;
			src[j] = u;
		}
	}
	we_ht[e] = (HashTable){0, we_keys[e], we_idx[e], we_vals[e]};
	if (path && (b[1] & 0x10) == 0) {
		we_path(w, e, b);
	}
	for (k = 0; k <= n; k++) {
		uint64_t sel = b[6 + used % 4];

		if (k == unknown_at) {
			we_add(w, e, K_UNKNOWN, unknown, dflt_zval(&wv_num[1]));
		}
		if (k == n) {
			break;
		}
		used++;
		if (src[k] == S_IN) {
			we_in(w, e, in_shape, in_count, msrc, bad_at, sel, &r);
		} else {
			we_operand(w, e, ops[k], pick(src[k], sel));
		}
	}
	if (path && (b[1] & 0x10) != 0) {
		we_path(w, e, b);
	}
	return (zval){IS_ARRAY, {.arr = &we_ht[e]}};
}

/*
 * FW limit mode: 16 entries w0..wf of 8-segment paths, distinct or (mode bit
 * 1) sharing a 7-segment prefix, each with the six operators in the declared order >, >=, =,
 * in, <=, <, in after 255 other elements its matching one. String entries take 1024-byte
 * operands: V, V with its last byte one below for >, one above for <. With mode bit 2 the odd
 * entries take numbers: V = 2^53 + 1 + 2e, which no double holds, = V, >= and <= V, > and <
 * the doubles V - 1 and V + 1, and the ints V + 2 + 2m as the other elements.
 */
static char lim_v[WHERE_LIMIT][3][1024];
static char lim_mem[WHERE_LIMIT][1024 + MEMBER_LIMIT];
static dflt_val lim_members[WHERE_LIMIT][MEMBER_LIMIT];
static char lim_wsegs[WHERE_LIMIT][8][8];

static int64_t lim_number(size_t e)
{
	return INT64_C(9007199254740993) + 2 * (int64_t)e;
}

static bool lim_numeric(uint8_t mode, size_t e)
{
	return (mode & 4) != 0 && (e & 1) != 0;
}

static void build_limit_where9(ref_plan *plan, uint8_t mode, size_t slot)
{
	static const char *const shared[] = {"p", "q", "r", "s", "t", "u", "v"};

	for (size_t e = 0; e < WHERE_LIMIT; e++) {
		ref_where *w = &plan->wheres[e];
		size_t ps = MAX_FIELDS + e;
		int64_t v = lim_number(e);
		bool num = lim_numeric(mode, e);
		dflt_val eq = num ? (dflt_val)DINT(v) : (dflt_val){D_STR, 0, 0, false, lim_v[e][0], 1024};
		dflt_val lo = num ? (dflt_val)DDBL((double)(v - 1)) : (dflt_val){D_STR, 0, 0, false, lim_v[e][1], 1024};
		dflt_val hi = num ? (dflt_val)DDBL((double)(v + 1)) : (dflt_val){D_STR, 0, 0, false, lim_v[e][2], 1024};
		ref_wop *o;

		snprintf(where_names[e], sizeof(where_names[e]), "w%zx", e);
		w->name = where_names[e];
		w->name_length = strlen(where_names[e]);
		key_pool[slot + e] = mkstr(w->name, w->name_length);
		idx_pool[slot + e] = 0;
		path_ht[ps] = (HashTable){0, path_keys[ps], path_idx[ps], path_vals[ps]};
		for (size_t k = 0; k < 8; k++) {
			const char *seg = lim_wsegs[e][k];

			if ((mode & 2) != 0 && k < 7) {
				seg = shared[k];
			} else {
				snprintf(lim_wsegs[e][k], sizeof(lim_wsegs[e][k]), "e%zx_%zu", e, k);
			}
			w->path.seg[k] = seg;
			w->path.len[k] = strlen(seg);
			path_put(ps, seg, strlen(seg));
		}
		w->path.depth = 8;
		we_ht[e] = (HashTable){0, we_keys[e], we_idx[e], we_vals[e]};
		we_add(w, e, K_PATH, NULL, (zval){IS_ARRAY, {.arr = &path_ht[ps]}});
		we_operand(w, e, K_GT, &lo);
		we_operand(w, e, K_GE, &eq);
		we_operand(w, e, K_EQ, &eq);
		in_ht[e] = (HashTable){0, in_keys[e], in_idx[e], in_vals[e]};
		for (size_t m = 0; m < MEMBER_LIMIT; m++) {
			lim_members[e][m] = m + 1 == MEMBER_LIMIT ? eq
				: num ? (dflt_val)DINT(v + 2 + 2 * (int64_t)m) : (dflt_val){D_STR, 0, 0, false, lim_mem[e] + m, 1024};
			w->members[m] = &lim_members[e][m];
			in_keys[e][m] = NULL;
			in_idx[e][m] = m;
			in_vals[e][m] = dflt_zval(&lim_members[e][m]);
			in_ht[e].count++;
		}
		o = we_add(w, e, K_IN, NULL, (zval){IS_ARRAY, {.arr = &in_ht[e]}});
		o->in_count = MEMBER_LIMIT;
		we_operand(w, e, K_LE, &eq);
		we_operand(w, e, K_LT, &hi);
		val_pool[slot + e] = (zval){IS_ARRAY, {.arr = &we_ht[e]}};
	}
}

/* ---- FW generated records ---- */

/* At most one node of a record is mutated: its key repeated (also escaped), its value null, not an object or a container, its key left out, or an unreferenced duplicate beside it. */
enum { R_NONE, R_DUP, R_DUP_ESCAPED, R_NULL, R_NOT_OBJECT, R_MISSING, R_UNREF_DUP };

typedef struct {
	const ref_plan *plan;
	uint64_t r;
	size_t target, tlevel;  /* the mutated node: entry target's key at level tlevel */
	int mutation;
	size_t miss;            /* limit records: the entry whose leaf fails =, or SIZE_MAX */
	char *end;
} rec_gen;

static char leaf_buf[LEAF_MAX + 16];

static void room(const rec_gen *g, const char *q, size_t need)
{
	if ((size_t)(g->end - q) < need) {
		FAIL("a generated record outgrew its bound");
	}
}

/* A JSON string of valid UTF-8: escaped as the writer would, or each code point as \u, a surrogate pair above U+FFFF. */
static char *put_json_string(char *q, const char *s, size_t n, bool unicode)
{
	*q++ = '"';
	if (!unicode) {
		put_escaped(&q, s, n);
	}
	for (size_t i = 0; unicode && i < n;) {
		unsigned char c = (unsigned char)s[i];
		size_t len = c < 0x80 ? 1 : c < 0xE0 ? 2 : c < 0xF0 ? 3 : 4;
		uint32_t cp = len == 1 ? c : len == 2 ? c & 0x1Fu : len == 3 ? c & 0x0Fu : c & 0x07u;

		for (size_t k = 1; k < len; k++) {
			cp = (cp << 6) | ((unsigned char)s[i + k] & 0x3Fu);
		}
		i += len;
		if (cp >= 0x10000) {
			cp -= 0x10000;
			q += sprintf(q, "\\u%04x\\u%04x", (unsigned)(0xD800 + (cp >> 10)), (unsigned)(0xDC00 + (cp & 0x3FF)));
		} else {
			q += sprintf(q, "\\u%04x", (unsigned)cp);
		}
	}
	*q++ = '"';
	return q;
}

/* JSON text of an operand or a neighbour: an int +-1, the next double either way, a string one byte shorter or longer. 0: none. */
static size_t operand_text(const dflt_val *v, uint64_t *r, char *out)
{
	int delta = (int)(rng_next(r) % 4) - 2;
	char tmp[1026];
	size_t len;

	delta = delta == -2 ? 0 : delta;
	switch (v->kind) {
		case D_INT:
			if (delta > 0 && v->i == INT64_MAX) {
				return (size_t)sprintf(out, "9223372036854775808");
			}
			if (delta < 0 && v->i == INT64_MIN) {
				return (size_t)sprintf(out, "-9223372036854775809");
			}
			return (size_t)sprintf(out, "%" PRId64, v->i + delta);
		case D_DOUBLE: {
			double d = delta == 0 ? v->d : nextafter(v->d, delta > 0 ? INFINITY : -INFINITY);

			len = (size_t)sprintf(out, "%.17g", isfinite(d) ? d : v->d);
			if (strpbrk(out, ".e") == NULL) {
				memcpy(out + len, ".0", 3);
				len += 2;
			}
			return len;
		}
		case D_STR:
			len = v->len;
			memcpy(tmp, v->s, len);
			if (delta < 0 && len > 0 && ref_utf8_ok(tmp, len - 1)) {
				len--;
			} else if (delta > 0) {
				tmp[len++] = (rng_next(r) & 1) != 0 ? 'a' : '\x7f';
			}
			return (size_t)(put_json_string(out, tmp, len, len <= 64 && (rng_next(r) & 3) == 0) - out);
		case D_BOOL:
			return (size_t)sprintf(out, "%s", v->b ? "true" : "false");
		case D_NULL:
			return (size_t)sprintf(out, "null");
		default:
			return 0;
	}
}

/* A record leaf value admitted by field f's type (step 5), so the record reaches step 6. */
static bool leaf_fits(const ref_field *f, const char *text, size_t len)
{
	yyjson_doc *doc = yyjson_read_opts((char *)text, len, YYJSON_READ_BIGNUM_AS_RAW, NULL, NULL);
	bool ok;

	if (doc == NULL) {
		return false;
	}
	ok = yyjson_is_null(yyjson_doc_get_root(doc)) ? f->nullable : ref_type_ok(f->type, yyjson_doc_get_root(doc));
	yyjson_doc_free(doc);
	return ok;
}

/* A leaf for entry w: half the time an operand of w or its neighbour, else a table value. */
static size_t where_leaf(rec_gen *g, const ref_where *w, char *out)
{
	const dflt_val *cand[MEMBER_LIMIT + 16];
	size_t n = 0;
	const leaf_text *t;

	if (w->shorthand) {
		cand[n++] = &w->v;
	}
	for (size_t k = 0; k < w->key_count; k++) {
		if (w->keys[k].key < K_IN) {
			cand[n++] = &w->keys[k].v;
		} else if (w->keys[k].key == K_IN) {
			for (size_t m = 0; m < w->keys[k].in_count; m++) {
				cand[n++] = w->members[m];
			}
		}
	}
	if (n > 0 && (rng_next(&g->r) & 1) == 0) {
		size_t len = operand_text(cand[rng_next(&g->r) % n], &g->r, out);

		if (len > 0) {
			return len;
		}
	}
	t = &leaf_texts[rng_next(&g->r) % LEAF_SCALARS];
	memcpy(out, t->text, t->len);
	return t->len;
}

static bool path_equal(const ref_path *a, const ref_path *b)
{
	return a->depth == b->depth && prefix_eq(a, b, a->depth);
}

/* The leaf value at path p: what the where entry with that path wants, of a kind the field with it (if any) admits. */
static char *put_leaf(char *q, rec_gen *g, const ref_path *p)
{
	const ref_plan *plan = g->plan;
	const ref_field *f = NULL;
	const ref_where *w = NULL;
	size_t e, len = 0, tries;

	for (e = 0; e < plan->field_count && f == NULL; e++) {
		f = path_equal(&plan->fields[e].path, p) ? &plan->fields[e] : NULL;
	}
	for (e = 0; e < plan->where_count && w == NULL; e++) {
		w = path_equal(&plan->wheres[e].path, p) ? &plan->wheres[e] : NULL;
	}
	if (plan->limit) {
		/* Limit records: 1 at every field leaf; V at a where leaf, or V + 1 at the missed entry's. */
		size_t we = w != NULL ? (size_t)(w - plan->wheres) : 0;

		if (w == NULL) {
			len = (size_t)sprintf(leaf_buf, "1");
		} else if (lim_numeric(plan->mode, we)) {
			len = (size_t)sprintf(leaf_buf, "%" PRId64, lim_number(we) + (we == g->miss));
		} else {
			leaf_buf[0] = '"';
			memcpy(leaf_buf + 1, lim_v[we][we == g->miss ? 2 : 0], 1024);
			leaf_buf[1025] = '"';
			len = 1026;
		}
	} else {
		for (tries = 0; tries < 8; tries++) {
			const leaf_text *t = &leaf_texts[rng_next(&g->r) % LEAF_SCALARS];

			if (w != NULL) {
				len = where_leaf(g, w, leaf_buf);
			} else {
				memcpy(leaf_buf, t->text, t->len);
				len = t->len;
			}
			if (f == NULL || leaf_fits(f, leaf_buf, len)) {
				break;
			}
		}
		if (tries == 8) {
			static const char *const fallback[] = {"1", "1.5", "true", "\"s\""};

			len = (size_t)sprintf(leaf_buf, "%s", fallback[f->type]);
		}
	}
	room(g, q, len);
	memcpy(q, leaf_buf, len);
	return q + len;
}

/*
 * A record as nested objects along every plan path, fields then where entries, keys in
 * first-use order (as limit_emit does for the FP limit record), with g's one mutation.
 */
static char *rec_emit(char *q, rec_gen *g, size_t level, int parent)
{
	const ref_plan *plan = g->plan;
	size_t entries = plan->field_count + plan->where_count;
	const ref_path *tp = entry_path(plan, g->target);
	bool first = true;

	room(g, q, 2);
	*q++ = '{';
	for (size_t e = 0; e < entries; e++) {
		const ref_path *p = entry_path(plan, e);
		bool owner = p->depth > level && (parent < 0 || prefix_eq(p, entry_path(plan, (size_t)parent), level));
		bool leaf = level + 1 == p->depth;
		int m;

		for (size_t o = 0; o < e && owner; o++) {
			const ref_path *op = entry_path(plan, o);

			owner = !(op->depth > level && prefix_eq(op, p, level + 1));
		}
		if (!owner) {
			continue;
		}
		m = level == g->tlevel && tp->depth > level && prefix_eq(tp, p, level + 1) ? g->mutation : R_NONE;
		if (m == R_MISSING) {
			continue;
		}
		for (int copy = 0; copy < (m == R_DUP || m == R_DUP_ESCAPED ? 2 : 1); copy++) {
			room(g, q, 6 * p->len[level] + 8);
			if (!first) {
				*q++ = ',';
			}
			first = false;
			q = put_json_string(q, p->seg[level], p->len[level], (copy == 1 && m == R_DUP_ESCAPED) || (rng_next(&g->r) & 7) == 0);
			*q++ = ':';
			if (m == R_NULL || m == R_NOT_OBJECT) {
				/* Not an object at an intermediate (path_not_object), a container at a leaf (type_mismatch, where_not_scalar). */
				static const char *const inner[] = {"5", "\"s\"", "[1]", "true"};
				const char *t = m == R_NULL ? "null" : leaf ? ((rng_next(&g->r) & 1) != 0 ? "[1]" : "{}") : inner[rng_next(&g->r) % 4];

				room(g, q, strlen(t));
				memcpy(q, t, strlen(t));
				q += strlen(t);
			} else if (leaf) {
				q = put_leaf(q, g, p);
			} else {
				q = rec_emit(q, g, level + 1, (int)e);
			}
		}
		if (m == R_UNREF_DUP) {
			room(g, q, 16);
			memcpy(q, ",\"zz\":1,\"zz\":2", 14);
			q += 14;
		}
	}
	room(g, q, 1);
	*q++ = '}';
	return q;
}

/* An upper bound of one generated record: every key twice, six-fold escaped, and every leaf twice. */
static size_t rec_bound(const ref_plan *plan)
{
	size_t n = 64, entries = plan->field_count + plan->where_count;

	for (size_t e = 0; e < entries; e++) {
		const ref_path *p = entry_path(plan, e);

		for (size_t k = 0; k < p->depth; k++) {
			n += 2 * (6 * p->len[k] + 32);
		}
		n += 2 * (LEAF_MAX + 16);
	}
	return n;
}

/*
 * The FW records in front of the body. Each draws one mutation at a drawn node, none three
 * times in four unless the plan forces one; limit records have none, and the one of the two that
 * misses has V + 1 at the leaf of entry rec_seed % 16: > and >= hold, the = after them does not.
 */
static size_t gen_records(char *body, const ref_plan *plan)
{
	size_t entries = plan->field_count + plan->where_count, bound = rec_bound(plan);
	char *q = body;
	rec_gen g;

	for (size_t i = 0; i < plan->records; i++) {
		memset(&g, 0, sizeof(g));
		g.plan = plan;
		g.r = (uint64_t)plan->rec_seed << 16 | i;
		g.miss = SIZE_MAX;
		g.end = q + bound;
		if (plan->limit) {
			g.miss = (i == 0) == ((plan->rec_seed & 0x10) != 0) ? plan->rec_seed % WHERE_LIMIT : SIZE_MAX;
		} else {
			g.target = rng_next(&g.r) % entries;
			g.tlevel = rng_next(&g.r) % entry_path(plan, g.target)->depth;
			g.mutation = !plan->rec_forced && (rng_next(&g.r) & 3) != 0 ? R_NONE : 1 + (int)(rng_next(&g.r) % 6);
		}
		q = rec_emit(q, &g, 0, -1);
		*q++ = '\n';
	}
	return (size_t)(q - body);
}

/* The fixed tables of the FW layout: long strings and the limit operands, drawn once from a fixed seed. */
static void fw_init(void)
{
	uint64_t r = 9;
	char *q;

	memset(str_plain_t, 's', sizeof(str_plain_t));
	str_plain_t[1023] = 't';
	for (size_t k = 0; k < 512; k++) {
		str_e512[2 * k] = '\xc3';
		str_e512[2 * k + 1] = '\xa9';
	}
	for (size_t k = 0; k < 6; k++) {
		static const size_t plain[] = {1023, 1024, 1024, 1025};

		q = leaf_long[k];
		*q++ = '"';
		if (k < 4) {
			memset(q, 's', plain[k]);
			q += plain[k];
			q[-1] = k == 2 ? 't' : 's';
		} else {
			for (size_t c = 0; c < 512; c++) {
				memcpy(q, k == 4 ? "\xc3\xa9" : "\\u00e9", k == 4 ? 2 : 6);
				q += k == 4 ? 2 : 6;
			}
		}
		*q++ = '"';
		for (size_t t = 0; t < COUNT(leaf_texts); t++) {
			if (leaf_texts[t].text == leaf_long[k]) {
				leaf_texts[t].len = (size_t)(q - leaf_long[k]);
			}
		}
	}
	for (size_t e = 0; e < WHERE_LIMIT; e++) {
		for (size_t k = 0; k < 1023; k++) {
			lim_v[e][0][k] = (char)('a' + rng_next(&r) % 26);
		}
		lim_v[e][0][1023] = 'm';
		memcpy(lim_v[e][1], lim_v[e][0], 1023);
		memcpy(lim_v[e][2], lim_v[e][0], 1023);
		lim_v[e][1][1023] = 'l';
		lim_v[e][2][1023] = 'n';
		for (size_t k = 0; k < sizeof(lim_mem[e]); k++) {
			lim_mem[e][k] = (char)('a' + rng_next(&r) % 26);
		}
		for (size_t m = 0; m + 1 < MEMBER_LIMIT; m++) {
			if (memcmp(lim_mem[e] + m, lim_v[e][0], 1024) == 0 || memcmp(lim_mem[e] + m, lim_v[e][2], 1024) == 0) {
				abort();
			}
		}
	}
}

/*
 * The FP limit record: every field's path as nested objects, keys in first-use order, value 1
 * at each leaf. The seed picks a node, by a field and a level, and a mutation: none, the key
 * repeated (a duplicate at that level), its value null (a non-object intermediate unless it
 * is the leaf) or the key left out.
 */
static char *limit_emit(char *q, const ref_plan *plan, size_t level, int parent, uint32_t seed)
{
	size_t target = (seed >> 5) % MAX_FIELDS, tlevel = (seed >> 2) % 8, mutation = seed & 3;
	bool first = true;

	*q++ = '{';
	for (size_t f = 0; f < plan->field_count; f++) {
		const ref_path *p = &plan->fields[f].path;
		bool owner = p->depth > level && (parent < 0 || prefix_eq(p, &plan->fields[parent].path, level));
		bool hit;

		for (size_t g = 0; g < f && owner; g++) {
			const ref_path *o = &plan->fields[g].path;

			owner = !(o->depth > level && prefix_eq(o, p, level + 1));
		}
		if (!owner) {
			continue;
		}
		hit = level == tlevel && plan->fields[target].path.depth > level && prefix_eq(&plan->fields[target].path, p, level + 1);
		if (hit && mutation == 3) {
			continue;
		}
		for (int copy = 0; copy < (hit && mutation == 1 ? 2 : 1); copy++) {
			if (!first) {
				*q++ = ',';
			}
			first = false;
			*q++ = '"';
			memcpy(q, p->seg[level], p->len[level]);
			q += p->len[level];
			*q++ = '"';
			*q++ = ':';
			if (hit && mutation == 2) {
				memcpy(q, "null", 4);
				q += 4;
			} else if (level + 1 == p->depth) {
				*q++ = '1';
			} else {
				q = limit_emit(q, plan, level + 1, (int)f, seed);
			}
		}
	}
	*q++ = '}';
	return q;
}

static void expected_plan_failure(const ref_plan *plan, const char **code, const char **option, const char **field);

static size_t build_plan(const uint8_t *data, size_t size, ref_plan *plan, zval *spec_out, HashTable *spec, HashTable *fields_ht, HashTable *where_ht)
{
	/* Returns the header length consumed, magic included, or 0 if the input is too short. */
	static zend_string *spec_keys[5];
	static zval spec_vals[5];
	static zend_ulong spec_idx[5];
	bool ext = size >= 2 && data[0] == 'F' && (data[1] == 'D' || data[1] == 'P' || data[1] == 'W');
	bool fw = ext && data[1] == 'W';
	bool paths = ext && data[1] != 'D';
	const uint8_t *p = ext ? data + 2 : data;
	size_t n = ext ? size - 2 : size;
	size_t field_count, where_count, used = fw ? 9 : ext ? 7 : 6, per_field = paths ? 5 : ext ? 3 : 2, i, slot = 0, per_where;
	const char *code, *option, *field;
	bool limit;
	uint8_t h0, h1;

	if (n < used) {
		return 0;
	}
	h0 = p[0];
	h1 = p[1];
	limit = ext && (p[6] & 1) != 0;
	/* FW limit mode: 128 fields with mode bit 3, else one; its 16 where entries are its own. */
	field_count = !limit ? 1 + (h1 & 15) % GEN_FIELDS : fw && (p[6] & 8) == 0 ? 1 : MAX_FIELDS;
	where_count = !fw ? (size_t)(h1 >> 4) % (LEGACY_WHERE + 1) : limit ? WHERE_LIMIT : (size_t)(p[6] >> 3) % (MAX_WHERE + 1);
	per_where = !fw ? 3 : limit ? 0 : 10;
	if (n < used + (limit ? 0 : per_field * field_count) + per_where * where_count) {
		return 0;
	}
	memset(plan, 0, sizeof(*plan));
	str_used = 0;
	plan->skip_blank = (h0 & 1) != 0;
	plan->cap = fw ? caps[4 + ((h0 >> 1) & 3)] : caps[(h0 >> 1) & 7];
	plan->field_count = field_count;
	plan->where_count = where_count;
	plan->limit = limit;
	plan->paths = paths;
	plan->fw = fw;
	plan->mode = ext ? p[6] : 0;
	if (fw) {
		plan->records = limit ? 2 : (h1 >> 4) & 7;
		plan->rec_forced = (h1 & 0x80) != 0;
		plan->rec_seed = (uint16_t)(p[7] << 8 | p[8]);
	}

	fields_ht->count = (uint32_t)field_count;
	fields_ht->keys = key_pool;
	fields_ht->idx = idx_pool;
	fields_ht->vals = val_pool;
	if (limit && fw) {
		/* The FP limit fields of variant 2, eight distinct segments each: with 16 entries, 1,152 nodes. */
		build_limit_plan8(plan, 2 << 1);
		slot = field_count;
	} else if (limit && paths) {
		build_limit_plan8(plan, p[6]);
		slot = field_count;
	} else if (limit) {
		build_limit_plan(plan, p[6], ((uint32_t)p[2] << 16) | ((uint32_t)p[3] << 8) | p[6]);
		slot = field_count;
	}
	for (i = 0; i < field_count && !limit; i++) {
		uint8_t sel = p[used++];
		uint8_t t = p[used++];
		const char *name = sel >= 0xFB ? invalid_names[sel - 0xFB] : names[sel % NAME_COUNT];
		const char *type = (t & 0x80) != 0 ? "INT64" : type_strings[t & 7];

		plan->fields[i].bad_name = sel >= 0xFB;
		plan->fields[i].name = name;
		plan->fields[i].name_length = strlen(name);
		key_pool[i] = mkstr(name, strlen(name));
		idx_pool[i] = 0;
		/*
		 * PHP turns canonical decimal keys into integer keys, but a symbol table can keep
		 * one as a string key (compact(), get_defined_vars()). Type bit 3 picks that form.
		 */
		if ((strcmp(name, "0") == 0 || strcmp(name, "123") == 0) && (t & 8) == 0) {
			key_pool[i] = NULL;
			idx_pool[i] = (zend_ulong)strtoul(name, NULL, 10);
		}
		if (ext) {
			uint8_t ds = p[used++], pb = paths ? p[used++] : 0, ps = paths ? p[used++] : 0;

			build_field(plan, i, t, ds, pb, ps, paths, &val_pool[i]);
		} else {
			plan->fields[i].path = (ref_path){1, {name}, {strlen(name)}};
			plan->fields[i].bad_type = (t & 0x80) != 0;
			plan->fields[i].type = t & 3;
			plan->fields[i].nullable = (t & 4) != 0;
			val_pool[i].type = IS_STRING;
			val_pool[i].value.str = mkstr(type, strlen(type));
		}
		slot++;
	}
	where_ht->count = (uint32_t)where_count;
	where_ht->keys = key_pool + slot;
	where_ht->idx = idx_pool + slot;
	where_ht->vals = val_pool + slot;
	if (fw && limit) {
		build_limit_where9(plan, p[6], slot);
	}
	for (i = 0; i < where_count && fw && !limit; i++) {
		val_pool[slot + i] = build_where9(plan, i, p + used, slot);
		used += 10;
	}
	/* The layouts before FW: the scalar shorthand of kind p % 5 (string, int, float, bool, null). */
	for (i = 0; i < where_count && !fw; i++) {
		const char *name = names[p[used++] % NAME_COUNT];
		uint8_t kind = p[used++] % 5;
		uint8_t sel = p[used++];
		ref_where *w = &plan->wheres[i];
		zval *v = &val_pool[slot + i];

		w->name = name;
		w->name_length = strlen(name);
		w->path = (ref_path){1, {name}, {strlen(name)}};
		w->shorthand = true;
		key_pool[slot + i] = mkstr(name, strlen(name));
		idx_pool[slot + i] = 0;
		/* Value bit 3 keeps a canonical decimal key as a string, as for fields. */
		if ((strcmp(name, "0") == 0 || strcmp(name, "123") == 0) && (sel & 8) == 0) {
			key_pool[slot + i] = NULL;
			idx_pool[slot + i] = (zend_ulong)strtoul(name, NULL, 10);
		}
		if ((sel & 0xE0) == 0xE0 && (sel & 1) == 0) {
			/* The fields array as an entry: an array entry whose keys are field names, never where keys. */
			w->shorthand = false;
			w->keys[w->key_count++].key = K_UNKNOWN;
			v->type = IS_ARRAY;
			v->value.arr = fields_ht;
			continue;
		}
		switch ((sel & 0xE0) == 0xE0 ? 5 : kind) {
			case 0: w->v = (dflt_val){D_STR, 0, 0, false, where_strs[sel & 7], strlen(where_strs[sel & 7])}; break;
			case 1: w->v = (dflt_val)DINT(where_ints[sel & 7]); break;
			case 2: w->v = (dflt_val)DDBL(where_floats[sel & 3]); break;
			case 3: w->v = (dflt_val){D_BOOL, 0, 0, (sel & 1) != 0, NULL, 0}; break;
			case 4: w->v = (dflt_val){D_NULL, 0, 0, false, NULL, 0}; break;
			default: w->v = (dflt_val)DDBL(NAN); break;
		}
		*v = dflt_zval(&w->v);
	}
	/* Every way the spec can be refused, decided in compile order by the reference alone. */
	expected_plan_failure(plan, &code, &option, &field);
	plan->invalid = code != NULL;

	spec_keys[0] = mkstr("fields", 6);
	spec_vals[0].type = IS_ARRAY;
	spec_vals[0].value.arr = fields_ht;
	spec_keys[1] = mkstr("where", 5);
	spec_vals[1].type = IS_ARRAY;
	spec_vals[1].value.arr = where_ht;
	spec_keys[2] = mkstr("maxRecordBytes", 14);
	spec_vals[2].type = IS_LONG;
	spec_vals[2].value.lval = (zend_long)plan->cap;
	spec_keys[3] = mkstr("skipEmptyLines", 14);
	spec_vals[3].type = plan->skip_blank ? IS_TRUE : IS_FALSE;
	spec_keys[4] = mkstr("output", 6);
	spec_vals[4].type = IS_STRING;
	spec_vals[4].value.str = mkstr("ndjson", 6);
	spec->count = 5;
	spec->keys = spec_keys;
	spec->idx = spec_idx;
	spec->vals = spec_vals;
	spec_out->type = IS_ARRAY;
	spec_out->value.arr = spec;
	return used + (ext ? 2 : 0);
}

/* ---- entry point ---- */

/* ---- fault runs and plan failures: reason codes ---- */

static bool flushing_reason(const exc_info *e)
{
	return e->ce == &ce_record || strcmp(e->reason, "read_failed") == 0 || strcmp(e->reason, "no_data") == 0;
}

static zend_long count_lf(const char *p, size_t n)
{
	zend_long lines = 0;

	for (size_t i = 0; i < n; i++) {
		lines += p[i] == '\n';
	}
	return lines;
}

static bool is_prefix(const char *a, size_t alen, const char *b, size_t blen)
{
	return alen <= blen && (alen == 0 || memcmp(a, b, alen) == 0);
}

static const char *fault_reason(fault_kind k)
{
	switch (k) {
		case FAULT_READ_FAIL: return "read_failed";
		case FAULT_READ_EMPTY: return "no_data";
		case FAULT_WRITE_FAIL:
		case FAULT_WRITE_ZERO: return "write_failed";
		case FAULT_FLUSH_FAIL: return "flush_failed";
		case FAULT_CLOSE_OTHER_ON_READ: return "stream_closed";
		case FAULT_TIMED_OUT: return "timeout";
		case FAULT_INPUT_HELD: return "stream_in_use";
		case FAULT_LOW_MEMORY: return "memory_limit";
		case FAULT_COUNTER: return "counter_overflow";
		default: return NULL;
	}
}

/*
 * A fault run: the reason the fault calls for, unless the clean run's own
 * failure comes first; a chain only where a write after a flushing failure failed; output
 * exactly the records matched before a flushing failure, and for every other failure only
 * what was written before it was decided.
 */
static void check_fault(const ref_plan *plan, const char *body, const run_result *golden, const run_result *f)
{
	const exc_info *e = NULL;
	const char *want = fault_reason(fault);
	ref_result pre;

	if (!f->threw) {
		return;
	}
	if (f->exc[0].ce == &ce_user) {
		if (f->chain == 2) {
			/* Only the flush-before-throw write runs a callback after a failure: never a flush. */
			if (fault != FAULT_WRITE_THROW || !flushing_reason(&f->exc[1])) {
				FAIL("a callback exception ends with an unexpected %s %s", f->exc[1].ce->name, f->exc[1].reason);
			}
			e = &f->exc[1];
		} else if (f->chain != 1) {
			FAIL("callback exception chain of %d", f->chain);
		}
	} else {
		e = &f->exc[0];
		if (f->chain == 2) {
			if ((strcmp(e->reason, "write_failed") != 0 && strcmp(e->reason, "stream_closed") != 0
					&& strcmp(e->reason, "counter_overflow") != 0) || !flushing_reason(&f->exc[1])) {
				FAIL("chain %s <- %s is not a failed write after a flushing failure", e->reason, f->exc[1].reason);
			}
			if (e->line != f->exc[1].line || e->record != f->exc[1].record || e->offset != f->exc[1].offset
					|| memcmp(e->progress, f->exc[1].progress, sizeof(e->progress)) != 0) {
				FAIL("chained exceptions disagree on position or progress");
			}
		} else if (f->chain != 1) {
			FAIL("exception chain of %d", f->chain);
		}
		if (fault == FAULT_COUNTER) {
			if (strcmp(e->reason, "counter_overflow") != 0 && e->ce != &ce_record) {
				FAIL("counter fault raised %s", e->reason);
			}
		} else if (want != NULL && strcmp(e->reason, want) != 0
				&& !(golden->threw && golden->chain == 1 && memcmp(&golden->exc[0], e, sizeof(*e)) == 0)
				&& !(f->chain == 2 && strcmp(e->reason, "write_failed") == 0)) {
			FAIL("fault %d raised %s, expected %s or the clean run's failure", (int)fault, e->reason, want);
		}
	}
	if (e == NULL || e->ce == &ce_exception) {
		return;
	}
	/* The clean run's record failure is decided before its line reaches the writer, so a write failing there is the flush. */
	if (golden->threw && golden->exc[0].ce == &ce_record && f->exc[0].ce == &ce_stream && strcmp(f->exc[0].reason, "write_failed") == 0
			&& f->exc[0].offset == golden->exc[0].offset && f->chain != 2) {
		FAIL("the failed write after a record failure lost the recorded exception");
	}
	if (strcmp(e->reason, "counter_overflow") == 0) {
		/* The bytes whose count would overflow were accepted but cannot be counted. */
		if (e->progress[4] > (zend_long)f->out_len || e->progress[2] > count_lf(f->out, f->out_len)) {
			FAIL("counter_overflow progress counts more than was written");
		}
		return;
	}
	if (e->progress[4] != (zend_long)f->out_len) {
		FAIL("bytesWritten %" PRId64 " differs from the %zu bytes written", e->progress[4], f->out_len);
	}
	if (e->progress[2] != count_lf(f->out, f->out_len)) {
		FAIL("recordsEmitted %" PRId64 " differs from the complete lines written", e->progress[2]);
	}
	if (strcmp(e->reason, "stream_in_use") == 0 && (e->line != 1 || e->record != 1 || e->offset != 0 || e->progress[1] != 0 || f->out_len != 0)) {
		FAIL("a refusal before reading reports a position or progress");
	}
	if (strcmp(e->reason, "timeout") == 0 && f->out_len != f->timeout_out_len) {
		FAIL("a write followed the timeout: %zu bytes when it was seen, %zu at the end", f->timeout_out_len, f->out_len);
	}
	if (fault == FAULT_COUNTER) {
		/* The lowered counter limit also bounds where-int matching, so the reference does not apply. */
		return;
	}
	/* A line start, or the end of input for a failure after the last line (final write or flush). */
	if (e->offset < 0 || (size_t)e->offset > golden->bytes_read_bound
			|| (e->offset > 0 && (size_t)e->offset < golden->bytes_read_bound && body[e->offset - 1] != '\n')) {
		FAIL("failure offset %" PRId64 " is not a line start", e->offset);
	}
	memset(&pre, 0, sizeof(pre));
	ref_model(plan, body, (size_t)e->offset, &pre);
	if (pre.failed || e->progress[0] != pre.read || e->progress[1] != pre.matched) {
		FAIL("progress read %" PRId64 " matched %" PRId64 " differs from the input before the failure (%" PRId64 ", %" PRId64 ")",
			e->progress[0], e->progress[1], pre.read, pre.matched);
	}
	if (!is_prefix(f->out, f->out_len, pre.out, pre.out_len)) {
		FAIL("fault output is not a prefix of the records matched before the failure");
	}
	if (flushing_reason(e) && e == &f->exc[0] && f->out_len != pre.out_len) {
		FAIL("a flushing failure wrote %zu of the %zu bytes matched before it", f->out_len, pre.out_len);
	}
	free(pre.out);
}

/* A strict prefix either way is invalid_path; equal paths conflict only within one section. */
static bool path_conflict(const ref_path *a, const ref_path *b, bool same_section, const char **code)
{
	if (!prefix_eq(a, b, MIN(a->depth, b->depth))) {
		return false;
	}
	if (a->depth != b->depth) {
		*code = "invalid_path";
		return true;
	}
	if (same_section) {
		*code = "duplicate_name";
		return true;
	}
	return false;
}

/*
 * The first failure Plan::compile meets: fields in order (name, then keys before values:
 * an unknown key, the type, the path's shape and segments, the default), where in order
 * (count, then name and entry), then entry by entry, fields before where, a name equal to an earlier one of its
 * section, then a path conflicting with any earlier path. The later entry is named.
 */
static void expected_plan_failure(const ref_plan *plan, const char **code, const char **option, const char **field)
{
	size_t i, g, entries = plan->field_count + plan->where_count;

	*field = NULL;
	for (i = 0; i < plan->field_count; i++) {
		const ref_field *f = &plan->fields[i];

		if (f->bad_name || f->bad_key || f->bad_type || f->bad_path || f->bad_seg || f->bad_default) {
			*code = f->bad_name ? "invalid_name" : f->bad_key ? "unknown_option" : f->bad_type ? "invalid_type"
				: f->bad_path ? "invalid_path" : f->bad_seg ? "invalid_name" : "invalid_value";
			*option = "fields";
			/* getField() is null for invalid_name. */
			*field = strcmp(*code, "invalid_name") == 0 ? NULL : f->name;
			return;
		}
	}
	/* Then where: its entry count, then entry by entry its name and its own failure (ref_where_failure). */
	if (plan->where_count > WHERE_LIMIT) {
		*code = "too_many";
		*option = "where";
		return;
	}
	for (i = 0; i < plan->where_count; i++) {
		const ref_where *w = &plan->wheres[i];
		bool named = false;
		const char *c = w->bad_name ? "invalid_name" : ref_where_failure(w, &named);

		if (c != NULL) {
			*code = c;
			*option = "where";
			*field = named ? w->name : NULL;
			return;
		}
	}
	for (i = 0; i < entries; i++) {
		bool is_field = i < plan->field_count;
		size_t len, glen;
		const char *name = entry_name(plan, i, &len);

		*option = is_field ? "fields" : "where";
		*field = name;
		for (g = is_field ? 0 : plan->field_count; g < i; g++) {
			const char *other = entry_name(plan, g, &glen);

			if (glen == len && memcmp(other, name, len) == 0) {
				*code = "duplicate_name";
				return;
			}
		}
		for (g = 0; g < i; g++) {
			if (path_conflict(entry_path(plan, i), entry_path(plan, g), (g < plan->field_count) == is_field, code)) {
				return;
			}
		}
	}
	*field = NULL;
	*code = NULL;
}

static void check_plan_failure(const ref_plan *plan)
{
	zval self = {IS_OBJECT, {.obj = shim_eg.exception}}, rv;
	shim_call call = {{NULL, NULL, NULL}, &self};
	const char *code, *option, *field;
	exc_info e;

	if (shim_eg.exception->ce != &ce_plan) {
		FAIL("Plan::compile raised something other than PlanException");
	}
	capture_exception(shim_eg.exception, &e);
	expected_plan_failure(plan, &code, &option, &field);
	if (code == NULL || strcmp(e.reason, code) != 0) {
		FAIL("plan reason %s, expected %s", e.reason, code != NULL ? code : "(none: the spec is valid)");
	}
	zim_JsonFuse_PlanException_getOption(&call, &rv);
	if (rv.type != IS_STRING || strcmp(rv.value.str->val, option) != 0) {
		FAIL("getOption() differs for %s", code);
	}
	if (e.has_field != (field != NULL) || (field != NULL && (e.field_length != strlen(field) || memcmp(e.field, field, e.field_length) != 0))) {
		FAIL("getField() differs for %s", code);
	}
}

/* internal_error: a Plan object that was never compiled, which PHP code cannot obtain. */
static void check_uninitialized_plan(void)
{
	static bool done;
	zval saved = zv_plan, uninit;
	run_result r;

	if (done) {
		return;
	}
	done = true;
	object_init_ex(&uninit, &ce_plan_class);
	zv_plan = uninit;
	memset(&r, 0, sizeof(r));
	run_once("{}\n", 3, 0, 0, 1, &r, false);
	zv_plan = saved;
	if (!r.threw || r.chain != 1 || r.exc[0].ce != &ce_exception || strcmp(r.exc[0].reason, "internal_error") != 0 || r.exc[0].has_field) {
		FAIL("an uninitialized plan did not raise JsonFuseException internal_error");
	}
	uninit.value.obj->handlers->free_obj(uninit.value.obj);
	shim_efree(jf_plan_from_obj(uninit.value.obj));
	free(r.out);
}

static void reset_process_state(void)
{
	shim_eg.exception = NULL;
	shim_eg.timed_out.value = false;
	shim_pg.memory_limit = -1;
	shim_long_max = INT64_MAX;
	fault = FAULT_NONE;
	fault_at = 0;
}

int LLVMFuzzerTestOneInput(const uint8_t *data, size_t size)
{
	static const size_t chunks[] = {0, 1, 3, 7, 64, 4096, 65536, 65537};
	ref_plan plan;
	zval spec, plan_ret;
	HashTable spec_ht, fields_ht, where_ht;
	shim_call call_compile;
	ref_result ref;
	run_result golden, shortio, faulted;
	size_t header, body_len, repeat, i;
	/* The six header bytes, after the FD, FP or FW magic if there is one (see build_plan). */
	const uint8_t *hd = size >= 2 && data[0] == 'F' && (data[1] == 'D' || data[1] == 'P' || data[1] == 'W') ? data + 2 : data;
	size_t short_floor, lead = 0, lead_cap = LIMIT_RECORD_MAX;
	char *body;

	jf_ce_JsonFuseException = &ce_exception;
	jf_ce_PlanException = &ce_plan;
	jf_ce_RecordException = &ce_record;
	jf_ce_StreamException = &ce_stream;
	jf_ce_Plan = &ce_plan_class;
	jf_ce_Stats = &ce_stats;
	if (ce_plan_class.create_object == NULL) {
		jf_plan_minit();
		jf_fiber_switches_observed = true;
		memset(str_ctl, 0x01, sizeof(str_ctl));
		memset(str_quote, '"', sizeof(str_quote));
		memset(str_plain, 's', sizeof(str_plain));
		memset(str_over, 's', sizeof(str_over));
		fw_init();
	}
	reset_process_state();

	header = build_plan(data, size, &plan, &spec, &spec_ht, &fields_ht, &where_ht);
	if (header == 0 || size - header > MAX_BODY) {
		return 0;
	}
	/* Repeating the body reaches the 64 KiB read chunk and the cap edges with short inputs. */
	repeat = (size_t)1 << ((hd[0] >> 4) & 15);
	body_len = size - header;
	if (repeat > 1 && body_len * repeat > MAX_BODY) {
		repeat = MAX(1, MAX_BODY / MAX(body_len, 1));
	}
	/* Room in front for the FP limit record (128 paths of 8 segments of at most 7 bytes, about 20 KB) or the FW records. */
	if (plan.fw && !plan.invalid) {
		lead_cap = MAX(lead_cap, plan.records * (rec_bound(&plan) + 1));
	}
	body = malloc(body_len * repeat + lead_cap + 1);
	if (body == NULL) {
		abort();
	}
	if (plan.fw && !plan.invalid) {
		lead = gen_records(body, &plan);
	} else if (plan.limit && plan.paths && !plan.invalid) {
		char *q = limit_emit(body, &plan, 0, -1, ((uint32_t)hd[2] << 8) | hd[3]);

		*q++ = '\n';
		lead = (size_t)(q - body);
	}
	for (i = 0; i < repeat; i++) {
		memcpy(body + lead + i * body_len, data + header, body_len);
	}
	body_len = lead + body_len * repeat;
	if (plan.limit) {
		/* A limit-mode line can be 787 KB of output: a few lines say as much as many. */
		size_t lines = 0;

		for (i = 0; i < body_len && lines < LIMIT_LINES; i++) {
			lines += body[i] == '\n';
		}
		body_len = i;
	}

	call_compile = (shim_call){{&spec, NULL, NULL}, NULL};
	memset(&plan_ret, 0, sizeof(plan_ret));
	zim_JsonFuse_Plan_compile(&call_compile, &plan_ret);
	if (shim_eg.exception != NULL) {
		if (!plan.invalid) {
			size_t len;
			const char *m = obj_str(shim_eg.exception, "message", &len);

			FAIL("Plan::compile refused a valid spec: %.*s", (int)len, m != NULL ? m : "");
		}
		check_plan_failure(&plan);
		release_exception();
		goto out_plan_failed;
	}
	if (plan.invalid) {
		FAIL("Plan::compile accepted an invalid spec");
	}
	zv_plan = plan_ret;
	{
		/*
		 * The writer's reservation, from the writer's own init over the compiled plan, against
		 * SPEC.md section 6 plus the default lengths computed here: each name escaped with its comma,
		 * quotes and colon, each default's exact output, 40 bytes per field, braces and LF.
		 */
		const jf_plan_data *pd = &jf_plan_from_obj(Z_OBJ_P(&plan_ret))->data;
		size_t prefixes = 0, defaults = 0;
		jf_writer w;

		for (i = 0; i < plan.field_count; i++) {
			char name[6 * 1024];
			size_t nlen;

			put_name(name, &plan.fields[i], &nlen);
			prefixes += 4 + nlen;
			defaults += plan.fields[i].has_default ? plan.fields[i].dflt_len : 0;
		}
		memset(&w, 0, sizeof(w));
		jf_writer_init(&w, pd);
		if (pd->prefix_total != prefixes || pd->default_total != defaults
				|| w.overhead != prefixes + defaults + 40 * plan.field_count + 2 || w.bound != plan.cap + w.overhead) {
			FAIL("writer bound: prefixes %zu, defaults %zu, overhead %zu, bound %zu; the reference counts %zu, %zu, %zu, %zu",
				pd->prefix_total, pd->default_total, w.overhead, w.bound, prefixes, defaults,
				prefixes + defaults + 40 * plan.field_count + 2, plan.cap + prefixes + defaults + 40 * plan.field_count + 2);
		}
		jf_writer_release(&w);
		code_overhead = w.overhead;
	}

	/* A clean run, checked against the reference model. */
	memset(&ref, 0, sizeof(ref));
	ref_model(&plan, body, body_len, &ref);
	memset(&golden, 0, sizeof(golden));
	run_once(body, body_len, 0, 0, hd[2], &golden, false);
	reference_decides(&ref, &golden, &plan);
	golden.bytes_read_bound = body_len;
	check_uninitialized_plan();

	/*
	 * Short reads and writes must not change anything observable. Every read here also
	 * counts as a fiber switch, which may change only the flags left on the streams.
	 */
	memset(&shortio, 0, sizeof(shortio));
	switch_on_read = true;
	/* One-byte I/O on a megabyte would only burn time: keep short chunks to shorter bodies and outputs. */
	short_floor = body_len > (1u << 18) || plan.limit ? 4096 : 0;
	run_once(body, body_len,
		chunks[hd[2] & 7] != 0 ? MAX(chunks[hd[2] & 7], short_floor) : 0,
		chunks[hd[3] & 7] != 0 ? MAX(chunks[hd[3] & 7], short_floor) : 0,
		((uint32_t)hd[2] << 16) | hd[3], &shortio, false);
	switch_on_read = false;
	if (!switched) {
		FAIL("short I/O run never read its input");
	}
	same_as_golden(&golden, &shortio, "short I/O");
	free(shortio.out);

	/* A fault: the run must end safely, and the output stay a prefix of the clean one. */
	fault = (fault_kind)(hd[4] % FAULT_KINDS);
	fault_at = 1 + hd[5] % 8;
	if (fault != FAULT_NONE) {
		bool held = fault == FAULT_INPUT_HELD;

		if (fault == FAULT_LOW_MEMORY) {
			/* Includes 0 and very small limits, so the first growth is refused. */
			shim_pg.memory_limit = (zend_long)hd[5] * 4096;
		}
		if (fault == FAULT_COUNTER) {
			/*
			 * counter_overflow needs 2^63 bytes for real, so the limit is lowered: odd, just above
			 * the input, so only the writer's counter can pass it; even, small, so the reader's does.
			 */
			shim_long_max = (hd[5] & 1) != 0 ? (zend_long)body_len + (hd[5] >> 1) : (zend_long)(hd[5] >> 1);
		}
		memset(&faulted, 0, sizeof(faulted));
		run_once(body, body_len, 0, 0, hd[2], &faulted, held);
		shim_long_max = INT64_MAX;
		check_fault(&plan, body, &golden, &faulted);

		if (fault == FAULT_WRITE_OVERCLAIM) {
			same_as_golden(&golden, &faulted, "an over-claiming stream");
		}
		if (fault == FAULT_FLUSH_FAIL_USERLAND) {
			same_as_golden(&golden, &faulted, "a userland flush failure");
		}
		if (fault != FAULT_COUNTER && !is_prefix(faulted.out, faulted.out_len, golden.out, golden.out_len)) {
			FAIL("fault run output is not a prefix of the clean output");
		}
		if (held && (!faulted.threw || faulted.ce != &ce_stream)) {
			FAIL("held input was not refused");
		}
		if (held && strcmp(faulted.exc[0].reason, "stream_in_use") != 0) {
			FAIL("held input refused with reason %s", faulted.exc[0].reason);
		}
		if (faulted.threw && strcmp(faulted.message, "") == 0) {
			FAIL("exception without a message");
		}
		free(faulted.out);
	}

	free(golden.out);
	free(ref.out);
	{
		zend_object *obj = Z_OBJ_P(&plan_ret);
		jf_plan_object *planobj = jf_plan_from_obj(obj);

		obj->handlers->free_obj(obj);
		shim_efree(planobj);
	}
	if (live_blocks != 0 || live_bytes != 0) {
		FAIL("plan or run leaked: %zu bytes, %zu blocks", live_bytes, live_blocks);
	}
	shim_eg.exception = NULL;
	free(body);
	return 0;

out_plan_failed:
	{
		zend_object *obj = Z_OBJ_P(&plan_ret);
		jf_plan_object *planobj = jf_plan_from_obj(obj);

		obj->handlers->free_obj(obj);
		shim_efree(planobj);
	}
	if (live_blocks != 0 || live_bytes != 0) {
		FAIL("failed plan leaked: %zu bytes, %zu blocks", live_bytes, live_blocks);
	}
	shim_eg.exception = NULL;
	free(body);
	return 0;
}
