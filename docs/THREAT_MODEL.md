# JsonFuse threat model

## Assets and attacker

JsonFuse transforms NDJSON. Its input stream is treated as untrusted: anyone who can place bytes in the input file or stream is assumed hostile. The `Plan` is assumed to come from the application, not from the attacker; field names and limits are validated, but a plan built directly from attacker input is the application's mistake.

## Attacker-controlled inputs and what stops them

| Input | Threat | Control |
| --- | --- | --- |
| Very long line | Unbounded buffering; fatal memory error | The reader refuses a line past `maxRecordBytes + 2` buffered bytes without an LF, before growing further. |
| Huge valid record | Parser memory | The pool is sized for the record, bounded by `maxRecordBytes`, and growth is preflighted against `memory_limit`. |
| Deep nesting | Stack exhaustion | yyjson parses iteratively; depth over 511 is rejected (`YYJSON_READER_DEPTH_LIMIT=511`). JsonFuse only walks top-level keys. |
| Malformed JSON, invalid UTF-8, BOM, NUL, comments, NaN, bad escapes | Parser confusion, smuggling | yyjson strict mode with no `ALLOW_*` flags. Any failure raises `RecordException`. |
| Duplicate referenced keys | Ambiguous data, first-wins vs last-wins confusion | Every root key is inspected; a duplicate of a referenced key raises. Never resolved silently. |
| Huge numbers | Silent loss of precision | Integers outside `int64` are rejected in `int64` fields. Out-of-range numbers are handled per the table in `SPEC.md` section 5; numbers that overflow binary64 are rejected in `float64` fields. In `float64` fields precision loss is silent by design: values round to the nearest binary64, and a nonzero literal too small for binary64 underflows to zero. The one exception is inherited from yyjson: a literal with more than 768 significant digits that is an exact binary tie may round one ulp high. |
| Control characters in strings or names | Log and output injection | Strings are re-encoded through the JSON escape table on output. Exception messages never include record bytes; field names are the plan's own, escaped. |
| Very wide records | CPU cost | Key lookup is one hash per key through a table sized at compile time; the table has at most 144 entries and records cannot add to it. |

No record bytes appear in exception messages, and no exception text is ever executed. There are no PHP callbacks on the record path, no `eval`, no regex built from input.

## What is not defended against

**Fibers.** A fiber may suspend inside a filter or wrapper callback of a stream held by a run, where PHP core relies on `PHP_STREAM_FLAG_NO_FCLOSE`, and the extension cannot see those core frames. So after any fiber switch during a run, the run leaves the flag set on both streams: a later `fclose()` is refused with a warning instead of freeing a stream that PHP core is still using, a new run on either stream raises `StreamException`, and each stream is freed when its last reference is released. The cost falls on applications that switch fibers inside a run, for example from an output wrapper: they cannot `fclose()` those streams and must drop the last reference instead. Loaded with `dl()`, the extension cannot observe switches and every run leaves both flags set.

**Persistent streams.** If a true bailout (a compile error or timeout, not a thrown exception) occurs while a run is suspended in a fiber on a persistent stream, the flag stays set. That stream is then unusable by further runs or by `fclose()` until the worker exits.

**`pclose()` of a stream from its own callback.** This is a use-after-free in PHP core, reproducible with plain `fread`, and not something this extension can prevent.

**Memory preflight.** The `memory_limit` preflight is best effort. On a heavily fragmented heap close to the limit, a request that the preflight allowed can still end in PHP's normal memory fatal, because ZendMM's free-page state is private.

**Timeouts.** A run stops with `StreamException` when PHP's `max_execution_time` flag is set at the next input refill, but a very long single record between refills is not interrupted mid-parse.

**Output integrity after failure.** If a write fails or a later record is invalid, output already accepted by the stream remains. JsonFuse does not roll back. A failed write can leave a partial last line. Applications that need all-or-nothing output should write to a temporary file and rename it after success.

**Output streams that run PHP.** A `php_user_filter` or stream wrapper on `$output` runs PHP during writes; JsonFuse honours its exceptions and failures, but it cannot make such code safe.

**Authentication and access.** The extension does not authenticate data sources and does not restrict which streams the application passes. The application decides what to read and write.

**Non-goals.** JsonFuse 1.0 has no generic JSONPath, no JSON Schema, no arbitrary PHP transforms, no compressed-stream integration, no transactional writer and no async API. ZTS builds are supported: per-run state is on the C stack or in per-call allocations, and the one module global, the fiber-switch count, is per thread.

## Sanitizer and fuzz limits

A clean ASan/UBSan run covers the extension's own code. It does not cover PHP core: a `USE_ZEND_ALLOC=0` sanitizer lane on PHP 8.3.36 did not report php-src use-after-frees that a differently built ASan PHP did. The native fuzz harness replaces Zend, PHP streams and the Zend allocator with fakes (`fuzz/shim/php.h`); bugs that need real PHP streams or real bailouts must be found with PHPT.

## Dependency tracking

yyjson is pinned to 0.13.0 with its SHA-256 in `third_party/yyjson/PIN.txt`. Security advisories for yyjson should be checked against that pin at each release.
