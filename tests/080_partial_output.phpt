--TEST--
jsonfuse: after a record error or an input failure the output is a line-complete prefix of the earlier emitted records, never a partial or failing record
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\RecordException;
use JsonFuse\Runner;
use JsonFuse\StreamException;

/*
 * SPEC.md section 5: output may contain a valid prefix after an error; complete records still buffered
 * when a later record fails are discarded, so the output is a line-complete prefix of the emitted
 * records. How many of the earlier records reached the stream is not specified, so no case below
 * asserts a count, except where the writer reservation rule (section 6) bounds what can be withheld.
 * Lines are judged by decoding them, so the whitespace of the output form does not matter here.
 */

/** Plan: id, s projected; g is a where-only key, so records with g = 2 are skipped. */
function plan(array $over = []): Plan
{
    return Plan::compile($over + ['fields' => ['id' => 'int64', 's' => 'string'], 'where' => ['g' => 1]]);
}

/** Record i: matches (g = 1) except every 5th, which carries g = 2 and is not emitted. */
function rec(int $i): string
{
    return '{"id":' . $i . ',"s":"v' . $i . '","g":' . ($i % 5 === 0 ? 2 : 1) . ',"x":[1,{"y":null}]}';
}

/** Ids of the n records that are emitted, in order. */
function emitted_ids(int $n): array
{
    return array_values(array_filter(range(1, max($n, 1)), fn($i) => $i <= $n && $i % 5 !== 0));
}

/**
 * 'ok' when $out is a line-complete prefix of the lines for $ids: empty, or LF-terminated, every line a
 * JSON object {id: ids[k], s: "v<id>"} with its keys in schema order, and no more lines than ids.
 */
function judge(string $out, array $ids): string
{
    if ($out === '') {
        return 'ok';
    }
    if (substr($out, -1) !== "\n") {
        return 'partial last line';
    }
    $lines = explode("\n", substr($out, 0, -1));
    if (count($lines) > count($ids)) {
        return 'more lines than emitted records: ' . count($lines) . ' > ' . count($ids);
    }
    foreach ($lines as $k => $line) {
        $d = json_decode($line, true);
        if (!is_array($d) || array_keys($d) !== ['id', 's']) {
            return "line $k is not an object with keys id, s";
        }
        if ($d['id'] !== $ids[$k] || $d['s'] !== 'v' . $ids[$k]) {
            return "line $k holds another record";
        }
    }
    return 'ok';
}

/** Build the input: $n good records, the bad text, then $after more good records. */
function build(int $n, string $bad, int $after, string $term = "\n"): array
{
    $data = '';
    for ($i = 1; $i <= $n; $i++) {
        $data .= rec($i) . $term;
    }
    $offset = strlen($data);
    $data .= $bad . $term;
    for ($i = $n + 2; $i < $n + 2 + $after; $i++) {
        $data .= rec($i) . $term;
    }
    return [$data, $offset];
}

function msg($e): string
{
    return $e instanceof Throwable ? $e->getMessage() : '';
}

/** Run, returning [class, exception|stats, output]. */
function go(string $data, ?Plan $plan = null, $out = null): array
{
    $out ??= jf_out();
    $in = jf_mem($data);
    try {
        $r = ['none', Runner::process($in, $out, $plan ?? plan())];
    } catch (Throwable $e) {
        $r = [get_class($e), $e];
    }
    fclose($in);
    $r[] = jf_out_bytes($out);
    fclose($out);
    return $r;
}

