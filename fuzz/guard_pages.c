/*
 * Guard-page harness for the validating scan (jf_scan_record, src/jf_parse.c). Each record is
 * copied so that its YYJSON_PADDING_SIZE padding bytes end where a PROT_NONE page starts: a read
 * of even one byte past the padding stops the process with SIGSEGV, which ASan's redzones may miss
 * for reads done a word at a time. yyjson gets the same record on its own guard page, and the two
 * must agree:
 *   - a record the scan accepts is an object yyjson accepts, with the same referenced values;
 *   - a record the scan refuses is left unchanged, for yyjson to read and report;
 *   - the padding bytes hold what they held before the scan.
 *
 * Usage: guard_pages <records> <seed>     generated records (truncations, bad escapes, bad UTF-8)
 *        guard_pages -f <file>...         every line of each file as one record
 * Prints one summary line; exits 1 on a disagreement, 2 on a read past the padding.
 */
#include "php.h"
#include "jf_errors.h"
#include "jf_parse.h"
#include "jf_plan.h"

#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/mman.h>
#include <unistd.h>

/* The parts of the Zend layer and of jf_errors.c that jf_parse.c links against. */
zend_long shim_long_max = INT64_MAX;
zend_class_entry *jf_ce_RecordException;
void *shim_emalloc(size_t n) { return malloc(n); }
void shim_efree(void *p) { free(p); }
void jf_fail(jf_pos *at, zend_class_entry *ce, jf_reason reason, const char *field, size_t field_length, const char *fmt, ...)
{
	(void)at; (void)ce; (void)reason; (void)field; (void)field_length; (void)fmt;
}
bool jf_mem_fits(jf_pos *at, size_t request) { (void)at; (void)request; return true; }

#define MAX_RECORD 65536
#define PAD YYJSON_PADDING_SIZE
#define NKEYS 8

static const char *const keys[NKEYS] = {"a", "s", "n", "", "\xc3\xa9", "a\0b", "k1", "a_longer_key_name"};
static const size_t key_lengths[NKEYS] = {1, 1, 1, 0, 2, 3, 2, 17};
static jf_node nodes[NKEYS + 1];
static jf_plan_data plan;
static uint64_t filter[JF_KEY_FILTER_BITS / 64];

static char rec[MAX_RECORD];
static size_t len;
static volatile long current = -1;

static uint64_t rng = 0x9E3779B97F4A7C15ull;
static unsigned pick(unsigned n)
{
	rng ^= rng << 13;
	rng ^= rng >> 7;
	rng ^= rng << 17;
	return (unsigned)(rng % n);
}
static void add(const char *s, size_t n)
{
	if (len + n <= MAX_RECORD - 64) {
		memcpy(rec + len, s, n);
		len += n;
	}
}
static void adds(const char *s) { add(s, strlen(s)); }
static void ws(void) { static const char *const w[] = {"", "", "", " ", "\t", "\r", "\n", "  "}; adds(w[pick(8)]); }

static void gen_string(void)
{
	static const char *const part[] = {"x", "abc", "\\n", "\\\"", "\\\\", "\\/", "\\u0041", "\\u00e9", "\\uD83D\\uDE00", "\\u0000",
		"\xc3\xa9", "\xe2\x82\xac", "\xf0\x9f\x98\x80", "\xf4\x8f\xbf\xbf", "\x7f", "wwwwwwwwwwwwwww", "\\ud800", "\\udc00",
		"\\ud800A", "\\ud800\\ud800", "\\ud800Xudc00", "\\ud800\\tdc00", "\xff", "\xc0\x80", "\xed\xa0\x80", "\xf4\x90\x80\x80",
		"\xe2\x82", "\x01", "\\x", "\\u12g4"};
	int n = (int)pick(6);

	add("\"", 1);
	while (n-- > 0) {
		if (pick(12) == 0) {
			add("\0", 1);
		} else {
			adds(part[pick(sizeof part / sizeof *part)]);
		}
	}
	if (pick(40)) {
		add("\"", 1);
	}
}

