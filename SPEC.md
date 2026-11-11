# JsonFuse — Schema-Driven, Bounded-Memory JSON Transformation for PHP

**Spec version:** 1.0  
**Native PHP module:** `jsonfuse`  
**Repository:** `php-jsonfuse`  
**Namespace:** `JsonFuse`  
**Primary language:** C11; a validated C JSON parser library may be embedded or linked  
**Target:** PHP 8.3–8.5, Linux x86-64 NTS first; expand based on tests. PHP 8.6 is tested against a release candidate only.  
**Status:** Specification of JsonFuse 1.0.0

> **Approach:** the smallest safe streaming NDJSON implementation first, and no homegrown partial JSON recognizer presented as RFC-8259-compliant. Benchmarks follow `BENCHMARK_PROTOCOL.md`.

---

## 1. Product vision

JSON-heavy PHP applications can waste time by converting complete records into nested Zend arrays, running several userland loops, then allocating another representation for output. JsonFuse provides one declarative, typed, **native record transformation pipeline** that validates JSON, filters inputs, projects required fields and serializes the result directly to a PHP output stream.

The initial format is **NDJSON** (newline-delimited independent JSON objects), ideally suited to ingestion pipelines, event logs, data exports, migration jobs and queue workers. Input stream and output stream are explicit. Memory must be bounded by the maximum record size, the parser's working set, and fixed buffers—not total input size.

### Example — API

```php
use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile([
    'fields' => [
        'user_id' => 'int64',
        'amount' => 'float64',
    ],
    'where' => [
        'event' => 'purchase',
    ],
    'output' => 'ndjson',
    'maxRecordBytes' => 8 * 1024 * 1024,
]);

$in = fopen('events.ndjson', 'rb');
$out = fopen('purchases.ndjson', 'wb');
try {
    $stats = Runner::process($in, $out, $plan);
    printf("Read %d, emitted %d\n", $stats->recordsRead, $stats->recordsEmitted);
} finally {
    fclose($in);
    fclose($out);
}
```

**Guarantee:** never materialize an entire input *file* in PHP arrays. The current native implementation may build one bounded-size **native JSON document tree per record** when using a mature C parser. A future fully event-driven/on-demand parser could remove that per-record tree, but must not be falsely claimed for this version.

## 2. Differentiation from existing products

- `json_decode` / `json_encode`: flexible general-purpose PHP JSON conversion, commonly materializing PHP arrays/objects.
- PHP `simdjson`: optimized parsing/selective extraction but not the proposed full schema-driven filtering, validation, and serialization pipeline. https://pecl.php.net/package/simdjson
- `simdjson` C++ library: exceptionally fast lower-level parser; adopting it directly introduces C++ and API/exception complexity. It is a valid future backend, not automatically faster for this product. https://github.com/simdjson/simdjson
- JSON Machine and similar streaming libraries: iterate input incrementally, but typical userland transformation still incurs per-record PHP values/callbacks. https://github.com/halaxa/json-machine
- Dedicated C streaming systems: may surpass JsonFuse; benchmark against credible alternatives instead of ignoring them.

**Unique focus:** schema compilation + predictable per-field semantics + filtering + projected output as one PHP-call-to-native pipeline, with no per-record PHP callbacks.

## 3. Intended users and real workloads

1. **API services:** transform external JSON event feeds into smaller, schema-validated records.
2. **Data import jobs:** read millions of events without O(total-file) memory growth.
3. **Operational tooling:** select a few fields from very wide NDJSON documents and export new NDJSON.

**Non-goals for 1.0:** generic JSONPath engine, live HTTP handling, auto-parallel workers, a schema standard such as full JSON Schema 2020-12, arbitrary PHP transforms, general-purpose JSON decoder replacement, and regular JSON top-level array input.

## 4. MVP feature boundaries

### Required

- `JsonFuse\Plan::compile(array $spec): Plan` validates and compiles a safe declarative plan.
- `JsonFuse\Runner::process($input, $output, Plan $plan): Stats` reads one NDJSON record at a time, validates JSON, projects typed fields, applies filters, and writes selected fields as valid NDJSON.
- `JsonFuse\Stats` read-only properties: `recordsRead`, `recordsMatched`, `recordsEmitted`, `bytesRead`, `bytesWritten`.
- Schema field types: `int64`, `float64`, `bool`, `string`, and their nullable variants (`int64?`, etc.).
- Filters on scalar fields, top-level or nested through `path`: equality (`string|int|bool|null`, optionally finite float with strict semantics), ranges (`<`, `<=`, `>`, `>=`) and `in` lists. Only **AND** of multiple `where` keys. All fields explicitly validated before a row can be emitted.
- Root JSON must be an object. Output field order is the order of the declared `fields` mapping.
- Strict input cap `maxRecordBytes`; robust LF and CRLF, final record without trailing newline, empty lines policy.
- Full syntactic JSON and UTF-8 validation, safe JSON serialization, strict I/O failure handling, and bounded working memory.
- PHPT, fuzz/mutation tests, integration examples, and benchmark suite.

