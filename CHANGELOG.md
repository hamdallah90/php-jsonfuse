# Changelog

All notable changes to JsonFuse are recorded here. Dates are UTC.

## 1.0.0 - 2026-11-11

First release.

### Features

- `JsonFuse\Plan::compile` and `JsonFuse\Runner::process`: a one-call NDJSON validate, filter and project pipeline with typed fields (`int64`, `float64`, `bool`, `string`, nullable variants), filters, a strict record-size cap and bounded native memory. `maxRecordBytes` defaults to 1,048,576 and ranges from 1 to 67,108,864.
- Field specs in array form, `'name' => ['type' => ..., 'default' => ...]`, and optional fields: a missing key emits the declared default, a present `null` never takes it. The default must have the declared type at compile time; a `float64` default may also be an int that binary64 holds exactly. Any other key in a field spec raises `PlanException` `unknown_option`.
- Nested field paths: `'name' => ['type' => ..., 'path' => ['a', 'b']]` reads a value 1 to 8 object levels deep, and the output stays flat. `where` entries take the same paths. Reason codes `invalid_path` and `path_not_object`; two fields with the same path raise `duplicate_name`, and `duplicate_key` covers a key repeated in any object on a plan path.
- `where` operators: an entry is a scalar (`['=' => value]`) or an array of an optional `path` and one or more of `=`, `>`, `>=`, `<`, `<=` and `in`, all ANDed. `in` takes a list of 1 to 256 scalars. Range bounds are numbers, compared with record integers and reals by exact value, or strings, compared with record strings bytewise after unescaping; other record kinds, raw big numbers included, never match a range.
- `JsonFuse\Stats` with `recordsRead`, `recordsMatched`, `recordsEmitted`, `bytesRead`, `bytesWritten`.
- `JsonFuse\PlanException`, `RecordException` and `StreamException`, all extending `JsonFuseException`. Every exception has a stable reason code (`getReason()`) and `getField()`; `PlanException` adds `getOption()`; `RecordException` and `StreamException` add `getInputLine()`, `getRecordNumber()`, `getByteOffset()` and `getProgress()`, a `Stats` of the counts at the throw. Messages never contain record bytes; their text is informational, so match on `getReason()`. The `line N (record R, offset O): reason` prefix is kept.
- After a record error, `read_failed` or `no_data`, the complete records buffered before the failure are written first, without `php_stream_flush`, and then the exception is raised. If that write fails, the exception is `StreamException` `write_failed` with the recorded exception as its previous; an exception thrown by a wrapper or filter during that write propagates with the recorded exception at the end of its `getPrevious()` chain.
- After a `Runner::process` call during which any fiber switch happened, both streams keep `PHP_STREAM_FLAG_NO_FCLOSE`: `fclose()` on them returns false with an `E_WARNING`, and a new run on them raises `StreamException`. Each stream is still freed when its last reference is released. This avoids a use-after-free in PHP core when a fiber is suspended inside a filter or wrapper callback of one of the streams. A module loaded with `dl()` cannot observe switches, so there every run leaves both flags set.
- A validating scan parses the records of plans whose paths all have one segment, without building a yyjson document. Records it does not accept (any syntax error, invalid UTF-8, a duplicate referenced key, an escaped top-level key, a RAW number in a referenced member, nesting deeper than 64) are parsed by yyjson, so results and messages do not change. `-DJF_DISABLE_SCAN` builds without it; `php --ri jsonfuse` reports `validating scan`.
- Vendored yyjson 0.13.0, unmodified, with its symbols hidden.
- Platforms: PHP 8.3 to 8.6, NTS and ZTS, Linux x86-64 and aarch64, and macOS arm64. PIE metadata in `composer.json` sets `support-zts` and the `linux` and `darwin` OS families.
- The source package (`git archive`) does not contain `tests/`, `fuzz/`, `benchmarks/` or `.github/`. The repository carries a PHPT suite, a libFuzzer harness over the native core, a multi-thread harness run under ThreadSanitizer, a benchmark harness against `json_decode`, PECL simdjson, `fastjson` and JSON Machine, and CI for every supported platform, including ASan/UBSan lanes.
- `SECURITY.md` and `RELEASING.md`.

### Performance

Measured on x86-64 (see `benchmarks/RESULTS.md`): 3.55x to 5.06x faster than a `json_decode` loop on 100k records and above, 2.08x to 2.63x faster than `fastjson` on narrow records at 100k records and above, and 1.53x to 1.71x faster than `fastjson` on wide records.

### Known limitations

See `docs/THREAT_MODEL.md`. In short: no general-purpose JSON use, and output is not transactional.