static void gen_value(int depth)
{
	static const char *const num[] = {"0", "-0", "1", "-1", "42", "01", "1.", "-", ".5", "1e", "1E-5", "1.5", "9223372036854775807",
		"9223372036854775808", "18446744073709551616", "-9223372036854775809", "1e400", "5e-324", "123456789012345678901234567890"};
	static const char *const lit[] = {"true", "false", "null", "tru", "fals", "nul", "truex"};
	unsigned k = pick(20);
	int i, n;

	if (k < 5) {
		gen_string();
	} else if (k < 10) {
		adds(num[pick(sizeof num / sizeof *num)]);
	} else if (k < 13) {
		adds(lit[pick(sizeof lit / sizeof *lit)]);
	} else if (depth > 3) {
		adds("0");
	} else {
		bool object = k >= 17;
		add(object ? "{" : "[", 1);
		for (i = 0, n = (int)pick(4); i < n; i++) {
			if (i) {
				add(",", 1);
			}
			ws();
			if (object) {
				gen_string();
				ws();
				add(":", 1);
				ws();
			}
			gen_value(depth + 1);
			ws();
		}
		if (pick(40)) {
			add(object ? "}" : "]", 1);
		}
	}
}

static void gen_record(void)
{
	static const char *const tail[] = {"\\", "\\u", "\\u0", "\\uD8", "\\uD800", "\\uD800\\", "\\uD800\\u", "\\uD800\\uDC",
		"\xf0", "\xf0\x9f", "\xf0\x9f\x98", "f", "fa", "fals", "t", "n", "\"", "1e", "-"};
	int i, n = (int)pick(7);

	len = 0;
	ws();
	add("{", 1);
	for (i = 0; i < n; i++) {
		if (i) {
			add(",", 1);
		}
		ws();
		if (pick(3) == 0) {
			int k = (int)pick(NKEYS);
			add("\"", 1);
			add(keys[k], key_lengths[k]);
			add("\"", 1);
		} else {
			gen_string();
		}
		ws();
		add(":", 1);
		ws();
		gen_value(0);
		ws();
	}
	add("}", 1);
	ws();
	switch (pick(8)) {
		case 0:     /* cut anywhere */
			len = pick((unsigned)len + 1);
			break;
		case 1:     /* end inside a string, an escape, a UTF-8 sequence or a literal: the reads nearest the padding */
			len = 0;
			adds(pick(2) ? "{\"a\":\"" : "{\"s\":");
			adds(tail[pick(sizeof tail / sizeof *tail)]);
			break;
		case 2:
			if (len) {
				rec[pick((unsigned)len)] = (char)pick(256);
			}
			break;
	}
}

static void on_fault(int sig)
{
	char msg[96];
	int n = snprintf(msg, sizeof msg, "READ PAST THE PADDING at record %ld (signal %d)\n", current, sig);
	(void)!write(2, msg, (size_t)n);
	_exit(2);
}

/* PROT_NONE page after a writable area of MAX_RECORD + PAD bytes rounded up to pages; returns the guard. */
static char *guarded_area(void)
{
	size_t page = (size_t)sysconf(_SC_PAGESIZE), size = (MAX_RECORD + PAD + page - 1) / page * page;
	char *m = mmap(NULL, size + page, PROT_READ | PROT_WRITE, MAP_PRIVATE | MAP_ANONYMOUS, -1, 0);

	if (m == MAP_FAILED || mprotect(m + size, page, PROT_NONE) != 0) {
		perror("guard page");
		exit(1);
	}
	return m + size;
}

static yyjson_val *find(yyjson_val *root, const char *key, size_t length)
{
	yyjson_val *k, *v;
	size_t i, max;

	yyjson_obj_foreach(root, i, max, k, v) {
		if (unsafe_yyjson_get_len(k) == length && memcmp(unsafe_yyjson_get_str(k), key, length) == 0) {
			return v;
		}
	}
	return NULL;
}

static int fail(const char *what)
{
	printf("%s at record %ld: ", what, current);
	fwrite(rec, 1, len, stdout);
	printf("\n");
	return 1;
}

static long accepted, refused;

