#ifndef JF_ERRORS_H
#define JF_ERRORS_H

#include "php.h"

#include <stdbool.h>

extern zend_class_entry *jf_ce_JsonFuseException;
extern zend_class_entry *jf_ce_PlanException;
extern zend_class_entry *jf_ce_RecordException;
extern zend_class_entry *jf_ce_StreamException;

/* Stable reason codes; jf_reason_names in jf_errors.c spells them. */
typedef enum {
	JF_R_UNKNOWN_OPTION,
	JF_R_MISSING_OPTION,
	JF_R_INVALID_OPTION,
	JF_R_TOO_MANY,
	JF_R_INVALID_NAME,
	JF_R_INVALID_TYPE,
	JF_R_INVALID_VALUE,
	JF_R_INVALID_PATH,
	JF_R_DUPLICATE_NAME,
	JF_R_BLANK_LINE,
	JF_R_RECORD_TOO_LARGE,
	JF_R_MEMORY_LIMIT,
	JF_R_INVALID_JSON,
	JF_R_NOT_OBJECT,
	JF_R_DUPLICATE_KEY,
	JF_R_MISSING_FIELD,
	JF_R_NULL_FIELD,
	JF_R_TYPE_MISMATCH,
	JF_R_WHERE_NOT_SCALAR,
	JF_R_PATH_NOT_OBJECT,
	JF_R_SAME_STREAM,
	JF_R_STREAM_IN_USE,
	JF_R_STREAM_CLOSED,
	JF_R_TIMEOUT,
	JF_R_READ_FAILED,
	JF_R_NO_DATA,
	JF_R_WRITE_FAILED,
	JF_R_FLUSH_FAILED,
	JF_R_COUNTER_OVERFLOW,
	JF_R_INTERNAL_ERROR
} jf_reason;

/* A run's first failure, recorded where it is decided and raised by the runner. */
typedef struct {
	zend_class_entry *ce;       /* NULL: nothing recorded */
	jf_reason reason;
	const char *field;          /* the plan's own name, borrowed from the plan; NULL if none */
	size_t field_length;
	char *message;              /* emalloc; the text after the position prefix */
} jf_failure;

/* Runner position of the current physical line, 1-based line and record, and the recorded failure. */
typedef struct {
	zend_long line;
	zend_long record;
	zend_long offset;
	jf_failure failure;
} jf_pos;

/* Run progress for getProgress(), in Stats property order. */
typedef struct {
	zend_long read;
	zend_long matched;
	zend_long emitted;
	zend_long bytes_read;
	zend_long written;
} jf_progress;

/*
 * Records the run's failure without raising it; a later call while one is recorded
 * is ignored. field (raw bytes, may be NULL) must outlive the run.
 */
void jf_fail(jf_pos *at, zend_class_entry *ce, jf_reason reason, const char *field, size_t field_length,
	const char *fmt, ...) ZEND_ATTRIBUTE_FORMAT(printf, 6, 7);
void jf_failure_release(jf_failure *f);

/*
 * A Record or Stream exception for f at position at, with the given progress; not
 * thrown yet. The message keeps the shape "line N (record R, offset O): reason".
 */
zend_object *jf_failure_exception(const jf_failure *f, const jf_pos *at, const jf_progress *progress);

/* Throws a Stream or JsonFuse exception raised before a run starts: line 1, record 1, offset 0, zero progress. */
void jf_throw_before_run(zend_class_entry *ce, jf_reason reason, const char *message);

/* Throws a PlanException; option and field (raw bytes) may be NULL. */
void jf_throw_plan(jf_reason reason, const char *option, const char *field, size_t field_length, const char *message);

/* False (memory_limit failure recorded) if the request cannot fit memory_limit. */
bool jf_mem_fits(jf_pos *at, size_t request);

#endif
