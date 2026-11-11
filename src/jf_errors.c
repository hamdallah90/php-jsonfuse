#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "php_globals.h"
#include "zend_exceptions.h"

#include "jf_errors.h"
#include "jf_runner.h"

zend_class_entry *jf_ce_JsonFuseException;
zend_class_entry *jf_ce_PlanException;
zend_class_entry *jf_ce_RecordException;
zend_class_entry *jf_ce_StreamException;

static const char *const jf_reason_names[] = {
	[JF_R_UNKNOWN_OPTION] = "unknown_option",
	[JF_R_MISSING_OPTION] = "missing_option",
	[JF_R_INVALID_OPTION] = "invalid_option",
	[JF_R_TOO_MANY] = "too_many",
	[JF_R_INVALID_NAME] = "invalid_name",
	[JF_R_INVALID_TYPE] = "invalid_type",
	[JF_R_INVALID_VALUE] = "invalid_value",
	[JF_R_INVALID_PATH] = "invalid_path",
	[JF_R_DUPLICATE_NAME] = "duplicate_name",
	[JF_R_BLANK_LINE] = "blank_line",
	[JF_R_RECORD_TOO_LARGE] = "record_too_large",
	[JF_R_MEMORY_LIMIT] = "memory_limit",
	[JF_R_INVALID_JSON] = "invalid_json",
	[JF_R_NOT_OBJECT] = "not_object",
	[JF_R_DUPLICATE_KEY] = "duplicate_key",
	[JF_R_MISSING_FIELD] = "missing_field",
	[JF_R_NULL_FIELD] = "null_field",
	[JF_R_TYPE_MISMATCH] = "type_mismatch",
	[JF_R_WHERE_NOT_SCALAR] = "where_not_scalar",
	[JF_R_PATH_NOT_OBJECT] = "path_not_object",
	[JF_R_SAME_STREAM] = "same_stream",
	[JF_R_STREAM_IN_USE] = "stream_in_use",
	[JF_R_STREAM_CLOSED] = "stream_closed",
	[JF_R_TIMEOUT] = "timeout",
	[JF_R_READ_FAILED] = "read_failed",
	[JF_R_NO_DATA] = "no_data",
	[JF_R_WRITE_FAILED] = "write_failed",
	[JF_R_FLUSH_FAILED] = "flush_failed",
	[JF_R_COUNTER_OVERFLOW] = "counter_overflow",
	[JF_R_INTERNAL_ERROR] = "internal_error",
};

void jf_fail(jf_pos *at, zend_class_entry *ce, jf_reason reason, const char *field, size_t field_length,
	const char *fmt, ...)
{
	va_list args;

	if (at->failure.ce != NULL) {
		return;
	}
	at->failure.ce = ce;
	at->failure.reason = reason;
	at->failure.field = field;
	at->failure.field_length = field_length;
	va_start(args, fmt);
	zend_vspprintf(&at->failure.message, 0, fmt, args);
	va_end(args);
}

void jf_failure_release(jf_failure *f)
{
	if (f->message != NULL) {
		efree(f->message);
	}
	memset(f, 0, sizeof(*f));
}

/* Message, code 0 and reason; built whole before any throw, so a dropped throw frees it cleanly. */
static zend_object *jf_exception_new(zend_class_entry *ce, jf_reason reason, const char *message)
{
	zval ex;

	object_init_ex(&ex, ce);
	zend_update_property_string(zend_ce_exception, Z_OBJ(ex), ZEND_STRL("message"), message);
	zend_update_property_string(jf_ce_JsonFuseException, Z_OBJ(ex), ZEND_STRL("reason"), jf_reason_names[reason]);
	return Z_OBJ(ex);
}

static void jf_exception_set_field(zend_object *ex, const char *field, size_t field_length)
{
	if (field != NULL) {
		zend_update_property_stringl(jf_ce_JsonFuseException, ex, ZEND_STRL("field"), field, field_length);
	}
}

static void jf_exception_set_position(zend_object *ex, const jf_pos *at, const jf_progress *progress)
{
	zend_class_entry *scope = instanceof_function(ex->ce, jf_ce_RecordException) ? jf_ce_RecordException : jf_ce_StreamException;
	zval stats;

	zend_update_property_long(scope, ex, ZEND_STRL("inputLine"), at->line);
	zend_update_property_long(scope, ex, ZEND_STRL("recordNumber"), at->record);
	zend_update_property_long(scope, ex, ZEND_STRL("byteOffset"), at->offset);
	jf_stats_new(&stats, progress);
	zend_update_property(scope, ex, ZEND_STRL("progress"), &stats);
	zval_ptr_dtor(&stats);
}

zend_object *jf_failure_exception(const jf_failure *f, const jf_pos *at, const jf_progress *progress)
{
	char *message;
	zend_object *ex;

	spprintf(&message, 0, "line " ZEND_LONG_FMT " (record " ZEND_LONG_FMT ", offset " ZEND_LONG_FMT "): %s",
		at->line, at->record, at->offset, f->message);
	ex = jf_exception_new(f->ce, f->reason, message);
	efree(message);
	jf_exception_set_field(ex, f->field, f->field_length);
	if (f->ce != jf_ce_JsonFuseException) {
		jf_exception_set_position(ex, at, progress);
	}
	return ex;
}

void jf_throw_before_run(zend_class_entry *ce, jf_reason reason, const char *message)
{
	static const jf_pos start = {1, 1, 0, {0}};
	static const jf_progress zero = {0};
	zend_object *ex = jf_exception_new(ce, reason, message);

	if (ce != jf_ce_JsonFuseException) {
		jf_exception_set_position(ex, &start, &zero);
	}
	zend_throw_exception_internal(ex);
}