$bad = [
    'type error in id' => '{"id":"777777","s":"a","g":1}',
    'missing field s' => '{"id":777777,"g":1}',
    'null in a non-nullable field' => '{"id":777777,"s":null,"g":1}',
    'duplicate referenced key id' => '{"id":777777,"id":2,"s":"a","g":1}',
    'duplicate where key g' => '{"id":777777,"s":"a","g":1,"g":1}',
    'where key is an array, every field valid' => '{"id":777777,"s":"a","g":[1]}',
    'where key is an object, every field valid' => '{"id":777777,"s":"a","g":{"k":1}}',
    'trailing comma' => '{"id":777777,"s":"a","g":1,}',
    'truncated object' => '{"id":777777,"s":"a","g":1',
    'root is an array' => '[777777]',
    'root is a number' => '777777',
    'lone surrogate escape in an unreferenced key' => '{"id":777777,"s":"a","g":1,"\ud800":1}',
    'invalid UTF-8 in the value' => "{\"id\":777777,\"s\":\"a\xff\",\"g\":1}",
    'UTF-8 BOM before the record' => "\xEF\xBB\xBF" . '{"id":777777,"s":"a","g":1}',
    'NUL byte outside a string' => "{\"id\":777777,\"s\":\"a\",\"g\":1}\0",
    'blank line (skipEmptyLines false)' => '',
    'whitespace-only line' => '   ',
    '512 levels of nesting' => '{"id":777777,"s":"a","g":1,"u":' . str_repeat('[', 511) . str_repeat(']', 511) . '}',
];
$capOnly = ['record over the size cap' => '{"id":777777,"s":"' . str_repeat('a', 60) . '","g":1}'];

function expect_ctx(int $n, int $offset): string
{
    return sprintf('line=%d record=%d offset=%d', $n + 1, $n + 1, $offset);
}

echo "-- a record error after N good records, default size cap\n";
foreach ($bad as $label => $text) {
    $res = [];
    foreach ([0, 1, 2, 4, 50, 3000] as $n) {
        foreach ([2, 0] as $after) {
            [$data, $offset] = build($n, $text, $after);
            [$class, $e, $out] = go($data);
            $res[] = $class === RecordException::class && jf_ctx(msg($e)) === expect_ctx($n, $offset) ? 'ok' : "$class " . jf_ctx(msg($e));
            $res[] = judge($out, emitted_ids($n));
            $res[] = str_contains($out, '777777') ? 'failing record written' : 'ok';
            $res[] = $n === 0 && $out !== '' ? 'output not empty for the first record' : 'ok';
            $res[] = str_contains(msg($e), '777777') ? 'message leaks record bytes' : 'ok';
        }
    }
    $summary = array_unique($res);
    echo str_pad($label, 50), $summary === ['ok'] ? 'ok (12 runs)' : 'FAIL ' . implode(' | ', $summary), "\n";
}

echo "-- the same with maxRecordBytes 64, which keeps the writer small\n";
foreach ($bad + $capOnly as $label => $text) {
    $res = [];
    foreach ([0, 1, 2, 4, 50, 3000] as $n) {
        foreach ([2, 0] as $after) {
            [$data, $offset] = build($n, $text, $after);
            [$class, $e, $out] = go($data, plan(['maxRecordBytes' => 64]));
            $res[] = $class === RecordException::class && jf_ctx(msg($e)) === expect_ctx($n, $offset) ? 'ok' : "$class " . jf_ctx(msg($e));
            $res[] = judge($out, emitted_ids($n));
            $res[] = str_contains($out, '777777') ? 'failing record written' : 'ok';
            $res[] = $n === 0 && $out !== '' ? 'output not empty for the first record' : 'ok';
        }
    }
    $summary = array_unique($res);
    echo str_pad($label, 50), $summary === ['ok'] ? 'ok (12 runs)' : 'FAIL ' . implode(' | ', $summary), "\n";
}

echo "-- CRLF input and skipped blank lines before the failure\n";
foreach ([0, 3, 200] as $n) {
    [$data, $offset] = build($n, '{"id":777777,"s":1,"g":1}', 1, "\r\n");
    [$class, $e, $out] = go($data);
    echo str_pad("CRLF, $n good records", 50), $class, ' ', judge($out, emitted_ids($n)), ' ', str_contains($out, "\r") ? 'CR in output' : 'no CR', "\n";
}
$data = "\n" . rec(1) . "\n\n" . rec(2) . "\n\n\n" . rec(3) . "\n{\"id\":777777,\"s\":false,\"g\":1}\n" . rec(6) . "\n";
[$class, $e, $out] = go($data, plan(['skipEmptyLines' => true]));
echo str_pad('blank lines skipped, then a type error', 50), $class, ' ', jf_ctx(msg($e)), ' ', judge($out, [1, 2, 3]), "\n";

