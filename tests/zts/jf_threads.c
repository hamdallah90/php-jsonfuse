/*
 * Multi-thread harness for ZTS builds.
 *
 * Links libphp built with --enable-zts --enable-embed (a test-time artifact, not a runtime
 * dependency of the extension) and loads the jsonfuse.so named on the command line. The main
 * thread computes reference results single-threaded, then JFH_THREADS pthreads each start their
 * own request (ts_resource, php_request_startup) and run JFH_ITERATIONS iterations of
 * jf_threads.php. Every iteration of every thread must give:
 *
 *   - the reference SHA-256 for its workload, compiled from a fresh plan on fresh streams;
 *   - for the wrapper run, "flagged" in the one thread that switched fibers while every thread
 *     was inside its run (fclose() refused on both streams), and "cleared" in all the others;
 *   - for the large record, RecordException memory_limit in thread 0, which runs at
 *     memory_limit=2M, and the reference output hash in every other thread;
 *   - the reference reason for an invalid plan.
 *
 * Leaks under the debug allocator and ThreadSanitizer reports go to stderr, which the caller
 * checks (.github/ci/zts-threads.sh). Exit status: 0 if every result matched, 1 otherwise,
 * 2 for a usage or startup error, 3 when the watchdog fires.
 *
 * usage: jf_threads <path to jsonfuse.so> <path to jf_threads.php>
 */

/* First, so that PHP's configuration (_GNU_SOURCE among it) applies to the system headers too. */
#include <sapi/embed/php_embed.h>

#include <pthread.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#ifndef ZTS
# error "jf_threads needs a ZTS build of PHP"
#endif

#define JFH_THREADS 8
#define JFH_ITERATIONS 200
#define JFH_WORKLOADS 6
#define JFH_REPORT_MAX 10

static char jfh_ini[8192];
static const char *jfh_script;

/*
 * A barrier for all JFH_THREADS threads that a thread leaving its request breaks, so that a thread
 * which failed early releases the others instead of leaving them waiting.
 */
static pthread_mutex_t jfh_lock = PTHREAD_MUTEX_INITIALIZER;
static pthread_cond_t jfh_cond = PTHREAD_COND_INITIALIZER;
static int jfh_waiting, jfh_generation, jfh_broken;

static bool jfh_wait(void)
{
	bool ok;

	pthread_mutex_lock(&jfh_lock);
	if (jfh_broken) {
		ok = false;
	} else if (++jfh_waiting == JFH_THREADS) {
		jfh_waiting = 0;
		jfh_generation++;
		pthread_cond_broadcast(&jfh_cond);
		ok = true;
	} else {
		int generation = jfh_generation;
		while (!jfh_broken && generation == jfh_generation) {
			pthread_cond_wait(&jfh_cond, &jfh_lock);
		}
		/* A thread that finished may break the barrier before this one wakes from a completed wait. */
		ok = generation != jfh_generation;
	}
	pthread_mutex_unlock(&jfh_lock);
	return ok;
}

/* Every thread passes its last wait together, so breaking at the end of a request releases only failures. */
static void jfh_break(void)
{
	pthread_mutex_lock(&jfh_lock);
	jfh_broken = 1;
	pthread_cond_broadcast(&jfh_cond);
	pthread_mutex_unlock(&jfh_lock);
}

