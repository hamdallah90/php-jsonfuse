#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"

#include "jf_errors.h"
#include "jf_parse.h"

static const yyjson_read_flag jf_read_flags = YYJSON_READ_INSITU | YYJSON_READ_BIGNUM_AS_RAW;

/* jf_writer.c reserves one record length per record, so no reader flag may let an output string
 * outgrow its input token. yyjson's flags are not macros: a forbidden flag makes this divide by 0. */
static const int jf_read_flags_checked ZEND_ATTRIBUTE_UNUSED = 1 / ((jf_read_flags & (YYJSON_READ_JSON5
	| YYJSON_READ_ALLOW_EXT_ESCAPE | YYJSON_READ_ALLOW_SINGLE_QUOTED_STR | YYJSON_READ_ALLOW_INVALID_UNICODE)) == 0);

void jf_parser_init(jf_parser *p, size_t max_record_bytes)
{
	p->bound = yyjson_read_max_memory_usage(max_record_bytes, jf_read_flags);
}

void jf_parser_release(jf_parser *p)
{
	if (p->pool != NULL) {
		efree(p->pool);
	}
}

static bool jf_parser_reserve(jf_parser *p, size_t need, jf_pos *at)
{
	size_t cap = MAX(need, MIN(2 * p->cap, p->bound));

	if (p->pool != NULL) {
		efree(p->pool);
		p->pool = NULL;
		p->cap = 0;
	}
	if (!jf_mem_fits(at, cap)) {
		return false;
	}
	p->pool = emalloc(cap);
	p->cap = cap;
	if (!yyjson_alc_pool_init(&p->alc, p->pool, cap)) {
		jf_fail(at, jf_ce_RecordException, JF_R_INTERNAL_ERROR, NULL, 0, "parser pool setup failed");
		return false;
	}
	return true;
}

bool jf_parser_fit(jf_parser *p, size_t length, jf_pos *at)
{
	size_t need = yyjson_read_max_memory_usage(length, jf_read_flags);

	if (need == 0) {
		jf_fail(at, jf_ce_RecordException, JF_R_RECORD_TOO_LARGE, NULL, 0, "record is too large for the parser");
		return false;
	}
	return need <= p->cap || jf_parser_reserve(p, need, at);
}

yyjson_doc *jf_parse_record(jf_parser *p, char *text, size_t length, jf_pos *at)
{
	char saved[YYJSON_PADDING_SIZE];
	yyjson_read_err err;
	yyjson_doc *doc;

	if (!jf_parser_fit(p, length, at)) {
		return NULL;
	}

	/* In-situ parsing zeroes the padding bytes, which may be the next record's. */
	memcpy(saved, text + length, YYJSON_PADDING_SIZE);
	doc = yyjson_read_opts(text, length, jf_read_flags, &p->alc, &err);
	memcpy(text + length, saved, YYJSON_PADDING_SIZE);

	if (doc != NULL) {
		return doc;
	}
	/* The pool is sized for any valid record, so only an unclosed bracket flood runs out. */
	if (err.code == YYJSON_READ_ERROR_MEMORY_ALLOCATION) {
		jf_fail(at, jf_ce_RecordException, JF_R_INVALID_JSON, NULL, 0, "invalid JSON at byte %zu: too many open containers", err.pos);
	} else {
		jf_fail(at, jf_ce_RecordException, JF_R_INVALID_JSON, NULL, 0, "invalid JSON at byte %zu: %s", err.pos, err.msg);
	}
	return NULL;
}

#ifndef JF_DISABLE_SCAN
/*
 * Validating scan. It accepts a subset of what yyjson_read_opts accepts with jf_read_flags:
 * RFC 8259 syntax, RFC 3629 UTF-8 and yyjson's surrogate-pair rule, nesting up to
 * JF_SCAN_MAX_DEPTH below the root (yyjson allows 510). Anything else, a duplicate referenced
 * key or an escaped root key returns false with the text untouched, and the caller parses the
 * record with yyjson, which reports the exact error. The zero padding written over the
 * YYJSON_PADDING_SIZE bytes after the record stops every loop, so no loop checks the end.
 */