echo "-- bounded buffering: with a 64 byte cap the writer holds back less than a handful of records\n";
[$data] = build(3000, '[]', 0);
[$class, $e, $out] = go($data, plan(['maxRecordBytes' => 64]));
$full = 0;
foreach (emitted_ids(3000) as $id) {
    $full += strlen('{"id":' . $id . ',"s":"v' . $id . "\"}\n");
}
echo $class, ' ', judge($out, emitted_ids(3000)), ' withheld at most 256 bytes: ', var_export($full - strlen($out) <= 256 && $full - strlen($out) >= 0, true), ' (of ', $full > 20000 ? 'more than 20000' : 'few', " bytes)\n";

echo "-- a record error with a sink that takes one byte per write\n";
foreach ([0, 1, 40] as $n) {
    [$data] = build($n, '{"id":777777,"s":null,"g":1}', 1);
    $out = jf_sink(['max' => 1]);
    $in = jf_mem($data);
    try {
        Runner::process($in, $out, plan());
        $class = 'none';
    } catch (Throwable $e) {
        $class = get_class($e);
    }
    $k = JfSink::$last;
    echo str_pad("$n good records", 50), $class, ' ', judge($k->data, emitted_ids($n)), "\n";
    fclose($in);
    fclose($out);
}

echo "-- input failures in the middle of a run\n";
$input = '';
for ($i = 1; $i <= 3000; $i++) {
    $input .= rec($i) . "\n";
}
$ids = emitted_ids(3000);
$boom = new JfBoom('boom-in');
foreach ([
    'stream_read throws' => ['throw_read', $boom],
    'stream_eof throws' => ['throw_eof', $boom],
    'stream_read returns false' => ['false_read', null],
    'stream_read returns empty, eof false' => ['empty_read', null],
] as $label => [$mode, $exc]) {
    foreach (['late' => strlen($input) - 100, 'half' => intdiv(strlen($input), 2), 'early' => 30000] as $when => $after) {
        $in = jf_wrap($input, 997, ['mode' => $mode, 'after' => $after] + ($exc ? ['exc' => $exc] : []));
        $out = jf_out();
        try {
            Runner::process($in, $out, plan(['maxRecordBytes' => 64]));
            $class = 'none';
            $same = '-';
        } catch (Throwable $e) {
            $class = get_class($e);
            $same = $exc ? var_export($e === $exc && $e->getPrevious() === null, true) : '-';
        }
        $bytes = jf_out_bytes($out);
        echo str_pad("$label, failing $when", 60), $class, ' same=', $same, ' ', judge($bytes, $ids),
            ' non-empty: ', var_export($bytes !== '', true), "\n";
        fclose($in);
        fclose($out);
    }
}

echo "-- a truncated last record at EOF\n";
foreach (['mid-string' => '{"id":777777,"s":"ab', 'after a value' => '{"id":777777,"s":"ab"', 'after a comma' => '{"id":777777,"s":"ab",', 'only a brace' => '{'] as $label => $tail) {
    $data = rec(1) . "\n" . rec(2) . "\n" . rec(3) . "\n" . $tail;
    [$class, $e, $out] = go($data);
    echo str_pad($label, 50), $class, ' ', jf_ctx(msg($e)), ' ', judge($out, [1, 2, 3]), ' ', str_contains($out, '777777') ? 'failing record written' : 'ok', "\n";
}

