#ifndef JF_PARSE_H
#define JF_PARSE_H

#include "yyjson.h"

#include "jf_errors.h"
#include "jf_plan.h"

typedef struct {
	char *pool;             /* emalloc; one yyjson pool, reused across records */
	size_t cap;
	size_t bound;           /* pool size that fits a maxRecordBytes record */
	yyjson_alc alc;
} jf_parser;

void jf_parser_init(jf_parser *p, size_t max_record_bytes);
void jf_parser_release(jf_parser *p);

/* Grows the pool for a record of length bytes, as jf_parse_record does first. False means a failure was recorded. */
bool jf_parser_fit(jf_parser *p, size_t length, jf_pos *at);

#ifndef JF_DISABLE_SCAN
/* Bits in a run's key filter: a power of two, so few unreferenced keys share a bit with a plan key. */
#define JF_KEY_FILTER_BITS 4096

/*
 * Bit of a root key in a run's key filter, from its length and edge bytes only: O(1) per
 * record key. A clear bit proves no node of the plan has this key, so only keys that share
 * a bit pay for jf_root_find. The filter lives in the run, on the C stack, so the Plan
 * object and every memory_limit check stay as they are without the scan.
 */
static zend_always_inline uint32_t jf_key_filter_bit(const char *key, size_t length)
{
	uint32_t h = (uint32_t)length;

	if (length > 0) {
		h ^= (uint32_t)(unsigned char)key[0] << 8 ^ (uint32_t)(unsigned char)key[length - 1] << 16;
	}
	return (h * 2654435761u) >> (32 - 12);
}

/*
 * Validates the record of a flat plan and, for each node present at the root, points
 * values[node] at vals[node] holding the value yyjson would produce (containers carry only
 * their type). Same padding contract as jf_parse_record. False means "parse with yyjson
 * instead": the record is invalid, has a duplicate referenced key or uses something the scan
 * leaves to yyjson; text is unchanged and no failure is recorded.
 */
bool jf_scan_record(const jf_plan_data *plan, const uint64_t *filter, char *text, size_t length,
	yyjson_val **values, yyjson_val *vals, const yyjson_alc *alc);
#ifdef JF_SCAN_STATS
uint64_t jf_scan_accepted_count(void);
#endif
#endif

/*
 * Parses one record in place. text must have YYJSON_PADDING_SIZE writable bytes
 * after it. NULL means a failure was recorded; otherwise free the document with
 * yyjson_doc_free() before the next call.
 */
yyjson_doc *jf_parse_record(jf_parser *p, char *text, size_t length, jf_pos *at);

#endif