#define JF_SCAN_MAX_DEPTH 64

typedef unsigned char jf_u8;

#define JF_PLAIN(c) (jf_u8)((c) >= 0x20 && (c) < 0x80 && (c) != '"' && (c) != '\\')
#define JF_PLAIN4(c) JF_PLAIN(c), JF_PLAIN((c) + 1), JF_PLAIN((c) + 2), JF_PLAIN((c) + 3)
#define JF_PLAIN16(c) JF_PLAIN4(c), JF_PLAIN4((c) + 4), JF_PLAIN4((c) + 8), JF_PLAIN4((c) + 12)
#define JF_PLAIN64(c) JF_PLAIN16(c), JF_PLAIN16((c) + 16), JF_PLAIN16((c) + 32), JF_PLAIN16((c) + 48)

/* 1 for a byte a string holds as itself: 0x20 to 0x7F except quote and backslash. */
static const jf_u8 jf_plain[256] = { JF_PLAIN64(0), JF_PLAIN64(64), JF_PLAIN64(128), JF_PLAIN64(192) };

static zend_always_inline bool jf_digit(jf_u8 c)
{
	return c >= '0' && c <= '9';
}

/* JSON whitespace: space, tab, LF, CR. One compare rejects every byte above space. */
static zend_always_inline const jf_u8 *jf_skip_ws(const jf_u8 *p)
{
	while (*p <= ' ' && ((uint64_t)1 << *p & ((uint64_t)1 << ' ' | 1 << '\t' | 1 << '\n' | 1 << '\r')) != 0) {
		p++;
	}
	return p;
}

static zend_always_inline int jf_hex(jf_u8 c)
{
	if (c >= '0' && c <= '9') {
		return c - '0';
	}
	c |= 0x20;
	return c >= 'a' && c <= 'f' ? c - 'a' + 10 : -1;
}

/* Four hex digits as a code unit, or -1. Reads p[0..3], which may be padding. */
static zend_always_inline long jf_hex4(const jf_u8 *p)
{
	int a = jf_hex(p[0]), b = jf_hex(p[1]), c = jf_hex(p[2]), d = jf_hex(p[3]);

	if ((a | b | c | d) < 0) {
		return -1;
	}
	return (long)a << 12 | b << 8 | c << 4 | d;
}

/* End of one RFC 3629 sequence starting at a byte >= 0x80, or NULL. */
static zend_always_inline const jf_u8 *jf_scan_utf8(const jf_u8 *p)
{
	jf_u8 c = p[0], lo = 0x80, hi = 0xBF;

	if (c >= 0xC2 && c <= 0xDF) {
		return (p[1] & 0xC0) == 0x80 ? p + 2 : NULL;
	}
	if (c >= 0xE0 && c <= 0xEF) {
		lo = c == 0xE0 ? 0xA0 : lo;
		hi = c == 0xED ? 0x9F : hi;
		return p[1] >= lo && p[1] <= hi && (p[2] & 0xC0) == 0x80 ? p + 3 : NULL;
	}
	if (c >= 0xF0 && c <= 0xF4) {
		lo = c == 0xF0 ? 0x90 : lo;
		hi = c == 0xF4 ? 0x8F : hi;
		return p[1] >= lo && p[1] <= hi && (p[2] & 0xC0) == 0x80 && (p[3] & 0xC0) == 0x80 ? p + 4 : NULL;
	}
	return NULL;
}

