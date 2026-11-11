# JsonFuse

A native PHP extension that reads NDJSON from one stream, validates each record against a declared schema, filters and projects it, and writes NDJSON to another stream. One `Plan::compile` and one `Runner::process` call replace a loop that decodes every record into PHP arrays.

**Version `1.0.0`.**

## What it is, and what it is not

JsonFuse does one job: validate, filter and project line-delimited JSON objects, in one native call, with memory bounded by the largest record rather than by the size of the file.

- Input: NDJSON only, one JSON object per line. A top-level JSON array is an error.
- Output: NDJSON with the declared fields, in the declared order.
- Schema: `int64`, `float64`, `bool`, `string`, optionally nullable (`int64?`). A field is required unless it has a default; a field with a default is optional.
- Filter: AND of conditions on scalars: equality, ranges (`<`, `<=`, `>`, `>=`) and `in` lists, on top-level or nested keys.

JsonFuse is **not a general JSON library.** There is no `decode`, no `encode`, no streaming iterator, no nested output (nested keys can be read, but the output stays flat), no JSONPath and no transformation step. If you want a faster general JSON encoder and decoder for PHP, use [`iliaal/fastjson`](https://github.com/iliaal/fastjson): it is a better fit for that job than JsonFuse, which does not try to do it. `json_decode` remains the right tool when you need the data as PHP values.

JsonFuse is worth considering only when the shape of your job is exactly: read many JSON lines, keep some of them, write some fields back out.

## Prerequisites

- Linux on x86-64 or aarch64, or macOS on arm64. Other platforms are untested.
- PHP 8.3, 8.4 or 8.5, NTS or ZTS, with the `phpize` toolchain (`php-dev`, or the official `php` images). PHP 8.6 has been tested only against a release candidate.
- A C11 compiler. yyjson is vendored; there are no other dependencies.

## Build and install

From a source checkout:

```sh
phpize
./configure --enable-jsonfuse
make -j"$(nproc)"
make test NO_INTERACTION=1 TESTS=tests     # optional
sudo make install
```

Then add `extension=jsonfuse` to `php.ini`, or load it for one command with `php -d extension=modules/jsonfuse.so`.

With [PIE](https://github.com/php/pie), once the package is published:

```sh
pie install hamdallah90/jsonfuse:^1.0
```

On minimal images such as `php:8.3-cli`, install `libtool` first, because PIE builds with `phpize`.

The PIE metadata is in `composer.json`. It declares Linux and macOS, NTS and ZTS, and PHP 8.3 up to 8.6.

### Check that it is loaded

```sh
php -r 'var_dump(extension_loaded("jsonfuse"));'
```

```text
bool(true)
```

```sh
php --ri jsonfuse
```

```text

jsonfuse

jsonfuse support => enabled
version => 1.0.0
yyjson => 0.13.0
validating scan => enabled
```

`php --ri jsonfuse` reports the extension version, the vendored yyjson version, and whether the validating scan is compiled in (see Compatibility).

## Usage

Every example below runs as written; the output shown is what it prints.

### Keep some records, write some fields

```php
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile([
    'fields' => ['user_id' => 'int64', 'amount' => 'float64'],
    'where'  => ['event' => 'purchase'],
]);

$in = fopen('php://memory', 'w+b');
fwrite($in, <<<'NDJSON'
{"event":"view","user_id":1,"amount":0}
{"event":"purchase","user_id":2,"amount":19.99,"currency":"EUR"}
{"event":"purchase","user_id":3,"amount":5}

NDJSON);
rewind($in);
$out = fopen('php://output', 'wb');

$stats = Runner::process($in, $out, $plan);
printf("read %d, matched %d, emitted %d, wrote %d bytes\n",
    $stats->recordsRead, $stats->recordsMatched, $stats->recordsEmitted, $stats->bytesWritten);
```

```text
{"user_id":2,"amount":19.99}
{"user_id":3,"amount":5.0}
read 3, matched 2, emitted 2, wrote 56 bytes
```

Output fields follow the plan's order, and a whole `float64` is written with `.0`.

### From a file to a file, all or nothing

JsonFuse does not roll back output it has written. To publish a result only when the whole run succeeded, write to a temporary file and rename it:

```php
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;

$dir = sys_get_temp_dir();
file_put_contents("$dir/events.ndjson",
    '{"event":"purchase","user_id":7,"amount":12.5}' . "\n" .
    '{"event":"refund","user_id":7,"amount":12.5}' . "\n");

$plan = Plan::compile([
    'fields' => ['user_id' => 'int64', 'amount' => 'float64'],
    'where'  => ['event' => 'purchase'],
]);

$in = fopen("$dir/events.ndjson", 'rb');
$out = fopen("$dir/purchases.ndjson.tmp", 'wb');
try {
    Runner::process($in, $out, $plan);
} finally {
    fclose($in);
    fclose($out);
}
// Reached only when the run succeeded, so purchases.ndjson is never partial.
rename("$dir/purchases.ndjson.tmp", "$dir/purchases.ndjson");
echo file_get_contents("$dir/purchases.ndjson");
```

```text
{"user_id":7,"amount":12.5}
```

### Any PHP stream, compressed input included

```php
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;

$dir = sys_get_temp_dir();
file_put_contents("$dir/logs.ndjson.gz", gzencode(
    '{"level":"error","msg":"disk full","host":"a"}' . "\n" .
    '{"level":"info","msg":"started","host":"b"}' . "\n"));

$plan = Plan::compile(['fields' => ['host' => 'string', 'msg' => 'string'], 'where' => ['level' => 'error']]);

$in = fopen("compress.zlib://$dir/logs.ndjson.gz", 'rb');
$out = fopen('php://stdout', 'wb');
Runner::process($in, $out, $plan);
```

```text
{"host":"a","msg":"disk full"}
```

Both arguments are ordinary PHP streams, such as files, `php://memory`, `php://stdin` and `php://stdout`, and streams with wrappers or filters. Non-blocking streams are not supported. The two must be different streams, and neither is closed by the extension.

### Plan options

| Option | Type | Default | Meaning |
| --- | --- | --- | --- |
| `fields` | array, required | | Field name to type (`int64`, `float64`, `bool`, `string`, with optional trailing `?`), or to a field spec array (below). 1 to 128 fields. Output order follows this array. |
| `where` | array | `[]` | Up to 16 entries, each a scalar (`string\|int\|bool\|null\|finite float`, exact type-strict equality) or an operator array (below). All entries must match. |
| `output` | string | `'ndjson'` | Only `'ndjson'` exists. |
| `maxRecordBytes` | int | 1 MiB | 1 to 64 MiB. Longer records raise `RecordException`. |
| `skipEmptyLines` | bool | `false` | When false, a blank line is a `RecordException`. |

### Nested fields, nullable fields and defaults

```php
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile(['fields' => [
    'id'       => 'int64',
    'city'     => ['type' => 'string?', 'path' => ['address', 'city'], 'default' => null],
    'tier'     => ['type' => 'string', 'default' => 'free'],
    'discount' => ['type' => 'float64', 'default' => 0],   // written as 0.0
]]);

$in = fopen('php://memory', 'w+b');
fwrite($in, <<<'NDJSON'
{"id":1,"address":{"city":"Lyon"},"tier":"pro","discount":2.5}
{"id":2,"address":{"zip":"10115"}}
{"id":3,"address":{"city":null},"tier":"team"}

NDJSON);
rewind($in);
Runner::process($in, fopen('php://output', 'wb'), $plan);
```

```text
{"id":1,"city":"Lyon","tier":"pro","discount":2.5}
{"id":2,"city":null,"tier":"free","discount":0.0}
{"id":3,"city":null,"tier":"team","discount":0.0}
```

A field spec array takes the keys `type` (required), `path` and `default` (optional); `'a' => 'int64'`, `'a' => ['type' => 'int64']` and `'a' => ['type' => 'int64', 'path' => ['a']]` are the same plan. Any other key, an int key included, raises `PlanException` `unknown_option`; a missing or invalid `type` raises `invalid_type`. The keys are checked first, then the type, the path and the default.

A `path` is a list of 1 to 8 strings, each a literal object key of valid UTF-8 and at most 1024 bytes, compared with record keys after escape decoding: `['address', 'city']` reads `{"address":{"city":...}}`, `'x.y'` is one key, and an int segment raises `invalid_path`, so `['items', 0]` is never an array index. The output stays flat: the output key is the field's name. A present intermediate that is not an object, `null` included, raises `RecordException` `path_not_object`, even when the field has a default; a missing key at any level makes the field missing (its default, or `missing_field`). A key repeated in any object on a plan path raises `duplicate_key` when it is the next segment or the leaf of a path under that parent; repeats elsewhere are accepted. Two fields with the same path raise `duplicate_name`, and a path that is a strict prefix of another plan path, in `fields` or `where`, raises `invalid_path`, both naming the later entry. A path that is not a list of 1 to 8 strings raises `invalid_path`; a segment that is not valid UTF-8 or is over 1024 bytes raises `invalid_name`. `where` entries take a `path` by the same rules.

A field with a `default` is optional: when the key is missing from a record, the default is written in its place. A JSON `null` is never replaced: it is written as `null` for a nullable type and raises `null_field` otherwise. `where` never sees defaults. The default must already have the declared type, with no conversion: an int for `int64`, a bool for `bool`, a valid UTF-8 string of at most 1024 bytes for `string`, and `null` only for a nullable type. A `float64` default is a finite float, or an int that binary64 holds exactly (every int up to 2^53 in magnitude, and some larger ones such as 2^60 and `PHP_INT_MIN`; not 2^53 + 1 or `PHP_INT_MAX`), written in float form. Anything else raises `invalid_value`. A `default` key holding `null` counts as a default. The default's output bytes are built when the plan is compiled, through the same number writer and escape table as record values.

### Filters: equality, ranges, lists and nested keys

A `where` entry is a scalar, which means `['=' => scalar]`, or an array of an optional `path` and one or more operators, all of which must hold:

```php
<?php
use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile([
    'fields' => ['order' => 'int64'],
    'where'  => [
        'event'   => 'purchase',                         // the same as ['=' => 'purchase']
        'amount'  => ['>=' => 10, '<' => 1000.0],
        'day'     => ['>=' => '2026-11-01', '<' => '2026-12-01'],
        'country' => ['path' => ['address', 'country'], 'in' => ['DE', 'FR', 'NL']],
    ],
]);

$in = fopen('php://memory', 'w+b');
fwrite($in, <<<'NDJSON'
{"order":1,"event":"purchase","amount":25,"day":"2026-11-03","address":{"country":"FR"}}
{"order":2,"event":"purchase","amount":5,"day":"2026-11-03","address":{"country":"FR"}}
{"order":3,"event":"purchase","amount":99.5,"day":"2026-12-01","address":{"country":"DE"}}
{"order":4,"event":"purchase","amount":120,"day":"2026-11-30","address":{"country":"NL"}}
{"order":5,"event":"purchase","amount":40,"day":"2026-11-15","address":{"country":"US"}}

NDJSON);
rewind($in);
Runner::process($in, fopen('php://output', 'wb'), $plan);
```

```text
{"order":1}
{"order":4}
```

- `=` takes one scalar and is the exact type-strict equality of a scalar entry: `1` never matches `1.0` or `"1"`, `null` matches only JSON `null`, strings compare as bytes after escape decoding. `in` takes a list of 1 to 256 such scalars and matches when any member does.
- `>`, `>=`, `<` and `<=` take a number (an int or a finite float) or a string of valid UTF-8 of at most 1024 bytes, and the range operators of one entry take all numbers or all strings. A number bound matches a record integer or real by exact value: integers compare as integers, signed and unsigned, an integer against a real compares exactly (no conversion to float), `-0.0` equals `0`. A string bound matches a record string by its unescaped bytes as unsigned values, the shorter of two strings with a common prefix first, which is code point order with no collation. Any other record kind, a big number that yyjson keeps raw (`1e400`, 30 digits) included, does not match a range and is not an error. ISO 8601 timestamps sort correctly only in one fixed format: `2026-11-09T10:00:00.5Z` sorts before `2026-11-09T10:00:00Z`.
- Contradictory operators such as `['=' => 1, '>' => 5]` are accepted and never match.
- Plan errors: any other key in an entry, an int key included, raises `unknown_option`; an entry with no operator, a bad operand, an empty or non-list `in`, or number and string range bounds in one entry raise `invalid_value`; more than 256 `in` members raise `too_many`. Keys are checked first, then the path, then the operands in declared order (an `in` list's shape, then its count, then its members), and last that the entry has an operator and that its range bounds share one kind.
- On a record, a present intermediate on a `where` path that is not an object raises `path_not_object`, a present leaf that is an array or object raises `where_not_scalar`, and a missing key at any level does not match. `where` never sees field defaults.

### Errors

```php
<?php
use JsonFuse\Plan;
use JsonFuse\PlanException;
use JsonFuse\RecordException;
use JsonFuse\Runner;

try {
    Plan::compile(['fields' => ['id' => 'int']]);
} catch (PlanException $e) {
    printf("plan: %s (option %s, field %s)\n", $e->getReason(), $e->getOption(), $e->getField());
}

$plan = Plan::compile(['fields' => ['id' => 'int64']]);
$in = fopen('php://memory', 'w+b');
fwrite($in, "{\"id\":1}\n{\"id\":2}\n{\"id\":\"3\"}\n{\"id\":4}\n");
rewind($in);
$out = fopen('php://memory', 'w+b');
try {
    Runner::process($in, $out, $plan);
} catch (RecordException $e) {
    printf("record: %s, field %s, line %d, record %d, byte offset %d\n",
        $e->getReason(), $e->getField(), $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset());
    printf("emitted before the error: %d\n", $e->getProgress()->recordsEmitted);
    echo $e->getMessage(), "\n";
}
rewind($out);
echo stream_get_contents($out);
```

```text
plan: invalid_type (option fields, field id)
record: type_mismatch, field id, line 3, record 3, byte offset 18
emitted before the error: 2
line 3 (record 3, offset 18): field "id": expected int64, got string
{"id":1}
{"id":2}
```

The records before the bad one were written; the run stopped at it. Match on `getReason()`: the message text may change between versions. A longer runnable example is in [`examples/filter_events.php`](examples/filter_events.php).

## What the extension adds at run time

Seven classes in the `JsonFuse` namespace. It adds no functions, constants, interfaces, enums or ini settings. Inherited `Exception` methods are not listed.

- `final class JsonFuse\Plan`: a compiled, immutable plan: field types, paths, defaults and filters. No public constructor; cannot be cloned or serialized.
  - `public static function compile(array $spec): Plan`: validates the options (see Usage) and compiles them; throws `PlanException`.
- `final class JsonFuse\Stats`: the counts of one run, returned by `Runner::process()` and by `getProgress()`. No public constructor.
  - `public readonly int $recordsRead`: lines read as records; lines skipped by `skipEmptyLines` are not counted.
  - `public readonly int $recordsMatched`: records that passed every `where` entry.
  - `public readonly int $recordsEmitted`: records whose complete output line the output stream accepted.
  - `public readonly int $bytesRead`: input bytes consumed, line terminators and skipped lines included.
  - `public readonly int $bytesWritten`: bytes the output stream accepted.
- `final class JsonFuse\Runner`: runs a plan over two streams. No public constructor.
  - `public static function process($input, $output, Plan $plan): Stats`: reads NDJSON from `$input` and writes the declared fields of each matching record to `$output`; throws `RecordException` or `StreamException`. Neither stream is closed.
- `class JsonFuse\JsonFuseException extends \RuntimeException`: base class of every exception the extension throws.
  - `public function getReason(): string`: a stable reason code such as `type_mismatch`; match on this, not on the message.
  - `public function getField(): ?string`: the plan's field or `where` name involved, or null.
- `class JsonFuse\PlanException extends JsonFuseException`: thrown by `Plan::compile()` for an invalid plan.
  - `public function getOption(): ?string`: the top-level option at fault, such as `fields`, or null.
- `class JsonFuse\RecordException extends JsonFuseException`: thrown by `Runner::process()` for a record that is too large, is not a valid JSON object, or does not fit the plan.
  - `public function getInputLine(): int`: the 1-based physical input line of the record.
  - `public function getRecordNumber(): int`: the 1-based record number; skipped blank lines are not counted.
  - `public function getByteOffset(): int`: the offset of the line's first byte, counted from where this call started reading.
  - `public function getProgress(): Stats`: a `Stats` with the counts at the time of the error.
- `class JsonFuse\StreamException extends JsonFuseException`: thrown by `Runner::process()` when a stream cannot be used, read from or written to.
  - `public function getInputLine(): int`: the input line reached when the error happened.
  - `public function getRecordNumber(): int`: the number of the record reached when the error happened.
  - `public function getByteOffset(): int`: the input byte offset reached when the error happened.
  - `public function getProgress(): Stats`: a `Stats` with the counts at the time of the error.

For IDE autocomplete and static analysis, point your tools at [`stubs/jsonfuse.stub.php`](stubs/jsonfuse.stub.php), which declares every class and method above with its types.

## Guarantees

- Every declared field and every `where` key is validated on every record, before the record is filtered. A bad record that would not have matched still raises.
- JSON syntax and UTF-8 are fully validated. A record that fails raises `JsonFuse\RecordException` with its line, record and offset, and never includes the record's bytes in the message.
- Every exception has a stable reason code, `getReason()`, and `getField()` (the plan's own name, or null). `PlanException::getOption()` names the option. `RecordException` and `StreamException` add `getInputLine()`, `getRecordNumber()`, `getByteOffset()` and `getProgress()`, a `Stats` of the counts at the throw. Message text is informational and may change; match on `getReason()`.
- Memory is bounded by `maxRecordBytes`, not by input size. Peak native memory is at most `12 * maxRecordBytes + 256` for the parser, plus `maxRecordBytes + 2 + 64 KiB + 4` for the reader, plus `maxRecordBytes + sum(field prefix lengths) + sum(default lengths) + 40 * fieldCount + 2` for the writer, where a field's prefix is its JSON-escaped name plus 4 bytes and a default's length is its exact output, quotes and escapes included: about 14.1 MiB at the 1 MiB default without defaults. A per-record native parse tree is built and freed; this is not a zero-copy or event-driven parser.
- Output contains whole records only. After a record error, `read_failed` or `no_data`, the complete records buffered before it are written first (without a flush), then the exception is raised; if that write fails, the `StreamException` (`write_failed`) carries the record error as its previous exception. After any other error nothing more is written. Output already written remains: JsonFuse does not roll back. If you need all or nothing, write to a temporary file and `rename()` it after `process` returns.
- Output is byte-exact for strings and field order: a decoded output line equals the projected record. Numbers use the shortest round-trip form with a `.0` on whole `float64` values, for example `1.0`.
- Neither input nor output stream is ever closed by the extension. Both streams must be different resources. Non-blocking streams are not supported.

Full semantics, including number handling, duplicate keys and framing, are in `SPEC.md` section 5.

### The writer bound

The writer never checks bounds byte by byte. Before it serializes a matched record it reserves `rec_len + sum(field prefix lengths) + sum(default lengths) + 40 * fieldCount + 2` bytes and writes the whole line into that room. Why this always suffices: the braces and LF take 2 bytes once the first prefix drops its comma; each prefix is written once; a present value is either a number, bool or `null` of at most 40 bytes, or a string whose escaped output is never longer than its own token in the record (every byte the table escapes needs an escape of at least the same length in valid JSON), and the projected tokens are distinct parts of the record, because two fields may not resolve to one name; a missing key writes its default instead, which is counted in full. Nested paths keep the tokens distinct: two fields with the same path raise `duplicate_name`, a path that is a strict prefix of another raises `invalid_path`, so no field's value lies inside another's, and the walk takes one value per path node, a repeated key being `duplicate_key`. A nested value is a token inside the record, so it is covered by `rec_len` like a top-level one; output names come from the plan's prefixes, never from the path; and a path field's default is counted in `sum(default lengths)` like any other. Paths add nothing to the bound. `tests/137_paths_defaults_writer_bound.phpt` repeats the 787,714-byte case with every field on its own 8-segment path. A string default is counted byte by byte through the escape table, so 1024 bytes of `\x01` count as 6,146 with the quotes. The largest reservation a plan can have, 128 fields of 1024-byte names and defaults that escape six-fold under the 64 MiB cap, is 68,687,618 bytes (65.5 MiB), and a static assertion in `src/jf_writer.c` shows it cannot wrap. `tests/125_defaults_writer_bound.phpt` writes 787,714-byte lines from 2-byte records this way under ASan, and the fuzz harness checks every output line against the reservation.

## Limits you should know about

Read `docs/THREAT_MODEL.md` before running this on untrusted input. The ones that surprise people:

- If any fiber switch happens during a `process` call (for example inside an output wrapper), both streams stay flagged afterwards: `fclose()` on them returns false with a warning and a new run on them raises `StreamException`; drop the last reference to free them. On PHP 8.4 and later a garbage collection that runs destructors in a fiber during the call counts as a switch. With the module loaded through `dl()` the switches cannot be observed, so every run leaves both streams flagged. See the threat model.
- `pclose()` of a stream from its own callback is a use-after-free in PHP core that this extension cannot prevent.
- A `php_user_filter` on `$output` runs PHP during writes and can throw; that exception propagates unchanged.
- The memory preflight is best effort: a heavily fragmented heap close to `memory_limit` can still end in PHP's normal memory fatal.
- A `float64` literal with more than 768 significant digits that is an exact binary tie may round one ulp high, inherited from yyjson 0.13.0.

## Compatibility

| | |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 NTS (each CI job builds and runs the full PHPT suite). 8.6 is tested against the 8.6.0RC4 release candidate, built from source as NTS. |
| ZTS | Supported on 8.3 to 8.6. CI runs the full PHPT suite on a ZTS build of each, and a multi-thread harness (`tests/zts/jf_threads.c`: 8 threads, each with its own request on the embed SAPI) under ThreadSanitizer. |
| OS / arch | Linux x86-64 and aarch64; macOS arm64 (8.3 and 8.6 in CI, where `084` and `105` skip for want of `/dev/full`). Other platforms are untested. |
| JSON parser | yyjson 0.13.0, vendored unmodified, symbols hidden |
| Flat-plan parser | A validating scan reads records of plans whose paths all have one segment and hands anything it does not accept to yyjson, so output, `Stats`, exceptions and messages are those of yyjson. Build with `CFLAGS=-DJF_DISABLE_SCAN` to use yyjson only; `php --ri jsonfuse` shows `validating scan => enabled` or `disabled`. |

## Performance

Measured on a pre-release build, on an Intel Xeon Platinum 8358 (2.60 GHz), x86-64, KVM guest, PHP 8.3.36 NTS with opcache and JIT on, on a host shared with another tenant's benchmark (CPUs 0 to 9 used for these runs). The workload is the full pipeline: read NDJSON, validate, filter, project and write NDJSON, with byte-identical output checked against every baseline. Full tables, method and caveats: `benchmarks/RESULTS.md`.

- Against a `json_decode` + PHP + `json_encode` loop, JsonFuse was 2.6x to 5.1x faster across the 18 workloads measured, and 3.5x to 5.1x faster at 100k records and above.
- Against PECL simdjson 4.0.0, JsonFuse was 1.56x to 4.18x faster. Against `iliaal/fastjson` 0.10.2, the fastest baseline in every workload, it was 1.49x to 2.63x faster.
- Wide records (100 fields, 3 projected): JsonFuse is only 1.53x to 1.71x faster than `fastjson`, and does not reach 2x.
- Narrow records at 100k records and above: 2.08x to 2.63x faster than `fastjson`. The margin is thin at 10% and 1% match rates (2.08x to 2.18x), and a different host load or CPU could move it under 2x. At 10k records the ratio was between 1.49x and 2.15x, so short inputs gain less.
- Against JSON Machine 1.2.6, JsonFuse was 21.5x (wide) to 23.8x (narrow) faster at 100k records, 3 runs.
- Memory: no comparative claim. Every implementation sat near PHP's floor, about 25 to 28 MiB max RSS, whatever the file size.
- Safety bound: with a 1 MiB record cap, 2 GiB of 1 MiB records peaked at 27,840 to 28,080 KiB RSS (27.2 to 27.4 MiB), and a single 64 MiB line was refused at the cap with `RecordException` with 0 records read, at 27,600 KiB (27.0 MiB). Both stayed under the asserted bound of 96,324 KiB, which includes an 80 MiB allowance. Each oversized record had 4 keys plus one huge string, the best case for parser memory, so other record shapes are not covered. The baselines were not run on these workloads.
- Support for range operators, `in` filters and `where` paths makes plans that use none of them 1.2% to 1.7% slower on narrow records (wide records: within 1%).
- Not measured: cold cache, concurrent workers, JIT-off with opcache disabled, parser-only (0% match) workloads, non-x86-64 CPUs, and any host other than this one shared KVM guest.

JsonFuse is a one-call NDJSON validate, filter and project pipeline, not a general JSON library. For general encode and decode use `fastjson`. JsonFuse also rejects a duplicate referenced key, which the baselines accept (last wins).

No baseline was faster than JsonFuse in any workload measured, but the gain over `fastjson` is under 2x on wide records and on short inputs, as stated above.

## Documentation

- `SPEC.md`: authoritative behaviour.
- `docs/DESIGN.md`: ownership, memory lifecycle, complexity, formats.
- `docs/THREAT_MODEL.md`: attacker-controlled inputs and non-goals.
- `benchmarks/README.md`: how to run the benchmarks and read the results.
- `CONTRIBUTING.md`: how to change this code.

## License

MIT, see `LICENSE`. yyjson is MIT and retains its own notice in `third_party/yyjson/LICENSE`.