### Not in this version

- Streaming arbitrary JSON arrays and nested output objects (nested input keys are read through `path`; the output stays flat).
- Full JSON Schema, JSON Pointer, transformations (arithmetic / date / regex), aggregation, parallel parsing, compressed streams integration, C++ simdjson backend, transactional file writer, HTTP/Swoole adapters, async APIs.

## 5. Public API and exact 1.0 semantics

```php
namespace JsonFuse;

/**
 * @strict-properties
 * @not-serializable
 */
final class Plan {
    private function __construct() {}
    public static function compile(array $spec): Plan {}
}

/**
 * @strict-properties
 */
final class Stats {
    public readonly int $recordsRead;
    public readonly int $recordsMatched;
    public readonly int $recordsEmitted;
    public readonly int $bytesRead;
    public readonly int $bytesWritten;
    private function __construct() {}
}

/**
 * @strict-properties
 * @not-serializable
 */
final class Runner {
    private function __construct() {}
    /** @param resource $input @param resource $output */
    public static function process($input, $output, Plan $plan): Stats {}
}

class JsonFuseException extends \RuntimeException {}
class PlanException extends JsonFuseException {}
class RecordException extends JsonFuseException {}
class StreamException extends JsonFuseException {}
```

**Implementation note:** PHP's exposed stream resource type in stub files may need an untyped parameter because PHP doesn't have a built-in `resource` type declaration. Validate at runtime with PHP streams APIs (`php_stream_from_zval` / appropriate helpers). Ensure arginfo matches the real C implementation. In the stub, each `@tag` must be on its own docblock line; the generator silently drops a second tag on the same line.

`Plan` has a custom `create_object`, `clone_obj = NULL`, and a `free_obj` that handles a partially built plan. `Runner::process` checks the plan is initialized. `Stats` properties are set from C with `zend_update_property_long(stats_ce, ...)`. `Stats` allows clone and serialization: it holds no native state.

Integer array keys in `fields` and `where` are converted to their decimal string, since PHP only normalizes canonical decimal strings to integers and the conversion is lossless. Field names and string `where` values must be valid UTF-8 and at most 1024 bytes. `where` has at most 16 keys.

`fields` is required; `where` defaults to `[]`; `output` defaults to `'ndjson'`; `maxRecordBytes` must be an int in range with no coercion; `skipEmptyLines` must be a bool. Type strings are case-sensitive with only a trailing `?`. Unknown top-level keys raise `PlanException`. Messages name the option and never echo values.

### Plan example and options

```php
$plan = Plan::compile([
    'fields' => [
        'id' => 'int64',
        'name' => 'string',
        'active' => 'bool?',
    ],
    'where' => ['kind' => 'customer'],
    'output' => 'ndjson',
    'maxRecordBytes' => 4_194_304,
    'skipEmptyLines' => false,
]);
```