/* p is after the opening quote. Returns the byte after the closing quote, or NULL. */
static zend_always_inline const jf_u8 *jf_scan_str(const jf_u8 *p, const jf_u8 *end, bool *escaped)
{
	for (;;) {
		long hi, lo;
		jf_u8 c;

		/*
		 * Skips eight plain bytes at a time while they lie inside the record. A byte's top bit
		 * ends up set if it is >= 0x80 (from w), < 0x20 (w - 0x20) or '"' / '\\' (xor, then - 1).
		 * Borrows start only at a flagged byte and the subtractions flag nothing else below 0x80,
		 * so the lowest flag is the first byte the loop below must look at; on little-endian
		 * targets the scan jumps straight to it, elsewhere the byte loop walks there.
		 */
		while (end - p >= 8) {
			uint64_t w, m;

			memcpy(&w, p, 8);
			m = (w | (w - 0x2020202020202020u) | ((w ^ 0x2222222222222222u) - 0x0101010101010101u)
				| ((w ^ 0x5C5C5C5C5C5C5C5Cu) - 0x0101010101010101u)) & 0x8080808080808080u;
			if (m != 0) {
#if defined(__GNUC__) && defined(__BYTE_ORDER__) && __BYTE_ORDER__ == __ORDER_LITTLE_ENDIAN__
				p += __builtin_ctzll(m) >> 3;
#endif
				break;
			}
			p += 8;
		}
		while (jf_plain[*p]) {
			p++;
		}
		c = *p;
		if (c == '"') {
			return p + 1;
		}
		if (c >= 0x80) {
			p = jf_scan_utf8(p);
			if (p == NULL) {
				return NULL;
			}
			continue;
		}
		if (c != '\\') {
			return NULL;    /* control byte, or the padding: unclosed */
		}
		*escaped = true;
		switch (p[1]) {
			case '"': case '\\': case '/': case 'b': case 'f': case 'n': case 'r': case 't':
				p += 2;
				continue;
			case 'u':
				break;
			default:
				return NULL;
		}
		hi = jf_hex4(p + 2);
		if (hi < 0) {
			return NULL;
		}
		p += 6;
		if ((hi & 0xF800) != 0xD800) {
			continue;
		}
		if ((hi & 0xFC00) != 0xD800 || p[0] != '\\' || p[1] != 'u') {
			return NULL;
		}
		lo = jf_hex4(p + 2);
		if (lo < 0 || (lo & 0xFC00) != 0xDC00) {
			return NULL;
		}
		p += 6;
	}
}

/* Decodes a string jf_scan_str accepted, in place, as yyjson does. Returns the decoded length. */
static size_t jf_unescape(jf_u8 *s)
{
	jf_u8 *src = s, *dst = s;
	unsigned long u, lo;

	for (;;) {
		if (*src == '"') {
			return (size_t)(dst - s);
		}
		if (*src != '\\') {
			*dst++ = *src++;
			continue;
		}
		switch (src[1]) {
			case 'b': *dst++ = '\b'; break;
			case 'f': *dst++ = '\f'; break;
			case 'n': *dst++ = '\n'; break;
			case 'r': *dst++ = '\r'; break;
			case 't': *dst++ = '\t'; break;
			case 'u':
				u = (unsigned long)jf_hex4(src + 2);
				src += 6;
				if ((u & 0xF800) == 0xD800) {
					lo = (unsigned long)jf_hex4(src + 2);
					src += 6;
					u = ((u - 0xD800) << 10 | (lo - 0xDC00)) + 0x10000;
					*dst++ = (jf_u8)(0xF0 | (u >> 18));
					*dst++ = (jf_u8)(0x80 | ((u >> 12) & 0x3F));
					*dst++ = (jf_u8)(0x80 | ((u >> 6) & 0x3F));
					*dst++ = (jf_u8)(0x80 | (u & 0x3F));
				} else if (u >= 0x800) {
					*dst++ = (jf_u8)(0xE0 | (u >> 12));
					*dst++ = (jf_u8)(0x80 | ((u >> 6) & 0x3F));
					*dst++ = (jf_u8)(0x80 | (u & 0x3F));
				} else if (u >= 0x80) {
					*dst++ = (jf_u8)(0xC0 | (u >> 6));
					*dst++ = (jf_u8)(0x80 | (u & 0x3F));
				} else {
					*dst++ = (jf_u8)u;
				}
				continue;
			default: *dst++ = src[1]; break;    /* '"', '\\' or '/' */
		}
		src += 2;
	}
}

static zend_always_inline const jf_u8 *jf_scan_num(const jf_u8 *p)
{
	p += *p == '-';
	if (*p == '0') {
		p++;
	} else if (*p >= '1' && *p <= '9') {
		do {
			p++;
		} while (jf_digit(*p));
	} else {
		return NULL;
	}
	if (*p == '.') {
		if (!jf_digit(*++p)) {
			return NULL;
		}
		do {
			p++;
		} while (jf_digit(*p));
	}
	if (*p == 'e' || *p == 'E') {
		p++;
		p += *p == '+' || *p == '-';
		if (!jf_digit(*p)) {
			return NULL;
		}
		do {
			p++;
		} while (jf_digit(*p));
	}
	return p;
 }

