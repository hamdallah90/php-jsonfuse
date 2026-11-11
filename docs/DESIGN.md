# JsonFuse design

This is a reader's guide to how the code meets `SPEC.md`. Where this document and the spec differ, the spec wins.

## Structure

| File | Role |
| --- | --- |
| `src/jsonfuse.c` | Module entry, class registration, version reporting. |
| `src/jf_plan.c`, `.h` | `Plan::compile`: validates the spec, builds the immutable compiled plan, the referenced-key hash table and the output prefixes. |
| `src/jf_runner.c`, `.h` | `Runner::process`: argument checks, stream flags, the per-record validation and filter loop, `Stats`. |
| `src/jf_stream.c`, `.h` | The bounded NDJSON line reader over PHP streams, and the after-every-stream-call checks. |
| `src/jf_parse.c`, `.h` | The yyjson record parser and its reused memory pool. The only place yyjson's reader is called. |
| `src/jf_writer.c`, `.h` | Serialization and the batched, short-write-tolerant output writer. |
| `src/jf_errors.c`, `.h` | Exception classes and their getters, reason codes, the recorded run failure and the exception built from it with the `line N (record R, offset O)` prefix, the `memory_limit` preflight. |
| `third_party/yyjson/` | yyjson 0.13.0, vendored unmodified, pinned in `PIN.txt`. |

## Object ownership

`Plan` is a Zend object holding a `jf_plan_data` struct, which owns its field names, escaped prefixes, the output bytes of each field's default, filter values and key table. A default is checked and turned into its output bytes during `compile`, so a run never reads the user's value. Its `free_obj` frees exactly the entries that `field_count` and `filter_count` record, so a partially built plan after a validation failure is freed correctly. `Plan` cannot be cloned or serialized. After `compile` returns, a plan is immutable.

`Stats` is a plain userland-visible object with five `readonly int` properties, set once from copied counters. It holds no native pointers and can be cloned and serialized.

`Runner::process` owns no objects beyond one call. `$plan` and both stream resources are kept alive by the call frame's argument slots; no pointer to them is stored anywhere that outlives the call.

## Memory lifecycle

Per-run state is one `jf_run` struct on the C stack, whose address is fixed before `zend_try`. All per-run buffers use `emalloc`. Three buffers grow during a run, and each is bounded:

| Buffer | Bound |
| --- | --- |
| Parser pool (`yyjson_alc_pool`) | `yyjson_read_max_memory_usage(maxRecordBytes)`, at most `12 * maxRecordBytes + 256` |
| Reader line buffer | `maxRecordBytes + 2 + 64 KiB + YYJSON_PADDING_SIZE` |
| Writer buffer | `maxRecordBytes + sum(field prefix lengths) + sum(default lengths) + 40 * fieldCount + 2` |

Before growing any of them, `jf_mem_fits` compares the request against `memory_limit` so a hostile record raises `RecordException` instead of reaching PHP's hard limit. The preflight is best effort: ZendMM's free-page state is private, so heavy fragmentation near the limit can still end in the normal memory fatal.