- `fields` is required, a non-empty ordered map of unique output field names to type strings or field spec arrays (`type`, `path`, `default`). Limit field count (e.g. 128).
- A projected field is required unless it has a `default`, which is written in place of a missing key. A nullable field may be present with JSON `null`; nullable does not mean optional. A field reads the top-level key of its name, or the nested key its `path` names (1 to 8 object keys); the output key is always the field name.
- `where` keys need not appear in output `fields`. Filter values must be JSON scalars; a record missing a filter field does not match. An `event` string filter is case-sensitive and byte-exact after correct JSON escape decoding.
- `where` values may be string, int, bool, null, or a finite float (NaN/INF raise `PlanException`). Matching is type-strict. A string matches only a JSON string with identical bytes after escape decoding. An int matches only an integer literal of equal value (never `5.0` or `"5"`). A float matches only a literal with a fraction or exponent, by IEEE equality (`-0.0` equals `0.0`; never the literal `5`). A bool matches only `true`/`false`; null matches only JSON null. A missing key does not match. A present scalar of another type, or a RAW number, does not match and is not an error. An array or object value raises `RecordException`. A key listed in both `fields` and `where` is type-checked as a field first. An empty or absent `where` matches every record.
- If a field named in `fields` has the wrong type, fail with `RecordException` even if the record would not match a filter. This avoids inconsistent validation. Validate projected fields and filter-field scalar forms **on every record** before filtering.
- Validation order per record, first failure reported: (1) framing and size cap; (2) JSON syntax and UTF-8; (3) root is an object; (4) duplicate referenced keys; (5) each `fields` entry in declared order: present or has a default, null only if nullable, type; (6) each `where` key in declared order: if present, must be a JSON scalar; (7) evaluate filters.
- `bool` requires JSON `true`/`false`. No coercion from strings or numbers for any type.
- Number rules (RAW = yyjson raw token: integer outside int64/uint64, or real overflowing binary64):

  | JSON literal | yyjson | `int64` | `float64` |
  | --- | --- | --- | --- |
  | `0`, `5`, `-5` | UINT/SINT | accept | accept, emitted `5.0` |
  | `-0` | SINT 0 | accept, emitted `0` | accept, emitted `0.0` (positive zero) |
  | `1.0`, `1e3`, `1E3` | REAL | reject | accept, emitted `1.0`, `1000.0` |
  | `-0.0`, `-0e5` | REAL | reject | accept, emitted `-0.0` |
  | int64 min and max | SINT, UINT | accept | accept (rounded) |
  | 9223372036854775808 to 18446744073709551615 | UINT | reject | accept (rounded) |
  | below int64 min or above uint64 max | RAW | reject | accept (rounded) if finite, else reject |
  | `1e400`, `-1e400`, 400-digit integer | RAW | reject | reject |
  | `1e-400`, `-1e-400` | REAL | reject | accept as `0.0`, `-0.0` |

  Values are correctly rounded to nearest binary64. One exception is inherited from yyjson 0.13.0, whose vendored copy stays unmodified: a `float64` literal with more than 768 significant-position digits that is an exact binary tie may round one ulp high. RAW tokens in `float64` fields are converted with `yyjson_read_number` (flags 0); a conversion failure raises `RecordException`. Number literals in unreferenced fields are validated for grammar only and may be any magnitude.
- `string` requires valid UTF-8 text. Output encoding uses a trusted JSON escaping routine and always emits correctly escaped Unicode/control characters.
- A key referenced by `fields` or `where` that occurs more than once in a record's root object raises `RecordException`, comparing keys as bytes after JSON escape decoding (`"\u0069"` and `"i"` are duplicates). Every root key is inspected on every record; processing never stops once all referenced keys are found. Duplicate keys that are not referenced, at any depth, are accepted and never inspected. Duplicates are never resolved first-wins or last-wins.
- The `fields` map may contain strings such as `__proto__` with no special behavior; plan and record bytes are data and are never evaluated as code.
- `output` only supports `'ndjson'` in 1.0; unknown values rejected at compile time.
- `maxRecordBytes` default 1 MiB (1,048,576); minimum 1; maximum 67,108,864 (64 MiB). The cap counts JSON text bytes only, excluding the terminating LF or CRLF.
- A record is one LF-delimited line. A trailing CR before LF is removed. Bare CR elsewhere is JSON whitespace; a CR inside a string is invalid, and CR-only files read as one record with trailing content.
- A final line without LF is processed as a record. A line of exactly 0 bytes after CR removal is a blank line: `RecordException` unless `skipEmptyLines` is true, in which case it is skipped. A whitespace-only line is always invalid JSON. A UTF-8 BOM or a NUL byte outside a JSON escape is rejected. `\u0000` inside a string is valid and preserved.
- A record is rejected once more than `maxRecordBytes + 2` bytes are buffered with no LF. When the line length is known the cap counts JSON text only; at EOF one trailing CR is excluded.
- `Stats` is returned after success, and every `RecordException` and `StreamException` of a run carries one through `getProgress()` with the counts at the throw. `recordsRead` counts non-skipped lines. `recordsMatched` counts records passing all filters. `recordsEmitted` counts records whose complete line the output accepted; on success it equals `recordsMatched`. `bytesRead` is all bytes consumed including terminators and skipped lines; on success it equals the input bytes read by this call. `bytesWritten` counts bytes accepted by the output stream. Counters are checked `zend_long` values.
- Exception messages use `line N (record R, offset O)` before the reason. `N` is the 1-based physical line, `R` is 1-based and excludes skipped blanks, and `O` is the byte offset of the line's first byte from this call's start. Parse errors add yyjson's byte position within the record and its fixed message. No record bytes appear in messages.
- If `Runner::process()` raises an error after emitting earlier records, **output may contain a valid prefix**. The function never promises transactional rollback. Document use of a temporary file + atomic rename by the application when needed.
- The function never closes streams owned by the PHP caller. After a record error, `read_failed` or `no_data`, with no PHP exception pending, the complete records still buffered are written with the same write loop and no `php_stream_flush`, then the exception is raised. If that write fails, the `StreamException` `write_failed` has the recorded exception as its previous; an exception a wrapper or filter throws during it propagates with the recorded exception at the end of its `getPrevious()` chain. After any other error the buffered records are discarded and nothing more is written. A failed output write may leave a partial last line.