/* One complete value of any type, without recursion: containers are tracked in a bit stack. */
static const jf_u8 *jf_scan_value(const jf_u8 *p, const jf_u8 *end)
{
	uint64_t objects = 0;   /* bit d set: the container at depth d + 1 is an object */
	unsigned depth = 0;
	bool escaped;

	for (;;) {
		switch (*p) {
			case '"':
				p = jf_scan_str(p + 1, end, &escaped);
				break;
			case '{':
			case '[':
				if (depth == JF_SCAN_MAX_DEPTH) {
					return NULL;
				}
				if (*p == '{') {
					objects |= (uint64_t)1 << depth;
				} else {
					objects &= ~((uint64_t)1 << depth);
				}
				depth++;
				p = jf_skip_ws(p + 1);
				if (*p == ((objects >> (depth - 1) & 1) ? '}' : ']')) {
					p++;
					depth--;
					break;
				}
				if (objects >> (depth - 1) & 1) {
					goto key;
				}
				continue;
			case 't':
				p = memcmp(p, "true", 4) == 0 ? p + 4 : NULL;
				break;
			case 'f':
				p = memcmp(p, "false", 5) == 0 ? p + 5 : NULL;
				break;
			case 'n':
				p = memcmp(p, "null", 4) == 0 ? p + 4 : NULL;
				break;
			default:
				p = jf_scan_num(p);
				break;
		}
		/* After a complete value: close containers until one continues. */
		for (;;) {
			bool object;

			if (p == NULL || depth == 0) {
				return p;
			}
			object = objects >> (depth - 1) & 1;
			p = jf_skip_ws(p);
			if (*p == ',') {
				p = jf_skip_ws(p + 1);
				break;
			}
			if (*p != (object ? '}' : ']')) {
				return NULL;
			}
			p++;
			depth--;
		}
		if (!(objects >> (depth - 1) & 1)) {
			continue;
		}
key:
		if (*p != '"') {
			return NULL;
		}
		p = jf_scan_str(p + 1, end, &escaped);
		if (p == NULL) {
			return NULL;
		}
		p = jf_skip_ws(p);
		if (*p != ':') {
			return NULL;
		}
		p = jf_skip_ws(p + 1);
	}
}