echo "-- a failing run after a successful one on the same output keeps the earlier output\n";
$out = jf_out();
$in = jf_mem(rec(1) . "\n" . rec(2) . "\n" . rec(3) . "\n");
$s = Runner::process($in, $out, plan());
$firstBytes = $s->bytesWritten;
fclose($in);
$in = jf_mem(rec(11) . "\n" . rec(12) . "\n" . '{"id":777777,"s":3,"g":1}' . "\n");
try {
    Runner::process($in, $out, plan());
    echo "no exception\n";
} catch (RecordException $e) {
    echo 'RecordException ', jf_ctx($e->getMessage()), "\n";
}
$all = jf_out_bytes($out);
fclose($in);
fclose($out);
$head = substr($all, 0, $firstBytes);
$rest = substr($all, $firstBytes);
echo 'first run intact: ', judge($head, [1, 2, 3]) === 'ok' && substr_count($head, "\n") === 3 ? 'ok' : 'FAIL', "\n";
$lines = $rest === '' ? [] : explode("\n", substr($rest, 0, -1));
$okRest = ($rest === '' || substr($rest, -1) === "\n") && count($lines) <= 2;
foreach ($lines as $k => $l) {
    $d = json_decode($l, true);
    $okRest = $okRest && is_array($d) && $d['id'] === 11 + $k;
}
echo 'second run adds a line-complete prefix of its records: ', var_export($okRest, true), "\n";