### Error reporting

Every exception carries a stable reason code (`getReason()`) and `getField()`, `PlanException` adds `getOption()`, and `RecordException` and `StreamException` add `getInputLine()`, `getRecordNumber()`, `getByteOffset()` and `getProgress()`. Message text is not frozen: the wording below is what this build writes, and only two rules about it are tested, that no record bytes appear and that plan messages never echo plan values.


- Invalid plan → `PlanException` with name of invalid option, without including confidential field values. A PHP array can hold both the string key `"1"` and the int key `1` (for example after `compact()` or `get_defined_vars()`), which name the same key; two `fields` entries or two `where` keys that resolve to the same name raise exactly `Plan option 'fields': field names must be unique` or `Plan option 'where': keys must be unique`.
- Invalid record → `RecordException` with 1-based record/physical line number and safe field name / type; report offset when available.
- Reasons for typed validation failures, after the `line N (record R, offset O): ` prefix, are exactly: `duplicate key "<name>"`, `missing field "<name>"`, `field "<name>" must not be null`, `field "<name>": expected <type>, got <kind>`, and `where key "<name>" must be a JSON scalar`. `<name>` is the plan's own field or `where` key, written with the output escape table from section 6 so control bytes cannot reach logs; it is never copied from record bytes. `<type>` is the declared type without `?`. `<kind>` is one of `null`, `bool`, `integer`, `real`, `string`, `array`, `object` or `out-of-range number`. In an `int64` field, any integer outside the int64 range is reported as `out-of-range number`, whether or not it fits in 64 unsigned bits. When several referenced keys are duplicated, the first duplicate met in document order is reported.
- Input/output I/O failure → `StreamException`; include operation and record context, but do not expose whole record contents.
- On partial write, retry remaining bytes as appropriate; do not count uncommitted bytes as successfully written. An error must surface if zero bytes are written unexpectedly.
- Never transform invalid JSON into a quietly accepted record. A top-level JSON array is invalid.

## 6. Native architecture: strict parser + compiled plan + writer

```text
PHP Plan::compile($spec)
       |
       v
   native immutable plan
       |
PHP Runner::process($input, $output, $plan)
       |
       v
   bounded line reader (PHP stream)
       |
       v
   strict JSON parser (one native record DOM or token representation)
       |
       v
   typed projected fields + validated filter predicates
       |
     (match?) -- no --> discard per-record native state
       |
      yes
       |
       v
   direct native JSON output writer (only projected fields)
       |
       v
   PHP output stream
```

### Parser backend choice

Every record is validated in full (RFC 8259 syntax, RFC 3629 UTF-8, surrogate pairs, nesting) before any of its values is used. There are two code paths:

- **The scan** (`jf_scan_record`), for plans whose every path has one segment. It is a non-recursive validating pass over the record text that accepts a strict subset of what yyjson accepts with the flags below, nesting at most 64 levels below the root. It materialises only the referenced root values: numbers through `yyjson_read_number` with the run's pool allocator, strings unescaped in place, containers as their type only. It hands to yyjson, with the text untouched, any record that is invalid, nests deeper, has a non-object root, an escaped root key or a duplicate referenced key. It never accepts a record yyjson rejects. A key pre-filter for the scan lives per run on the C stack, never in `Plan`.
- **yyjson 0.13.0**, vendored unmodified, parses one bounded record in native memory for every record the scan hands over and for every record of a plan with a nested path. It therefore produces every parse error, position and message. It allocates a native DOM per record from a pool reused across records; memory remains O(maximum record size), not O(total input size).

The pool is sized before the scan runs, so heap requests, and with them `memory_limit` outcomes, are the same on both paths. A build compiled with `-DJF_DISABLE_SCAN` (test builds only) uses yyjson for every record and must give identical output bytes, `Stats`, exception class, reason, getters, progress and message for every plan and input; `php --ri jsonfuse` reports `validating scan => enabled` or `disabled`.