/* One record through both parsers, each on its own guard page. */
static int check(char *scan_end, char *yy_end)
{
	yyjson_val *values[NKEYS + 1], vals[NKEYS + 1];
	char *s = scan_end - PAD - len, *y = yy_end - PAD - len;
	yyjson_read_err err;
	yyjson_doc *doc;
	bool ok;
	int i, rc = 0;

	memcpy(s, rec, len);
	memset(s + len, '"', PAD);
	memcpy(y, rec, len);
	memset(y + len, 0, PAD);
	ok = jf_scan_record(&plan, filter, s, len, values, vals, NULL);
	doc = yyjson_read_opts(y, len, YYJSON_READ_INSITU | YYJSON_READ_BIGNUM_AS_RAW, NULL, &err);
	if (memcmp(s + len, "\"\"\"\"", PAD) != 0) {
		rc = fail("PADDING CHANGED");
	} else if (!ok) {
		refused++;
		if (memcmp(s, rec, len) != 0) {
			rc = fail("TEXT CHANGED ON REFUSAL");
		}
	} else if (accepted++, doc == NULL || !yyjson_is_obj(yyjson_doc_get_root(doc))) {
		rc = fail("SCAN ACCEPTED WHAT YYJSON REFUSES");
	} else {
		for (i = 1; i <= NKEYS && rc == 0; i++) {
			yyjson_val *a = values[i], *b = find(yyjson_doc_get_root(doc), keys[i - 1], key_lengths[i - 1]);

			/* containers carry their type only, and a string's subtype is a hint: compare the type, and
			   the subtype of numbers and literals */
			if ((a == NULL) != (b == NULL) || (a && (yyjson_get_type(a) != yyjson_get_type(b)
					|| (!yyjson_is_str(a) && !yyjson_is_ctn(a) && yyjson_get_subtype(a) != yyjson_get_subtype(b))))) {
				rc = fail("PRESENCE OR TYPE DIFFERS");
			} else if (a && yyjson_is_str(a) && (unsafe_yyjson_get_len(a) != unsafe_yyjson_get_len(b)
					|| memcmp(unsafe_yyjson_get_str(a), unsafe_yyjson_get_str(b), unsafe_yyjson_get_len(a)) != 0)) {
				rc = fail("STRING DIFFERS");
			} else if (a && yyjson_is_num(a) && memcmp(&a->uni, &b->uni, sizeof a->uni) != 0) {
				rc = fail("NUMBER DIFFERS");
			}
		}
	}
	yyjson_doc_free(doc);
	return rc;
}

int main(int argc, char **argv)
{
	char *scan_end = guarded_area(), *yy_end = guarded_area();
	long count = 0;
	int i;

	signal(SIGSEGV, on_fault);
	signal(SIGBUS, on_fault);
	plan.node_count = NKEYS;
	plan.nodes = nodes;
	plan.flat = true;
	for (i = 1; i <= NKEYS; i++) {
		uint32_t h = jf_key_hash(0, keys[i - 1], key_lengths[i - 1]) & (JF_ROOT_SLOTS - 1), bit;

		nodes[i].key = keys[i - 1];
		nodes[i].key_length = key_lengths[i - 1];
		while (plan.root_slots[h] != 0) {
			h = (h + 1) & (JF_ROOT_SLOTS - 1);
		}
		plan.root_slots[h] = (uint16_t)i;
		bit = jf_key_filter_bit(keys[i - 1], key_lengths[i - 1]);
		filter[bit >> 6] |= (uint64_t)1 << (bit & 63);
	}

	if (argc >= 3 && strcmp(argv[1], "-f") == 0) {
		for (i = 2; i < argc; i++) {
			FILE *f = fopen(argv[i], "rb");
			int c;

			if (f == NULL) {
				perror(argv[i]);
				return 1;
			}
			do {
				len = 0;
				while ((c = getc(f)) != EOF && c != '\n') {
					if (len < MAX_RECORD - 64) {
						rec[len++] = (char)c;
					}
				}
				if (len > 0 || c == '\n') {
					current = count++;
					if (check(scan_end, yy_end)) {
						return 1;
					}
				}
			} while (c != EOF);
			fclose(f);
		}
	} else if (argc == 3) {
		long n = atol(argv[1]);

		rng ^= (uint64_t)atol(argv[2]) * 0xD6E8FEB86659FD93ull;
		for (count = 0; count < n; count++) {
			current = count;
			gen_record();
			if (check(scan_end, yy_end)) {
				return 1;
			}
		}
	} else {
		fprintf(stderr, "usage: guard_pages <records> <seed> | guard_pages -f <file>...\n");
		return 1;
	}
	printf("records=%ld scan_accepted=%ld scan_refused=%ld, no read past the padding\n", count, accepted, refused);
	return 0;
}