On every exit that returns through this frame, including a bailout caught by `zend_try`, `jf_run_release` frees the three buffers and clears `PHP_STREAM_FLAG_NO_FCLOSE` on both streams, unless a fiber switch happened during the run (the per-thread count kept by the MINIT observer moved), in which case both flags stay set. Nothing non-Zend is held across a call that can bail out. Exits that do not return through this frame are not covered; see the fiber, persistent-stream and `pclose()` sections of the [threat model](THREAT_MODEL.md#what-is-not-defended-against).

## Record processing

For each physical line:

1. **Framing.** `jf_reader_next` finds the next LF with `memchr` over bytes not yet scanned, excludes a trailing CR, and enforces `maxRecordBytes`. A line that grows past `maxRecordBytes + 2` bytes with no LF is refused before the buffer grows further.
2. **Parsing.** `jf_parser_fit` first grows the pool to fit the record, on both paths. For a flat plan, `jf_scan_record` then validates the record and fills `values[]` for the root nodes it finds (`jf_scan`, in `jf_parse.c`): strings skip eight plain bytes per step while inside the record, UTF-8 follows RFC 3629 and surrogates come in pairs, numbers follow the RFC 8259 grammar and are read by `yyjson_read_number`, containers below a referenced key are only validated, with their nesting kept in a 64-bit stack. The four bytes after the record are saved, zeroed so that every loop stops there, and restored; escaped strings are decoded in place only after the whole record is accepted. A root key is looked up only if its bit is set in a 4096-bit filter built per run from the plan's keys (length and edge bytes). Anything the scan does not accept, and every record of a plan with a nested path, goes to `jf_parse_record`, which runs yyjson with exactly `YYJSON_READ_INSITU | YYJSON_READ_BIGNUM_AS_RAW` and so reports every parse error. yyjson writes zero bytes after the record, so the four bytes following it are saved and restored around each parse: they may be the next record's. One document per record, freed with `yyjson_doc_free`; the pool is reused. `-DJF_DISABLE_SCAN` removes the scan. `-DJF_SCAN_STATS` (test builds only; the `.github/ci/sanitizer.sh` lanes use it) adds a per-thread count of accepted records, shown as the `validating scan accepted` info row, which `151`, `152`, `153` and `tests/jf_scan_diff.inc` check so that a scan that silently hands every record to yyjson fails; it changes nothing else.
3. **Keys.** The walk (`jf_walk`) visits the root object's members, and the members of every object reached through an inner path node, in document order, looking each key up in a compile-time table keyed on (parent node, key bytes). It keeps an explicit stack of at most 8 objects, because objects are entered only below inner nodes, and inner nodes are at most 7 levels deep, so its depth never follows the record's nesting. A flat plan (no path of two or more segments, so no inner node; a flag set at compile) runs a loop over the root instead, with no stack and a lookup whose mask is a constant: the general walk cost such plans about 7% of wall time on narrow records. Both report a duplicate through the same function. Every table has at least 512 slots, inline in the plan when it has exactly 512, so a key that names no node rarely meets an occupied slot. Every key is inspected on every record, so a duplicate of a referenced key found late is still an error. `yyjson_obj_get` is never used for referenced fields: it is first-wins and linear per lookup.
4. **Types.** Each declared field in declared order resolves its path (a present intermediate that is not an object is `path_not_object`), then: present (or missing with a default, which the writer then emits), null only if nullable, correct type.
5. **Filters.** Each `where` entry in declared order resolves its path like a field (`path_not_object`) and must have a scalar leaf if present (`where_not_scalar`); then the entries are ANDed, each holding only if every one of its operators does. `=` and `in` use type-strict equality; a one-operator `=` entry, the scalar shorthand, takes that comparison directly, without the operator loop. Number bounds compare exactly (`jf_cmp_number`, `jf_cmp_int_real`): two integers as integers, a UINT above `INT64_MAX` above every int64, two reals by IEEE order, and an integer against a real by the real's truncation, which int64 or uint64 holds exactly for every double in [-2^63, 2^64), then by the sign of its fraction. String bounds compare with `memcmp` over the shorter length, then by length. `in` is a linear scan of its members.
6. **Writing.** Matching records are serialized into the writer buffer whole, with a reservation made first, and written out when the batch is full or the run ends. The batch is normally 64 KiB, and larger after a big record.

Every step's failure raises with the record's line, record number and offset.

## Data semantics

The number table, duplicate-key rule, framing rules and error reasons are specified in `SPEC.md` section 5. Key points:

- Number output uses `yyjson_write_number`. `float64` values are rounded to the nearest binary64 silently, by design: precision loss is not an error, and a nonzero literal too small for binary64 underflows to zero. The one exception is inherited from yyjson: a literal with more than 768 significant digits that is an exact binary tie may round one ulp high. `float64` values are always written from a REAL-tagged value, so an integer `5` in a `float64` field emits `5.0`, and `-0` emits `0.0`. RAW big numbers are converted once more with `yyjson_read_number` to write them.
- Strings are written through a 256-entry escape table that matches `json_encode` with `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS`.
- Field names and `where` keys are compared after JSON escape decoding, so `"\u0069"` and `"i"` are the same key.

## Algorithmic complexity

For a record of `L` bytes and `n` keys: the framing scan is O(L) in total across the file (each byte is scanned once); parsing is O(L); the walk is O(L + n), because each key of an inspected object is hashed once and each object is entered at most once (a second entry of its node is a duplicate); a probe is bounded by the plan's node count, which records cannot change, and the table keeps a load factor of at most one third; validation of declared fields and filters is O(fields + path depth + operators), an `in` costing one comparison per member, so at most 16 x (256 + 5) comparisons per record; writing is O(output length). Memory is O(`maxRecordBytes`), independent of input size.

Reader compaction moves the unconsumed tail of the buffer with `memmove` on each refill. The tail is at most one incomplete record, and a record delivered in many short reads compacts only once, because the read position stays at the buffer start after that.

## Stream handling

`php_stream_read`, `php_stream_write`, `php_stream_flush` and `php_stream_eof` can run PHP (wrappers, filters, error handlers). After each one, the code checks `EG(exception)` and re-fetches both stream resources. No `php_stream *` is held across such a call. Output is written only as whole records: a `php_stream_write` that accepts part of a batch is retried for the rest, and only bytes accepted count toward `bytesWritten`.

Failures are recorded, not thrown, where they are decided (`jf_fail` stores the class, reason code, the plan's own name and the message in the run's position struct), and every failing path returns at once with no further stream call. The runner raises once, inside its guarded section (`jf_run_raise` in `src/jf_runner.c`). A record failure, `read_failed` or `no_data`, with no PHP exception pending, first writes the buffered records with the same write loop (`jf_writer_drain`) and no flush, so user wrappers and filters run normally; the record failure is kept in `run` meanwhile, so a bailout in that write still frees it. Exceptions are built whole before they are thrown, because a throw over a pending `UnwindExit` frees the new object. Bytes the stream layer accepted count even when a callback then throws, so `getProgress()` matches what reached the output.

A `php_user_filter` sets the count returned by a filtered write, and PHP returns it to the caller without checking it against the bytes offered. The writer clamps it, so an over-claiming filter cannot inflate `bytesWritten`.

## Formats and limits

| Limit | Value |
| --- | --- |
| Fields per plan | 128 |
| `where` entries per plan | 16 |
| `in` members per entry | 256 |
| Field name, path segment, string `where` value or operand, string default | 1024 bytes, valid UTF-8 |
| `maxRecordBytes` | 1 to 67,108,864; default 1,048,576 |
| JSON nesting | 511 levels, enforced by `YYJSON_READER_DEPTH_LIMIT=511` |

## Testing approach

PHPT cover observable behaviour. The native fuzz harness in `fuzz/` compiles the real `src/jf_*.c` against a small fake of the Zend APIs they use (`fuzz/shim/php.h`) so one process can run millions of inputs; it compares every run against a reference model built on yyjson alone (so it shares yyjson with the code under test) and also runs fault-injected and short-read variants. It does not cover Zend-specific paths: bailout, real streams, real `memory_limit`, fibers. Those are PHPT territory.

## Gaps for later versions

A record is one NDJSON line. These are the known gaps for schema features and nested JSON, from the "Not in this version" list in `SPEC.md` section 4. None is implemented or planned for this version.

- **Nested output and arrays.** Field paths read nested objects (1.0), but output stays flat and arrays are never indexed.
- **Arrays as top-level input.** A top-level JSON array of records is not streamed; input is NDJSON only.
- **Full JSON Schema.** Plans declare a flat map of field names to `int64`, `float64`, `bool`, `string` and nullable variants. There is no `$ref`, `oneOf`, pattern or length keyword; `where` has `=`, `in` and the four range operators, ANDed, and no OR or `!=`.
- **JSON Pointer.** No path syntax: a field or `where` path is a list of literal keys.
- **Transformations.** No arithmetic, date or regex operations, and no PHP callbacks on the record path.
- **Aggregation.** Records are filtered and projected one at a time; there are no counts per key, sums or groupings beyond `Stats`.
- **Parallel parsing.** One record is parsed at a time on one thread.
- **Compressed streams.** Only what the PHP stream layer provides is read; there is no built-in gzip or other decoder.
- **simdjson backend.** The parser is yyjson, vendored and pinned; no alternative backend exists.
- **Transactional writer.** Output is not rolled back after a failure; applications that need all-or-nothing output write to a temporary file.
- **Adapters and async APIs.** No HTTP or Swoole adapter, and no async or non-blocking API.