Parse with exactly `YYJSON_READ_INSITU | YYJSON_READ_BIGNUM_AS_RAW`. Never set any `ALLOW_*` flag or `NUMBER_AS_RAW`.

Referenced keys are found by one pass over the root object using a compile-time hash table. `yyjson_obj_get` must not be used for referenced fields: it is first-wins and O(n) per lookup.

### Illustrative native structs

```c
typedef enum { JF_INT64, JF_FLOAT64, JF_BOOL, JF_STRING } jf_type;

typedef struct {
    char *name;                 /* owned */
    size_t name_length;
    jf_type type;
    bool nullable;
} jf_field_spec;

typedef struct {
    size_t field_count;
    jf_field_spec *fields;
    size_t filter_count;
    /* typed, owned filter values; immutable after compile */
    size_t max_record_bytes;
    bool skip_empty_lines;
} jf_plan_data;
```

The PHP `Plan` object owns compiled native arrays. `Runner::process` relies on its call frame's argument slots to keep the plan alive for the duration of the call; do not keep pointers into temporary PHP arrays from the `compile` call. `Stats` objects contain copied scalar counters, not references into the runner's stack.

### Bounded line reader

- Read with PHP stream APIs in chunks (e.g. 64 KiB), not a function that can silently allocate an unlimited line.
- Detect overlong lines **before** increasing buffers past the configured cap; reject safely.
- Handle CRLF by excluding trailing carriage-return from JSON text but counting bytes read accurately. Preserve correct offsets.
- Handle EOF after a complete final record without LF.
- A JSON string cannot contain a literal unescaped newline, so raw-LF framing is appropriate for NDJSON.
- If a UTF-8 sequence spans input chunks, maintain a contiguous record buffer and validate after complete read; no premature decoding.
- Never assume `php_stream_read()`/`php_stream_write()` transfers the requested number of bytes in one call.

### Stream contract

`Runner::process` takes no references of its own. The call frame's argument slots keep `$plan` and both stream resources alive, so `unset()` in a callback cannot free them.

At entry, before setting anything, the runner checks both streams. If either already has `PHP_STREAM_FLAG_NO_FCLOSE`, it raises `StreamException` ("a stream is already in use by another Runner::process call or cannot be closed from PHP"). Known cases: another run; directory handles (`opendir()`, `dir()`); streams internally held by SPL or libxml; a stream's own filter or OpenSSL callback; and, on PHP 8.5, a wrapper's own callback, because 8.5 sets the flag around userspace read, write, seek and cast. That last case differs by version: 8.3 and 8.4 allow a run on a wrapper's own stream from inside its callback, 8.5 refuses it.

Otherwise the runner sets `PHP_STREAM_FLAG_NO_FCLOSE` on both streams. During processing a callback's `fclose()` on either stream is refused by PHP with an `E_WARNING` and returns false. On every exit including bailout, the runner clears both flags if no fiber switch happened during the run, and leaves both set if one did (since JsonFuse 1.0).

`php_stream_read`, `php_stream_write`, `php_stream_flush` and `php_stream_eof` can run PHP (wrappers, filters, error handlers). After each one, in this order: (1) if `EG(exception)` is set, release per-run state and return with that exception unchanged, never wrapped or replaced; (2) re-fetch both streams with `zend_fetch_resource2(res, NULL, php_file_le_stream(), php_file_le_pstream())`, and if either returns NULL (closed by another route, e.g. `pclose()`), raise `StreamException`. No `php_stream*` is held across such a call.

`pclose()` is not refused by the flag and raises `StreamException` only for the OTHER stream. `pclose()` of a stream from its own callback frees it under the running read: that is a php-src use-after-free, reproducible with plain `fread`, and this extension cannot prevent it.

Fibers: at MINIT the extension registers a fiber-switch observer that counts every switch on the thread (start, suspend, resume, return, destruction), and a run compares the count at entry and exit. A fiber that suspended inside a filter or wrapper callback of either stream may still have PHP core relying on the flag, and the extension cannot see those core frames, so after any switch the flags stay set: `fclose()` of either stream returns false with an `E_WARNING`, a new run on it raises `StreamException`, and the stream is still freed when its last reference is released (the resource destructor does not check the flag). A module loaded with `dl()` registers no observer, because the observer list has no removal and the module is unloaded at request end; then every run leaves both flags set. On PHP 8.4 and later, a garbage collection that calls destructors while a fiber is active runs them in a fiber of its own (`gc_call_destructors_in_fiber`), so during a run it counts as a switch.