/* jf_harness_barrier(): false if a thread left its request before every thread arrived. */
PHP_FUNCTION(jf_harness_barrier)
{
	ZEND_PARSE_PARAMETERS_NONE();
	RETURN_BOOL(jfh_wait());
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_jf_harness_barrier, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

static const zend_function_entry jfh_functions[] = {
	PHP_FE(jf_harness_barrier, arginfo_jf_harness_barrier)
	PHP_FE_END
};

static zend_module_entry jfh_module = {
	STANDARD_MODULE_HEADER,
	"jf_harness",
	jfh_functions,
	NULL, NULL, NULL, NULL, NULL,
	"1",
	STANDARD_MODULE_PROPERTIES
};

/* php_embed_init() calls this through php_embed_module.startup: our INI and the barrier function. */
static int jfh_startup(sapi_module_struct *sf)
{
	sf->ini_entries = jfh_ini;
	sf->php_ini_ignore = 1;
	return php_module_startup(sf, &jfh_module);
}

/*
 * Runs code in the current request and stores a malloc'd copy of its string result in *result, or
 * NULL. The result goes through a pointer that is never changed: a local assigned between setjmp
 * and longjmp would be indeterminate after a bailout (C11 7.13.2.1).
 */
static void jfh_eval(const char *code, char **result)
{
	*result = NULL;
	zend_first_try {
		zval rv;
		ZVAL_UNDEF(&rv);
		if (zend_eval_stringl_ex(code, strlen(code), &rv, "jf_threads", true) == SUCCESS && Z_TYPE(rv) == IS_STRING) {
			*result = strndup(Z_STRVAL(rv), Z_STRLEN(rv));
		}
		zval_ptr_dtor(&rv);
	} zend_end_try();
}

typedef struct {
	pthread_t id;
	int tid;
	int startup_failed;
	char *result;
} jfh_thread;

static void *jfh_thread_main(void *arg)
{
	jfh_thread *t = arg;
	char code[4096];

	(void)ts_resource(0);
	if (php_request_startup() == FAILURE) {
		t->startup_failed = 1;
		jfh_break();
		ts_free_thread();
		return NULL;
	}
	SG(headers_sent) = 1;
	SG(request_info).no_headers = 1;
	/* zend_eval_stringl_ex() evaluates an expression when it returns a value. */
	snprintf(code, sizeof code, "(require '%s') ? jfh_thread(%d, %d, %d) : ''",
		jfh_script, t->tid, JFH_ITERATIONS, JFH_THREADS);
	jfh_eval(code, &t->result);
	jfh_break();
	php_request_shutdown(NULL);
	ts_free_thread();
	return NULL;
}

static void *jfh_watchdog(void *arg)
{
	unsigned seconds = *(unsigned *)arg;

	sleep(seconds);
	fprintf(stderr, "jf_threads: FAIL watchdog: no result after %u s\n", seconds);
	fflush(stderr);
	_exit(3);
	return NULL;
}

typedef struct {
	char workload[JFH_WORKLOADS][80];
	char big[80];
	char sync[80];
	char bad[80];
} jfh_reference;

static int jfh_parse_reference(const char *s, jfh_reference *ref)
{
	int n = 0, used = 0;

	for (int w = 0; w < JFH_WORKLOADS; w++) {
		if (sscanf(s + used, " %79s%n", ref->workload[w], &n) != 1) {
			return 0;
		}
		used += n;
	}
	return sscanf(s + used, " %79s %79s %79s", ref->big, ref->sync, ref->bad) == 3;
}

/* Checks one thread's lines against the reference; returns the number of mismatches. */
static int jfh_check_thread(const jfh_thread *t, const jfh_reference *ref)
{
	int failures = 0, lines = 0;
	const char *p = t->result;

	if (t->startup_failed || p == NULL) {
		fprintf(stderr, "jf_threads: FAIL thread %d: %s\n", t->tid,
			t->startup_failed ? "request startup failed" : "no result (fatal error or uncaught exception)");
		return 1;
	}
	while (*p != '\0') {
		const char *eol = strchr(p, '\n');
		size_t len = eol ? (size_t)(eol - p) : strlen(p);
		char line[512], hash[80], sync[80], big[80], bad[80];
		int i, w;
		const char *want_sync, *want_big;

		snprintf(line, sizeof line, "%.*s", (int)(len < sizeof line ? len : sizeof line - 1), p);
		if (sscanf(line, "%d %d %79s %79s %79s %79s", &i, &w, hash, sync, big, bad) != 6) {
			if (failures++ < JFH_REPORT_MAX) {
				fprintf(stderr, "jf_threads: FAIL thread %d line %d: unparsable \"%s\"\n", t->tid, lines, line);
			}
		} else {
			want_sync = i % JFH_THREADS == t->tid ? "flagged" : "cleared";
			want_big = t->tid == 0 ? "memory_limit" : ref->big;
			if (i != lines || w != (t->tid + i) % JFH_WORKLOADS || strcmp(hash, ref->workload[w]) != 0
				|| strcmp(sync, want_sync) != 0 || strcmp(big, want_big) != 0 || strcmp(bad, ref->bad) != 0) {
				if (failures++ < JFH_REPORT_MAX) {
					fprintf(stderr, "jf_threads: FAIL thread %d iteration %d: got \"%s\"; want \"%d %d %s %s %s %s\"\n",
						t->tid, lines, line, lines, (t->tid + lines) % JFH_WORKLOADS,
						ref->workload[(t->tid + lines) % JFH_WORKLOADS], want_sync, want_big, ref->bad);
				}
			}
		}
		lines++;
		p = eol ? eol + 1 : p + len;
	}
	if (lines != JFH_ITERATIONS) {
		fprintf(stderr, "jf_threads: FAIL thread %d: %d iterations, want %d\n", t->tid, lines, JFH_ITERATIONS);
		failures++;
	}
	return failures;
}

int main(int argc, char **argv)
{
	jfh_thread threads[JFH_THREADS];
	jfh_reference ref;
	pthread_t watchdog;
	unsigned timeout = 900;
	char code[4096];
	char *reference;
	int failures = 0;

	if (argc != 3) {
		fprintf(stderr, "usage: %s <jsonfuse.so> <jf_threads.php>\n", argv[0]);
		return 2;
	}
	if (getenv("JFH_TIMEOUT") != NULL) {
		timeout = (unsigned)strtoul(getenv("JFH_TIMEOUT"), NULL, 10);
	}
	jfh_script = argv[2];
	/*
	 * opcache is off: from PHP 8.5 it is always built in and on for the embed SAPI, and its shared
	 * hit counter and JIT deactivation are unsynchronised by design, which ThreadSanitizer reports.
	 */
	snprintf(jfh_ini, sizeof jfh_ini,
		"html_errors=0\nimplicit_flush=1\noutput_buffering=0\nmax_execution_time=0\nmax_input_time=-1\n"
		"memory_limit=128M\nreport_memleaks=1\nerror_reporting=-1\ndisplay_errors=1\nlog_errors=0\n"
		"opcache.enable=0\nextension=%s\n", argv[1]);
	if (pthread_create(&watchdog, NULL, jfh_watchdog, &timeout) != 0 || pthread_detach(watchdog) != 0) {
		return 2;
	}

	php_embed_module.startup = jfh_startup;
	if (php_embed_init(0, NULL) == FAILURE) {
		fprintf(stderr, "jf_threads: php_embed_init failed\n");
		return 2;
	}

	snprintf(code, sizeof code, "(require '%s') ? jfh_reference() : ''", jfh_script);
	jfh_eval(code, &reference);
	if (reference == NULL || !jfh_parse_reference(reference, &ref) || strcmp(ref.sync, "cleared") != 0
		|| strlen(ref.big) != 64) {
		fprintf(stderr, "jf_threads: FAIL reference run: \"%s\"\n", reference ? reference : "(none)");
		free(reference);
		php_embed_shutdown();
		return 1;
	}
	printf("jf_threads: reference %s ... big %.16s sync %s bad %s\n", ref.workload[0], ref.big, ref.sync, ref.bad);

	for (int t = 0; t < JFH_THREADS; t++) {
		memset(&threads[t], 0, sizeof threads[t]);
		threads[t].tid = t;
		if (pthread_create(&threads[t].id, NULL, jfh_thread_main, &threads[t]) != 0) {
			fprintf(stderr, "jf_threads: pthread_create failed\n");
			return 2;
		}
	}
	for (int t = 0; t < JFH_THREADS; t++) {
		pthread_join(threads[t].id, NULL);
	}
	for (int t = 0; t < JFH_THREADS; t++) {
		failures += jfh_check_thread(&threads[t], &ref);
		free(threads[t].result);
	}
	free(reference);
	php_embed_shutdown();

	printf("jf_threads: %d threads x %d iterations, %d mismatches\n", JFH_THREADS, JFH_ITERATIONS, failures);
	fflush(stdout);
	return failures == 0 ? 0 : 1;
}
