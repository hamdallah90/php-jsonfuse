#ifndef JF_STREAM_H
#define JF_STREAM_H

#include "php.h"

#include <stdbool.h>

#include "jf_errors.h"

typedef struct {
	zend_resource *in;      /* kept alive by the call frame's argument slots */
	zend_resource *out;
	char *buf;              /* emalloc; contiguous current line, plus YYJSON_PADDING_SIZE tail */
	size_t cap;
	size_t pos;             /* first unconsumed byte */
	size_t fill;            /* end of buffered data */
	size_t scan;            /* next byte to search for LF */
	size_t max_record;
	bool eof;
	jf_pos *at;
} jf_reader;

typedef struct {
	char *text;             /* record bytes in the reader buffer */
	size_t length;          /* JSON text, excluding LF or CRLF */
	size_t consumed;        /* whole physical line, including terminators */
} jf_line;

typedef enum { JF_LINE_OK, JF_LINE_EOF, JF_LINE_ERROR } jf_line_status;

jf_line_status jf_reader_next(jf_reader *r, jf_line *line);
void jf_reader_release(jf_reader *r);

/* NULL (stream_closed recorded) if the resource was closed, e.g. by pclose(). */
php_stream *jf_stream_of(const jf_reader *r, zend_resource *res);
/* Run after every php_stream_* call: those can run PHP and close either stream. */
bool jf_after_stream_call(const jf_reader *r);

#endif