Persistent streams: if a true bailout occurs while a run is suspended in a fiber, the flag stays set on that persistent stream, and the stream is unusable by further runs or `fclose()` until the worker exits.

Reads: `n < 0` is `StreamException`. `n == 0` with `php_stream_eof()` true is end of input. `n == 0` with `php_stream_eof()` false raises `StreamException` (would-block or empty read); non-blocking streams are not supported. Short reads are normal.

Writes: loop until every byte is accepted. `n <= 0` raises `StreamException`. Only bytes accepted by `php_stream_write` count toward `bytesWritten`.

After the final write of a successful run the runner calls `php_stream_flush` once. A run that wrote nothing, or that failed, does not flush. A non-zero result raises `StreamException` unless the stream is a userland wrapper, where a missing `stream_flush` also returns non-zero.

`$input` and `$output` must be different resources, else `StreamException`. A closed or non-stream argument raises `TypeError`, as for all PHP stream functions. The position of `$input` after an exception is unspecified, because the reader reads ahead.

Output reaches the stream only in whole records. After any error no further writes occur.

Per-run state is on the C stack or per-call heap, never static or module-global; the one module global is the per-thread fiber-switch count. Nested calls from callbacks and fiber suspension are safe only on different streams; a stream already held by another run raises `StreamException`. At each input refill the runner checks `EG(timed_out)` and, if set, stops with `StreamException`, letting the engine raise its timeout fatal instead of a hard kill.

### Memory and failure model

All per-run buffers use `emalloc`. Never `malloc`, persistent allocation, a NULL yyjson allocator, or yyjson APIs that allocate through libc (`yyjson_val_write*`).