echo "-- every emitted line decodes, in schema order, with the exact values\n";
$types = ['fields' => ['i' => 'int64', 'f' => 'float64?', 'b' => 'bool', 's' => 'string?'], 'where' => ['w' => true]];
$rng = new JfRng(80080);
$data = '';
$expect = [];
for ($k = 0; $k < 600; $k++) {
    $i = $rng->int(2000000) - 1000000;
    $f = $rng->chance(20) ? null : ($rng->int(100000) / 8);
    $b = $rng->chance(50);
    $s = $rng->chance(20) ? null : 'str' . $rng->int(1000) . ($rng->chance(30) ? "\u{e9}\"\\/\n" : '');
    $match = $rng->chance(60);
    $data .= '{"w":' . ($match ? 'true' : 'false') . ',"s":' . ($s === null ? 'null' : json_encode($s)) . ',"b":' . ($b ? 'true' : 'false')
        . ',"f":' . ($f === null ? 'null' : sprintf('%.3f', $f)) . ',"i":' . $i . "}\n";
    if ($match) {
        $expect[] = ['i' => $i, 'f' => $f === null ? null : (float)sprintf('%.3f', $f), 'b' => $b, 's' => $s];
    }
}
$data .= '{"w":true,"s":"x","b":"true","f":1,"i":777777}' . "\n";
[$class, $e, $out] = go($data, Plan::compile($types + ['maxRecordBytes' => 128]));
$lines = $out === '' ? [] : explode("\n", substr($out, 0, -1));
$mism = 0;
foreach ($lines as $k => $l) {
    $d = json_decode($l, true);
    $x = $expect[$k] ?? null;
    if ($x === null || !is_array($d) || array_keys($d) !== ['i', 'f', 'b', 's'] || $d['i'] !== $x['i'] || $d['b'] !== $x['b'] || $d['s'] !== $x['s']
        || ($x['f'] === null ? $d['f'] !== null : (!is_float($d['f']) || pack('E', $d['f']) !== pack('E', $x['f'])))) {
        $mism++;
    }
}
echo $class, ' ', ($out === '' || substr($out, -1) === "\n") ? 'line-complete' : 'PARTIAL', ' lines not matching the reference: ', $mism, ' more lines than expected: ', var_export(count($lines) > count($expect), true), "\n";
?>
--EXPECT--
-- a record error after N good records, default size cap
type error in id                                  ok (12 runs)
missing field s                                   ok (12 runs)
null in a non-nullable field                      ok (12 runs)
duplicate referenced key id                       ok (12 runs)
duplicate where key g                             ok (12 runs)
where key is an array, every field valid          ok (12 runs)
where key is an object, every field valid         ok (12 runs)
trailing comma                                    ok (12 runs)
truncated object                                  ok (12 runs)
root is an array                                  ok (12 runs)
root is a number                                  ok (12 runs)
lone surrogate escape in an unreferenced key      ok (12 runs)
invalid UTF-8 in the value                        ok (12 runs)
UTF-8 BOM before the record                       ok (12 runs)
NUL byte outside a string                         ok (12 runs)
blank line (skipEmptyLines false)                 ok (12 runs)
whitespace-only line                              ok (12 runs)
512 levels of nesting                             ok (12 runs)
-- the same with maxRecordBytes 64, which keeps the writer small
type error in id                                  ok (12 runs)
missing field s                                   ok (12 runs)
null in a non-nullable field                      ok (12 runs)
duplicate referenced key id                       ok (12 runs)
duplicate where key g                             ok (12 runs)
where key is an array, every field valid          ok (12 runs)
where key is an object, every field valid         ok (12 runs)
trailing comma                                    ok (12 runs)
truncated object                                  ok (12 runs)
root is an array                                  ok (12 runs)
root is a number                                  ok (12 runs)
lone surrogate escape in an unreferenced key      ok (12 runs)
invalid UTF-8 in the value                        ok (12 runs)
UTF-8 BOM before the record                       ok (12 runs)
NUL byte outside a string                         ok (12 runs)
blank line (skipEmptyLines false)                 ok (12 runs)
whitespace-only line                              ok (12 runs)
512 levels of nesting                             ok (12 runs)
record over the size cap                          ok (12 runs)
-- CRLF input and skipped blank lines before the failure
CRLF, 0 good records                              JsonFuse\RecordException ok no CR
CRLF, 3 good records                              JsonFuse\RecordException ok no CR
CRLF, 200 good records                            JsonFuse\RecordException ok no CR
blank lines skipped, then a type error            JsonFuse\RecordException line=8 record=4 offset=133 ok
-- bounded buffering: with a 64 byte cap the writer holds back less than a handful of records
JsonFuse\RecordException ok withheld at most 256 bytes: true (of more than 20000 bytes)
-- a record error with a sink that takes one byte per write
0 good records                                    JsonFuse\RecordException ok
1 good records                                    JsonFuse\RecordException ok
40 good records                                   JsonFuse\RecordException ok
-- input failures in the middle of a run
stream_read throws, failing late                            JfBoom same=true ok non-empty: true
stream_read throws, failing half                            JfBoom same=true ok non-empty: true
stream_read throws, failing early                           JfBoom same=true ok non-empty: true
stream_eof throws, failing late                             JfBoom same=true ok non-empty: true
stream_eof throws, failing half                             JfBoom same=true ok non-empty: true
stream_eof throws, failing early                            JfBoom same=true ok non-empty: true
stream_read returns false, failing late                     JsonFuse\StreamException same=- ok non-empty: true
stream_read returns false, failing half                     JsonFuse\StreamException same=- ok non-empty: true
stream_read returns false, failing early                    JsonFuse\StreamException same=- ok non-empty: true
stream_read returns empty, eof false, failing late          JsonFuse\StreamException same=- ok non-empty: true
stream_read returns empty, eof false, failing half          JsonFuse\StreamException same=- ok non-empty: true
stream_read returns empty, eof false, failing early         JsonFuse\StreamException same=- ok non-empty: true
-- a truncated last record at EOF
mid-string                                        JsonFuse\RecordException line=4 record=4 offset=129 ok ok
after a value                                     JsonFuse\RecordException line=4 record=4 offset=129 ok ok
after a comma                                     JsonFuse\RecordException line=4 record=4 offset=129 ok ok
only a brace                                      JsonFuse\RecordException line=4 record=4 offset=129 ok ok
-- a failing run after a successful one on the same output keeps the earlier output
RecordException line=3 record=3 offset=90
first run intact: ok
second run adds a line-complete prefix of its records: true
-- every emitted line decodes, in schema order, with the exact values
JsonFuse\RecordException line-complete lines not matching the reference: 0 more lines than expected: false