void jf_throw_plan(jf_reason reason, const char *option, const char *field, size_t field_length, const char *message)
{
	zend_object *ex = jf_exception_new(jf_ce_PlanException, reason, message);

	jf_exception_set_field(ex, field, field_length);
	if (option != NULL) {
		zend_update_property_string(jf_ce_PlanException, ex, ZEND_STRL("option"), option);
	}
	zend_throw_exception_internal(ex);
}

/*
 * The properties are typed and private, so only an unset one (through a bound closure)
 * reads as another type here; each getter then returns its documented default.
 */
static zval *jf_property(zend_object *obj, zend_class_entry *scope, const char *name, size_t length, zval *rv)
{
	zval *v = zend_read_property(scope, obj, name, length, 1, rv);

	ZVAL_DEREF(v);
	return v;
}

ZEND_METHOD(JsonFuse_JsonFuseException, getReason)
{
	zval rv, *v;

	ZEND_PARSE_PARAMETERS_NONE();
	v = jf_property(Z_OBJ_P(ZEND_THIS), jf_ce_JsonFuseException, ZEND_STRL("reason"), &rv);
	if (Z_TYPE_P(v) == IS_STRING) {
		RETURN_COPY(v);
	}
	RETURN_EMPTY_STRING();
}

ZEND_METHOD(JsonFuse_JsonFuseException, getField)
{
	zval rv, *v;

	ZEND_PARSE_PARAMETERS_NONE();
	v = jf_property(Z_OBJ_P(ZEND_THIS), jf_ce_JsonFuseException, ZEND_STRL("field"), &rv);
	if (Z_TYPE_P(v) == IS_STRING) {
		RETURN_COPY(v);
	}
	RETURN_NULL();
}

ZEND_METHOD(JsonFuse_PlanException, getOption)
{
	zval rv, *v;

	ZEND_PARSE_PARAMETERS_NONE();
	v = jf_property(Z_OBJ_P(ZEND_THIS), jf_ce_PlanException, ZEND_STRL("option"), &rv);
	if (Z_TYPE_P(v) == IS_STRING) {
		RETURN_COPY(v);
	}
	RETURN_NULL();
}

/* RecordException and StreamException declare the same private properties and share these methods. */
static zend_long jf_position_long(zend_object *obj, const char *name, size_t length)
{
	zend_class_entry *scope = instanceof_function(obj->ce, jf_ce_RecordException) ? jf_ce_RecordException : jf_ce_StreamException;
	zval rv, *v = jf_property(obj, scope, name, length, &rv);

	return Z_TYPE_P(v) == IS_LONG ? Z_LVAL_P(v) : 0;
}

ZEND_METHOD(JsonFuse_RecordException, getInputLine)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(jf_position_long(Z_OBJ_P(ZEND_THIS), ZEND_STRL("inputLine")));
}

ZEND_METHOD(JsonFuse_RecordException, getRecordNumber)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(jf_position_long(Z_OBJ_P(ZEND_THIS), ZEND_STRL("recordNumber")));
}

ZEND_METHOD(JsonFuse_RecordException, getByteOffset)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_LONG(jf_position_long(Z_OBJ_P(ZEND_THIS), ZEND_STRL("byteOffset")));
}

/* An instance made with new holds no progress and reports a Stats of zeros. */
ZEND_METHOD(JsonFuse_RecordException, getProgress)
{
	static const jf_progress zero = {0};
	zend_object *obj;
	zval rv, *v;

	ZEND_PARSE_PARAMETERS_NONE();
	obj = Z_OBJ_P(ZEND_THIS);
	v = jf_property(obj, instanceof_function(obj->ce, jf_ce_RecordException) ? jf_ce_RecordException : jf_ce_StreamException,
		ZEND_STRL("progress"), &rv);
	if (Z_TYPE_P(v) == IS_OBJECT) {
		RETURN_COPY(v);
	}
	jf_stats_new(return_value, &zero);
}

bool jf_mem_fits(jf_pos *at, size_t request)
{
	size_t real = zend_memory_usage(1);
	size_t used = real;
	size_t charge = (request + (ZEND_MM_CHUNK_SIZE - 1)) & ~(ZEND_MM_CHUNK_SIZE - 1);
	size_t limit = (size_t)PG(memory_limit);
	bool no_contiguous_run = false;

	/*
	 * Limit: a request a chunk can satisfy comes out of free pages, so it is
	 * compared unrounded against allocated bytes. ZendMM needs those pages
	 * contiguous, so with no headroom for another chunk a request above half the
	 * free space is refused as well. This is a heuristic: heavy fragmentation can
	 * still cross the soft limit and end in PHP's normal memory fatal. An exact
	 * answer needs ZendMM's private free-page map.
	 */
	if (request < ZEND_MM_CHUNK_SIZE - ZEND_MM_PAGE_SIZE) {
		used = zend_memory_usage(0);
		charge = request;
		no_contiguous_run = real + ZEND_MM_CHUNK_SIZE > limit && request > (real - used) / 2;
	}
	if (PG(memory_limit) != -1 && (no_contiguous_run || used + charge > limit)) {
		jf_fail(at, jf_ce_RecordException, JF_R_MEMORY_LIMIT, NULL, 0, "record needs more memory than memory_limit allows");
		return false;
	}
	return true;
}