The parser uses one `yyjson_alc_pool_init` pool, reused across records (one document at a time, freed with `yyjson_doc_free`, which returns void in 0.13.0; the bool to check is `yyjson_alc_pool_init`'s). For a record of `L` bytes, `need = yyjson_read_max_memory_usage(L, flags)` (treat 0 as an error). If `need` exceeds the pool capacity, free the old pool, then allocate `max(need, min(2 * capacity, bound))`, where `bound = yyjson_read_max_memory_usage(maxRecordBytes, flags)`.

The reader grows to at most `maxRecordBytes + 2 + 64 KiB + YYJSON_PADDING_SIZE` bytes. It keeps at least `YYJSON_PADDING_SIZE` writable bytes after any record and zeroes the unread tail. yyjson zeroes the 4 bytes after a record in place, so the runner saves them before and restores them after each parse.

The writer reserves space for a whole record before serializing it: `rec_len + sum(field prefix lengths) + sum(default lengths) + 40 * fieldCount + 2`, where a default's length is its exact output. Writer capacity never exceeds that value for `maxRecordBytes`. No record is written in pieces.

Peak native memory is at most `12 * maxRecordBytes + 256` (parser) + the reader bound + the writer bound, about 14.1 MiB at the default 1 MiB. The document tree charges `memory_limit` for the worst case regardless of record shape.

Before growing the pool, reader or writer, the runner compares the request against `PG(memory_limit)` (ignoring `-1`). A request a chunk cannot satisfy (at or above `ZEND_MM_CHUNK_SIZE - ZEND_MM_PAGE_SIZE`) is rounded up to 2 MiB and compared against the limit minus `zend_memory_usage(1)`. A smaller request comes out of chunk pages, so it is compared unrounded against the limit minus `zend_memory_usage(0)`; and when `zend_memory_usage(1) + ZEND_MM_CHUNK_SIZE` exceeds the limit (no headroom for another chunk), it is also refused if above half the free space, `(zend_memory_usage(1) - zend_memory_usage(0)) / 2`, because ZendMM needs contiguous pages. A refused request raises `RecordException`.

This preflight is best-effort. It prevents a fatal on an unfragmented heap and for all sizes where there is headroom for another chunk, but a heavily fragmented heap close to `memory_limit` can still end in PHP's normal memory fatal, because ZendMM's free-page state is private. The cost: at `memory_limit` 2M and 3M the largest accepted record is about 65 KB.

Crossing the soft `memory_limit` in a non-fallible `emalloc` succeeds and queues a delayed OOM, delivered at the next safe point as `UnwindExit` and seen through `EG(exception)` (verified on 8.3.36, 8.4.27 and 8.5.12). The hard limit (by default 200% of `memory_limit`, or +1G above 1G) calls `_exit(137)`: no longjmp, no shutdown functions, no `zend_catch`. The preflight is what prevents a hostile record from reaching the hard limit.

Genuine fatals such as compile errors and timeouts do longjmp through `zend_catch`. The runner wraps its body in one `zend_try`. `zend_catch` clears stream flags, frees per-run buffers and re-bails. Per-run state lives in one struct whose address is fixed before `zend_try`. Across a bailout it is safe to hold only Zend-allocated memory. Hold raw file descriptors, `malloc` memory, locks and module-global state only through PHP streams or object state reachable from `free_obj`.

### Native output writer

- Output is the declared `fields`, in schema order, as a compact JSON object (no whitespace after `:` or `,`, for example `{"a":1,"b":"x"}`) followed by LF, in the bounded record-reserved writer described under Memory and failure model. Output goes to the stream's current position; the runner never truncates or seeks it. No eager PHP `json_encode()` call per record.
- Numbers use `yyjson_write_number` only, with a buffer of at least 40 bytes. `float64` values are always written from a REAL-tagged value (integers converted by C cast, RAW via `yyjson_read_number`). The output is yyjson's shortest round-trip form with lowercase `e` and no `+`, independent of locale and `serialize_precision`, for example `1.0`, `1000.0`, `100000000000000000000.0`, `1e21`, `5e-324`, `-0.0`. The build must not define `YYJSON_DISABLE_FAST_FP_CONV`, whose fallback uses locale-dependent libc.
- Strings use a 256-entry escape table: `"` `\\` `\b` `\f` `\n` `\r` `\t` and other bytes below 0x20 as lowercase `\u00xx`. `/`, DEL, non-ASCII UTF-8 and U+2028/U+2029 are written raw. Field names use the same table at compile time. Input strings are already validated UTF-8.
- Strings are byte-identical to `json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS)`. Float text is not byte-compared with `json_encode`.
- Reference tests decode each output line with `json_decode` and compare key order, strings by bytes, integers exactly, and floats by IEEE bits (`pack('E', $v)`). Duplicate keys, depth, and over-range numbers are tested against the tables in section 5, because `json_decode` cannot observe duplicate-key rejection.

## 7. Suggested project layout

```text
php-jsonfuse/
  README.md
  SPEC.md
  BENCHMARK_PROTOCOL.md
  config.m4
  composer.json
  src/jsonfuse.c
  src/jf_plan.c
  src/jf_plan.h
  src/jf_runner.c
  src/jf_runner.h
  src/jf_stream.c
  src/jf_stream.h
  src/jf_parse.c
  src/jf_parse.h
  src/jf_writer.c
  src/jf_writer.h
  src/jf_errors.c
  src/jf_errors.h
  third_party/yyjson/yyjson.c  # ONLY verified pinned dependencies + licenses
  third_party/yyjson/yyjson.h
  third_party/yyjson/LICENSE
  third_party/yyjson/PIN.txt   # tag 0.13.0, sha256, unmodified
  stubs/jsonfuse.stub.php
  stubs/jsonfuse_arginfo.h     # generated by PHP 8.3's gen_stub.php, committed
  tests/001_load.phpt
  tests/010_compile_plan.phpt
  tests/020_match_and_project.phpt
  tests/030_types_and_nulls.phpt
  tests/040_utf8_escape.phpt
  tests/050_bad_json.phpt
  tests/060_stream_errors.phpt
  tests/070_limits.phpt
  tests/080_partial_output.phpt
  examples/filter_events.php
  benchmarks/generate_ndjson.php
  benchmarks/bench_jsonfuse.php
  benchmarks/bench_php.php
  docs/DESIGN.md
  docs/THREAT_MODEL.md
  .github/workflows/ci.yml
  CHANGELOG.md
  LICENSE
```

Build flags are the only yyjson configuration: `-fvisibility=hidden -Dyyjson_api= -DYYJSON_READER_DEPTH_LIMIT=511`. Editing the stub requires regenerating the header.

`config.m4` layout:

```
PHP_NEW_EXTENSION([jsonfuse], [src/jsonfuse.c src/jf_plan.c src/jf_runner.c src/jf_stream.c src/jf_parse.c src/jf_writer.c src/jf_errors.c third_party/yyjson/yyjson.c], [$ext_shared],, [-fvisibility=hidden -Dyyjson_api= -DYYJSON_READER_DEPTH_LIMIT=511])
PHP_ADD_INCLUDE([$ext_srcdir/stubs])
PHP_ADD_INCLUDE([$ext_srcdir/third_party/yyjson])
PHP_ADD_BUILD_DIR([$ext_builddir/src])
PHP_ADD_BUILD_DIR([$ext_builddir/third_party/yyjson])
```

## 8. Complete testing checklist

| Area | Specific cases |
| --- | --- |
| Input framing | LF/CRLF, final record without LF, zero records, empty line, huge line, short reads, pipe input, unreadable stream |
| JSON syntax | Escaped quotes, Unicode surrogate pairs, invalid escapes, invalid UTF-8, unterminated strings, trailing garbage, nested unrelated data |
| Schema | Empty fields, invalid type string, nullable vs required-present, too many fields, unknown plan option |
| Type handling | Signed int64 min/max, 2^53 precision boundaries, finite float limits, negative zero, bool, JSON null, type mismatch |
| Filters | Match/no match, missing field, several AND predicates, duplicate referenced key, wrong filter scalar type |
| Output | Exact keys/order, control-character escaping, non-ASCII text, newline termination, parseable output |
| Streams | Closed stream, wrong direction, short write, out-of-space behavior if testable, I/O exception, do not close caller streams |
| Memory | Long processing run with stable RSS, failed native parse cleanup, repeated plan/runner lifetime |
| Fuzzing | Mutated truncated input, unexpected nesting, UTF-8 corruption, pathological lengths, repeated-field patterns |

An output stream opened read-only produces no error when there are zero matches, because no write occurs; the runner does not pre-validate stream modes.

A PHPT test should verify each user-visible error contract. A standalone native test harness should fuzz parsers and writers without needing a PHP process per input.

## 9. Benchmark methodology and success hypothesis

### Primary workloads

1. **Narrow input:** 10k, 100k, 1m NDJSON objects with ~5 scalar fields; emit 2 fields for 10% of records.
2. **Wide input:** same records but ~100 input fields, project 2 or 3.
3. **High match rate:** emit 90% of records (serialization dominates).
4. **Low match rate:** emit 1% of records. Every record is still fully parsed and validated; the saving is skipped output formatting and writes.
5. **Oversized record handling:** fixed maximum record size; total input much larger than process memory. This verifies the memory bound, not a comparative claim.

### Baselines

- Userland PHP loop (`fgets`, `json_decode`, validation, projection, `json_encode`, `fwrite`) implementing **identical** error semantics where feasible.
- A mature PHP streaming JSON solution with functionally equivalent transform.
- PHP `simdjson` installed and compatible, if available, with a fair measurement of wrapping/conversion overhead.

Report throughput (records/s, MiB/s), median/p95 wall time, max RSS, raw process run logs, and exact input record count/size. Include both parser-only and full-pipeline tests, clearly labeled. Note differences if a baseline treats malformed JSON differently.

**Goal, not promise:** at least 2x median throughput against the best PHP baseline (`json_decode` and PECL simdjson, JIT on) on at least one predeclared workload, measured on the release target. Memory is not a comparative claim: every implementation sits at PHP's floor. Bounded memory on oversized lines is a safety property.

## 10. Safety model and threat boundaries

- NDJSON is treated as untrusted input. Hard record-size caps, checked allocation arithmetic and strict parser errors are mandatory.
- JSON data may contain sensitive personal or business data. Logs and exception messages must never dump entire records.
- No arbitrary PHP callbacks, eval, regex from input, or schema execution. Plans are data, not scripts.
- The extension neither authenticates data sources nor restricts accessible streams; those are the application's responsibilities.
- Partial output is explicitly possible after a record error. No implicit rollback. Document an atomic-file recipe for callers.
- Parser backend must have its own license and version recorded; vulnerability updates must be tracked.
- ZTS builds are supported, and the PIE metadata declares it (`support-zts`): per-run state is on the C stack or in per-call allocations, the one module global is per thread, and CI runs the PHPT suite on ZTS builds and a multi-thread harness under ThreadSanitizer.
- Parser memory is bounded by `maxRecordBytes` (section 6). yyjson 0.13 parses iteratively, and JsonFuse walks record keys iteratively, at most 8 levels deep along plan paths, so no recursion occurs on input data. Container nesting above 511 levels (root object = level 1) is rejected with `RecordException`, enforced by building yyjson with `-DYYJSON_READER_DEPTH_LIMIT=511`. This matches `json_decode` at its default depth.

## 11. References

- PHP internals: https://www.phpinternalsbook.com/
- PHPT docs: https://php.github.io/php-src/miscellaneous/running-tests.html
- PIE: https://php.github.io/pie/
- simdjson: https://github.com/simdjson/simdjson
- PHP simdjson: https://pecl.php.net/package/simdjson
- JSON Machine: https://github.com/halaxa/json-machine
