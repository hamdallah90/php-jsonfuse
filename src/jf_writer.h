#ifndef JF_WRITER_H
#define JF_WRITER_H

#include "php.h"

#include <stdbool.h>

#include "yyjson.h"

#include "jf_plan.h"
#include "jf_stream.h"

typedef struct {
	const jf_plan_data *plan;
	char *buf;              /* emalloc; complete records not yet written */
	size_t cap;
	size_t fill;
	size_t overhead;        /* record reservation beyond the record's own length */
	size_t bound;           /* capacity that fits a maxRecordBytes record */
	zend_long records;      /* complete records in buf */
	zend_long written;      /* bytes the output stream accepted */
	zend_long emitted;      /* complete lines among them */
} jf_writer;

void jf_writer_init(jf_writer *w, const jf_plan_data *plan);
void jf_writer_release(jf_writer *w);

/*
 * Serializes one validated record whose JSON text is rec_len bytes. It may first
 * write out earlier records, which runs PHP. False means a failure was recorded or
 * a PHP exception is pending.
 */
bool jf_writer_record(jf_writer *w, const jf_reader *r, yyjson_val *const *values, size_t rec_len, const yyjson_alc *alc);

/*
 * Writes the buffered records with the write loop and no flush. On failure the
 * accepted bytes and the complete lines among them are still counted.
 */
bool jf_writer_drain(jf_writer *w, const jf_reader *r);

/* Writes the buffered records and, if the run wrote anything, flushes once. */
bool jf_writer_finish(jf_writer *w, const jf_reader *r);

#endif
