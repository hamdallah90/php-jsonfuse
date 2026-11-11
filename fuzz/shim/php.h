/*
 * Minimal stand-in for the Zend/PHP headers, so the real src/jf_*.c files compile
 * and run in a libFuzzer process with no PHP runtime. It declares only what those
 * files use. The harness in fuzz/ provides the functions; memory is plain malloc so
 * ASan sees exact bounds, and streams are in-memory fakes with injectable faults.
 */
#ifndef JF_FUZZ_SHIM_PHP_H
#define JF_FUZZ_SHIM_PHP_H

#include <inttypes.h>
#include <math.h>
#include <stdarg.h>
#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/types.h>

typedef int64_t zend_long;
typedef uint64_t zend_ulong;
/* A variable, so the counter_overflow fault can lower it for one run; INT64_MAX otherwise. */
extern zend_long shim_long_max;
#define ZEND_LONG_MAX shim_long_max
#define ZEND_LONG_FMT "%" PRId64
#define MAX_LENGTH_OF_LONG 21

#define ZEND_MM_CHUNK_SIZE ((size_t)2 * 1024 * 1024)
#define ZEND_MM_PAGE_SIZE ((size_t)4 * 1024)

#define zend_always_inline inline __attribute__((always_inline))
#define zend_never_inline __attribute__((noinline))
#define ZEND_ATTRIBUTE_FORMAT(type, idx, first) __attribute__((format(type, idx, first)))
/* Checked in the harness, as in a debug build of PHP. */
#define ZEND_ASSERT(c) do { if (!(c)) abort(); } while (0)
#define ZEND_ATTRIBUTE_UNUSED __attribute__((unused))
#ifndef MAX
# define MAX(a, b) (((a) > (b)) ? (a) : (b))
#endif
#ifndef MIN
# define MIN(a, b) (((a) < (b)) ? (a) : (b))
#endif
#define ZEND_STRL(s) (s), (sizeof(s) - 1)
#define zend_finite(x) isfinite(x)
#define SUCCESS 0

/* Strings, values, arrays. */
typedef struct {
	size_t len;
	char *val;
} zend_string;
#define ZSTR_VAL(s) ((s)->val)
#define ZSTR_LEN(s) ((s)->len)
#define zend_string_equals_literal(s, lit) ((s)->len == sizeof(lit) - 1 && memcmp((s)->val, (lit), sizeof(lit) - 1) == 0)

typedef struct _zend_class_entry zend_class_entry;
typedef struct _zend_object zend_object;
typedef struct _zend_resource zend_resource;
typedef struct _HashTable HashTable;

#define IS_NULL 1
#define IS_FALSE 2
#define IS_TRUE 3
#define IS_LONG 4
#define IS_DOUBLE 5
#define IS_STRING 6
#define IS_ARRAY 7
#define IS_OBJECT 8
#define IS_RESOURCE 9

typedef struct {
	int type;
	union {
		zend_long lval;
		double dval;
		zend_string *str;
		HashTable *arr;
		zend_object *obj;
		zend_resource *res;
	} value;
} zval;

#define Z_TYPE_P(z) ((z)->type)
#define Z_LVAL_P(z) ((z)->value.lval)
#define Z_DVAL_P(z) ((z)->value.dval)
#define Z_STR_P(z) ((z)->value.str)
#define Z_STRVAL_P(z) ((z)->value.str->val)
#define Z_STRLEN_P(z) ((z)->value.str->len)
#define Z_ARRVAL_P(z) ((z)->value.arr)
#define Z_OBJ_P(z) ((z)->value.obj)
#define Z_OBJ(z) ((z).value.obj)
#define Z_RES_P(z) ((z)->value.res)
#define ZVAL_DEREF(z) do { } while (0)

struct _HashTable {
	uint32_t count;
	zend_string **keys;     /* NULL entry: integer key in idx */
	zend_ulong *idx;
	zval *vals;
};
#define zend_hash_num_elements(ht) ((ht)->count)