static bool jf_scan(const jf_plan_data *plan, const uint64_t *filter, jf_u8 *text, const jf_u8 *end, yyjson_val **values, yyjson_val *vals,
	const yyjson_alc *alc)
{
	jf_u8 *pending[JF_MAX_FIELDS + JF_MAX_FILTERS];        /* escaped referenced strings, decoded once the record is valid */
	yyjson_val *pending_val[JF_MAX_FIELDS + JF_MAX_FILTERS];
	size_t npending = 0, i;
	const jf_u8 *p = jf_skip_ws(text);
	yyjson_read_err err;

	memset(values, 0, (plan->node_count + 1) * sizeof(values[0]));
	if (*p != '{') {
		return false;
	}
	p = jf_skip_ws(p + 1);
	if (*p != '}') {
		for (;;) {
			const jf_u8 *key = p + 1, *v;
			bool escaped = false;
			yyjson_val *val;
			uint32_t bit;
			uint16_t id = 0;

			if (*p != '"') {
				return false;
			}
			p = jf_scan_str(key, end, &escaped);
			if (p == NULL || escaped) {
				return false;
			}
			bit = jf_key_filter_bit((const char *)key, (size_t)(p - 1 - key));
			if ((filter[bit >> 6] >> (bit & 63) & 1) != 0) {
				id = jf_root_find(plan, (const char *)key, (size_t)(p - 1 - key));
			}
			p = jf_skip_ws(p);
			if (*p != ':') {
				return false;
			}
			v = p = jf_skip_ws(p + 1);
			val = NULL;
			if (id != 0) {
				if (values[id] != NULL) {
					return false;   /* duplicate: the yyjson path reports it in document order */
				}
				val = values[id] = &vals[id];
			}
			switch (*p) {
				case '"':
					escaped = false;
					p = jf_scan_str(p + 1, end, &escaped);
					if (p == NULL) {
						return false;
					}
					if (val == NULL) {
						break;
					}
					if (escaped) {
						pending[npending] = text + (v + 1 - text);
						pending_val[npending++] = val;
					} else {
						/* Unlike yyjson, which tags a string without escapes NOESC, a scanned string keeps
						 * subtype NONE; no consumer reads the subtype. */
						unsafe_yyjson_set_strn(val, (const char *)v + 1, (size_t)(p - 1 - (v + 1)));
					}
					break;
				case '{':
				case '[':
					p = jf_scan_value(p, end);
					if (p == NULL) {
						return false;
					}
					/* A referenced container gets length 0, not its member count as from yyjson:
					 * the runner only reads its type, to refuse it. */
					if (val != NULL && *v == '{') {
						unsafe_yyjson_set_obj(val, 0);
					} else if (val != NULL) {
						unsafe_yyjson_set_arr(val, 0);
					}
					break;
				case 't':
					if (memcmp(p, "true", 4) != 0) {
						return false;
					}
					p += 4;
					if (val != NULL) {
						unsafe_yyjson_set_bool(val, true);
					}
					break;
				case 'f':
					if (memcmp(p, "false", 5) != 0) {
						return false;
					}
					p += 5;
					if (val != NULL) {
						unsafe_yyjson_set_bool(val, false);
					}
					break;
				case 'n':
					if (memcmp(p, "null", 4) != 0) {
						return false;
					}
					p += 4;
					if (val != NULL) {
						unsafe_yyjson_set_null(val);
					}
					break;
				default:
					p = jf_scan_num(p);
					if (p == NULL) {
						return false;
					}
					/*
					 * yyjson's own number reader, so the value and its subtype are exactly yyjson's.
					 * A RAW number takes the yyjson path: its token would not be NUL-terminated here.
					 */
					if (val != NULL && ((const jf_u8 *)yyjson_read_number((const char *)v, val,
							YYJSON_READ_BIGNUM_AS_RAW, alc, &err) != p || unsafe_yyjson_is_raw(val))) {
						return false;
					}
					break;
			}
			p = jf_skip_ws(p);
			if (*p == ',') {
				p = jf_skip_ws(p + 1);
				continue;
			}
			if (*p != '}') {
				return false;
			}
			break;
		}
	}
	if (jf_skip_ws(p + 1) != end) {
		return false;
	}
	for (i = 0; i < npending; i++) {
		unsafe_yyjson_set_strn(pending_val[i], (const char *)pending[i], jf_unescape(pending[i]));
	}
	return true;
}

#ifdef JF_SCAN_STATS
/* Test builds only (-DJF_SCAN_STATS): records the scan accepted in this thread, shown as a module
 * info row so that tests notice a scan that silently hands every record to yyjson. */
ZEND_TLS uint64_t jf_scan_accepted;

uint64_t jf_scan_accepted_count(void)
{
	return jf_scan_accepted;
}
#endif

bool jf_scan_record(const jf_plan_data *plan, const uint64_t *filter, char *text, size_t length,
	yyjson_val **values, yyjson_val *vals, const yyjson_alc *alc)
{
	char saved[YYJSON_PADDING_SIZE];
	bool ok;

	memcpy(saved, text + length, YYJSON_PADDING_SIZE);
	memset(text + length, 0, YYJSON_PADDING_SIZE);
	/* jf_scan writes to the text (the in-place unescape of pending strings) only after its last
	 * check has passed, so a false return leaves the record as read for the yyjson path. The order
	 * is load-bearing: a record the scan accepted must never go to yyjson as well. */
	ok = jf_scan(plan, filter, (jf_u8 *)text, (const jf_u8 *)text + length, values, vals, alc);
	memcpy(text + length, saved, YYJSON_PADDING_SIZE);
#ifdef JF_SCAN_STATS
	jf_scan_accepted += ok;
#endif
	return ok;
}
#endif