#define ZEND_HASH_FOREACH_KEY_VAL(ht, _h, _key, _val) do { \
		HashTable *ht__ = (ht); \
		for (uint32_t i__ = 0; i__ < ht__->count; i__++) { \
			_h = ht__->idx[i__]; \
			_key = ht__->keys[i__]; \
			_val = &ht__->vals[i__];
#define ZEND_HASH_FOREACH_STR_KEY_VAL(ht, _key, _val) do { \
		HashTable *ht__ = (ht); \
		for (uint32_t i__ = 0; i__ < ht__->count; i__++) { \
			_key = ht__->keys[i__]; \
			_val = &ht__->vals[i__];
#define ZEND_HASH_FOREACH_VAL(ht, _val) do { \
		HashTable *ht__ = (ht); \
		for (uint32_t i__ = 0; i__ < ht__->count; i__++) { \
			_val = &ht__->vals[i__];
#define ZEND_HASH_FOREACH_END() } } while (0)

/* A list: integer keys 0, 1, 2, ... in order. */
static inline bool zend_array_is_list(const HashTable *ht)
{
	for (uint32_t i = 0; i < ht->count; i++) {
		if (ht->keys[i] != NULL || ht->idx[i] != i) {
			return false;
		}
	}
	return true;
}

/* Objects. */
typedef struct {
	int offset;
	void (*free_obj)(zend_object *obj);
	void *clone_obj;
} zend_object_handlers;

struct _zend_class_entry {
	const char *name;
	zend_object *(*create_object)(zend_class_entry *ce);
};

struct _zend_object {
	zend_class_entry *ce;
	const zend_object_handlers *handlers;
};

#define zend_object_alloc(size, ce) shim_ecalloc(1, (size))
#define zend_object_std_init(obj, class) ((obj)->ce = (class))
#define object_properties_init(obj, class) ((void)0)
#define zend_object_std_dtor(obj) ((void)(obj))
const zend_object_handlers *zend_get_std_object_handlers(void);
void object_init_ex(zval *z, zend_class_entry *ce);
void zend_update_property_long(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, zend_long value);
void zend_update_property_string(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, const char *value);
void zend_update_property_stringl(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, const char *value, size_t value_len);
void zend_update_property(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, zval *value);
zval *zend_read_property(zend_class_entry *ce, zend_object *obj, const char *name, size_t length, bool silent, zval *rv);
void zval_ptr_dtor(zval *z);
void shim_obj_release(zend_object *obj);
#define OBJ_RELEASE(obj) shim_obj_release(obj)
/* The fake has no class hierarchy: every class is final. */
#define instanceof_function(a, b) ((a) == (b))
extern zend_class_entry *zend_ce_exception;

/* Allocator: the harness counts these, so a leak on any path is detected. */
void *shim_emalloc(size_t n);
void *shim_ecalloc(size_t n, size_t size);
void *shim_erealloc(void *p, size_t n);
void shim_efree(void *p);
#define emalloc(n) shim_emalloc(n)
#define ecalloc(n, s) shim_ecalloc((n), (s))
#define erealloc(p, n) shim_erealloc((p), (n))
#define efree(p) shim_efree(p)
void *shim_safe_emalloc(size_t n, size_t size, size_t offset);
#define safe_emalloc(n, s, o) shim_safe_emalloc((n), (s), (o))
char *shim_estrndup(const char *s, size_t n);
#define estrndup(s, n) shim_estrndup((s), (n))
size_t zend_memory_usage(int real);
size_t spprintf(char **out, size_t max, const char *fmt, ...) ZEND_ATTRIBUTE_FORMAT(printf, 3, 4);
size_t zend_vspprintf(char **out, size_t max, const char *fmt, va_list args);

/* Engine state. */
typedef struct {
	volatile bool value;
} zend_atomic_bool;
/* A function, so the harness sees when the core first finds the timeout set. */
bool shim_atomic_bool_load(zend_atomic_bool *b);
#define zend_atomic_bool_load_ex(b) shim_atomic_bool_load(b)

typedef struct {
	zend_object *exception;
	zend_atomic_bool timed_out;
} shim_executor_globals;
typedef struct {
	zend_long memory_limit;
} shim_core_globals;
extern shim_executor_globals shim_eg;
extern shim_core_globals shim_pg;
#define EG(v) (shim_eg.v)
#define PG(v) (shim_pg.v)

/* As in the engine: a throw over a pending exception makes the pending one the new one's previous. */
void zend_throw_exception_internal(zend_object *ex);
void zend_exception_set_previous(zend_object *exception, zend_object *add_previous);
#define zend_is_unwind_exit(ex) ((void)(ex), false)
#define zend_is_graceful_exit(ex) ((void)(ex), false)

/* Module globals in the NTS layout; the harness owns jsonfuse_globals and fakes fiber switches. */
#define ZEND_BEGIN_MODULE_GLOBALS(module_name) typedef struct _zend_##module_name##_globals {
#define ZEND_END_MODULE_GLOBALS(module_name) } zend_##module_name##_globals;
#define ZEND_EXTERN_MODULE_GLOBALS(module_name) extern zend_##module_name##_globals module_name##_globals;
#define ZEND_MODULE_GLOBALS_ACCESSOR(module_name, v) (module_name##_globals.v)

/* No bailout can happen here: nothing in this process longjmps. */
#define zend_try { {
#define zend_catch } if (0) {
#define zend_end_try() } }
#define zend_bailout() abort()

/* Streams. */
#define PHP_STREAM_FLAG_NO_FCLOSE 0x8000

typedef struct {
	unsigned flags;
	bool userspace;
	void *impl;
} php_stream;

struct _zend_resource {
	void *ptr;              /* php_stream, or NULL once closed */
};

#define php_file_le_stream() 1
#define php_file_le_pstream() 2
#define zend_fetch_resource2(res, name, a, b) ((php_stream *)(res)->ptr)
#define php_stream_from_zval(s, z) ((s) = (php_stream *)Z_RES_P(z)->ptr)
#define PHP_STREAM_IS_USERSPACE 1
#define php_stream_is(s, kind) ((s)->userspace && (kind) == PHP_STREAM_IS_USERSPACE)
ssize_t php_stream_read(php_stream *s, char *buf, size_t n);
ssize_t php_stream_write(php_stream *s, const char *buf, size_t n);
int php_stream_flush(php_stream *s);
bool php_stream_eof(php_stream *s);

/* Function entry points and parameter parsing. */
typedef struct {
	zval *args[3];
	zval *self;             /* $this for the exception getters */
} shim_call;

#define ZEND_METHOD(cls, name) void zim_##cls##_##name(shim_call *execute_data, zval *return_value)
#define ZEND_PARSE_PARAMETERS_START(min, max) { int arg__ = 0;
#define Z_PARAM_RESOURCE(dest) (dest) = execute_data->args[arg__++];
#define Z_PARAM_OBJ_OF_CLASS(dest, ce) (dest) = Z_OBJ_P(execute_data->args[arg__++]);
#define Z_PARAM_ARRAY_HT(dest) (dest) = Z_ARRVAL_P(execute_data->args[arg__++]);
#define ZEND_PARSE_PARAMETERS_END() (void)arg__; }
#define RETURN_THROWS() do { if (EG(exception) == NULL) { abort(); } return; } while (0)
#define ZEND_PARSE_PARAMETERS_NONE() (void)execute_data
#define ZEND_THIS (execute_data->self)
/* Shallow: the caller reads the value while the object holds it and never frees it. */
#define RETURN_COPY(v) do { *return_value = *(v); return; } while (0)
#define RETURN_NULL() do { return_value->type = IS_NULL; return; } while (0)
#define RETURN_LONG(l) do { return_value->type = IS_LONG; return_value->value.lval = (l); return; } while (0)
#define RETURN_EMPTY_STRING() do { static zend_string empty__ = {0, (char *)""}; return_value->type = IS_STRING; return_value->value.str = &empty__; return; } while (0)

#endif
