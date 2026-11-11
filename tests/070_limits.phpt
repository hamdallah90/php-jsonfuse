--TEST--
jsonfuse 1.0: maxRecordBytes boundaries (LF, CRLF, EOF), default 1 MiB cap, lazy overlong line, 8 MiB record under an explicit cap
--EXTENSIONS--
jsonfuse
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;
use JsonFuse\RecordException;

const M = 'SECRETMARK';
const NOCTX = 'line=1 record=1 offset=0';

// Records must carry the plan's field; jf_rec() records hold a string `k`, so the plan declares it.
const KPLAN = ['fields' => ['k' => 'string']];
const SM = '{"k":""}';

function ok(string $label, string $data, int $cap): void
{
    echo str_pad($label, 40), jf_run_str($data, ['maxRecordBytes' => $cap] + KPLAN), "\n";
}

function over(string $label, string $data, int $cap, string $ctx = NOCTX): void
{
    echo str_pad($label, 40),
        jf_expect_error($data, RecordException::class, $ctx, [M, 'SECRET', 'aaaaaaaa'], ['maxRecordBytes' => $cap] + KPLAN), "\n";
}

function stream_run($in, array $over = []): string
{
    // The writer writes the record; a spilling sink keeps the output out of memory_limit's accounting.
    $out = fopen('php://temp/maxmemory:65536', 'w+b');
    try {
        return jf_stats(Runner::process($in, $out, jf_plan($over + KPLAN)));
    } catch (Throwable $e) {
        return get_class($e) . ' ' . jf_ctx($e->getMessage());
    } finally {
        fclose($in);
        fclose($out);
    }
}

foreach ([64, 8192, 65536] as $cap) {
    echo "-- cap $cap\n";
    $exact = jf_rec($cap);
    $plus1 = jf_rec($cap + 1);
    ok('exact + LF', $exact . "\n", $cap);
    ok('exact + CRLF', $exact . "\r\n", $cap);
    ok('exact, no terminator', $exact, $cap);
    ok('exact + CR at EOF', $exact . "\r", $cap);
    ok('exact + LF, then {}', $exact . "\n" . SM . "\n", $cap);
    ok('exact + CRLF, then {}', $exact . "\r\n" . SM, $cap);
    ok('{} then exact + CRLF', SM . "\n" . $exact . "\r\n", $cap);
    ok('two exact records', $exact . "\n" . $exact . "\n", $cap);
    over('cap+1 + LF', $plus1 . "\n", $cap);
    over('cap+1 + CRLF', $plus1 . "\r\n", $cap);
    over('cap+1, no terminator', $plus1, $cap);
    over('cap+1 + CR at EOF', $plus1 . "\r", $cap);
    over('exact + CR + CRLF (extra CR is text)', $exact . "\r\r\n", $cap);
    over('cap+2 + LF', jf_rec($cap + 2) . "\n", $cap);
    over('cap+100 + LF', jf_rec($cap + 100) . "\n", $cap);
    over('cap+1 on line 2', SM . "\n" . $plus1 . "\n", $cap, 'line=2 record=2 offset=9');
    over('cap+1 on line 3, CRLF before', SM . "\r\n" . SM . "\r\n" . $plus1 . "\r\n", $cap, 'line=3 record=3 offset=20');
    over('cap+1 after exact record', $exact . "\n" . $plus1 . "\n", $cap, 'line=2 record=2 offset=' . ($cap + 1));
    over('cap+1 with trailing record', $plus1 . "\n{}\n", $cap);
}

echo "-- maxRecordBytes=1\n";
ok('empty input', '', 1);
echo str_pad('skip blank line', 40), jf_run_str("\n\r\n", ['maxRecordBytes' => 1, 'skipEmptyLines' => true]), "\n";
over('{} is 2 bytes', "{}\n", 1);
over('{} no terminator', '{}', 1);
over('{} CRLF', "{}\r\n", 1);
over('blank line 1 fails before {} on line 2', "\n{}\n", 1, NOCTX);
echo str_pad('{} on line 2, blank skipped', 40),
    jf_expect_error("\n{}\n", RecordException::class, 'line=2 record=1 offset=1', [], ['maxRecordBytes' => 1, 'skipEmptyLines' => true]), "\n";
over('one byte x is not an object', "x\n", 1);
over('one byte 5 is not an object', '5', 1);

echo "-- default cap is 1 MiB\n";
$cap = 1024 * 1024;
$cap8 = 8 * 1024 * 1024;
$gen = fn(int $n, string $term) => jf_gen([['{"k":"', 1], ['a', $n - 8], ['"}', 1], [$term, 1]]);
echo str_pad('default: exact + LF', 40), stream_run($gen($cap, "\n")), "\n";
echo str_pad('default: exact + CRLF', 40), stream_run($gen($cap, "\r\n")), "\n";
echo str_pad('default: exact, no terminator', 40), stream_run($gen($cap, '')), "\n";
echo str_pad('default: cap+1 + LF', 40), stream_run($gen($cap + 1, "\n")), "\n";
echo str_pad('default: cap+1 + CRLF', 40), stream_run($gen($cap + 1, "\r\n")), "\n";
echo str_pad('default: cap+1, no terminator', 40), stream_run($gen($cap + 1, '')), "\n";
echo str_pad('explicit 8388608: exact + LF', 40), stream_run($gen($cap8, "\n"), ['maxRecordBytes' => $cap8]), "\n";
echo str_pad('explicit 8388608: cap+1 + LF', 40), stream_run($gen($cap8 + 1, "\n"), ['maxRecordBytes' => $cap8]), "\n";
echo str_pad('smaller cap 4194304: 4 MiB + 1', 40), stream_run($gen(4194305, "\n"), ['maxRecordBytes' => 4194304]), "\n";
echo str_pad('smaller cap 4194304: 4 MiB', 40), stream_run($gen(4194304, "\n"), ['maxRecordBytes' => 4194304]), "\n";

echo "-- an 8 MiB record of 1,1,1,... succeeds under memory_limit=128M\n";
var_dump(ini_get('memory_limit'));
// {"k":"","a":[ + "1,"*k + 1 + ] + 2 spaces + }  == 18 + 2k bytes
$k = intdiv($cap8 - 18, 2);
$arr = [['{"k":"","a":[', 1], ['1,', $k], ['1', 1], [']', 1], [' ', 2], ['}', 1]];
echo str_pad('8 MiB array record + LF', 40), stream_run(jf_gen(array_merge($arr, [["\n", 1]])), ['maxRecordBytes' => $cap8]), "\n";
echo str_pad('8 MiB array record, no LF', 40), stream_run(jf_gen($arr), ['maxRecordBytes' => $cap8]), "\n";
echo str_pad('then a small run still works', 40), jf_run_str("{\"id\":1}\n"), "\n";

echo "-- a lazy 100x-cap line without LF is rejected early\n";
$cap = 1048576;
$in = jf_gen([['{"a":"' . M, 1], ['x', 100 * $cap]]);
$out = jf_out();
try {
    Runner::process($in, $out, jf_plan(['maxRecordBytes' => $cap]));
    echo "no exception\n";
} catch (Throwable $e) {
    echo get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
    echo 'message leaks marker: ', var_export(str_contains($e->getMessage(), M) || str_contains($e->getMessage(), 'xxxxxxxx'), true), "\n";
}
$w = JfWrap::$last;
// the documented reader bound is cap + 2 + 64 KiB (+ padding); allow generous slack for stream-layer buffers.
$limit = $cap + 2 + 65536 + 262144;
echo 'bytes pulled from the stream: ', $w->served > $cap ? 'more than cap' : 'less than cap', "\n";
echo 'bytes pulled within bound: ', var_export($w->served <= $limit, true), "\n";
echo 'input not fully consumed: ', var_export($w->served < 100 * $cap, true), "\n";
fclose($in);
fclose($out);

$in = jf_gen([["{\"id\":1}\n", 1], ['{"a":"', 1], ['y', 100 * $cap]]);
$out = jf_out();
try {
    Runner::process($in, $out, jf_plan(['maxRecordBytes' => $cap]));
    echo "no exception\n";
} catch (Throwable $e) {
    echo 'on line 2: ', get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
}
echo 'bytes pulled within bound: ', var_export(JfWrap::$last->served <= $limit + 9, true), "\n";
fclose($in);
fclose($out);

// the same line, but with an LF far beyond the cap
$in = jf_gen([['{"a":"', 1], ['z', $cap * 3], ["\"}\n", 1]]);
echo str_pad('long line with far LF', 40), stream_run($in, ['maxRecordBytes' => $cap]), "\n";
?>
--EXPECT--
-- cap 64
exact + LF                              read=1 matched=1 emitted=1 bytesRead=65 bytesWritten=65
exact + CRLF                            read=1 matched=1 emitted=1 bytesRead=66 bytesWritten=65
exact, no terminator                    read=1 matched=1 emitted=1 bytesRead=64 bytesWritten=65
exact + CR at EOF                       read=1 matched=1 emitted=1 bytesRead=65 bytesWritten=65
exact + LF, then {}                     read=2 matched=2 emitted=2 bytesRead=74 bytesWritten=74
exact + CRLF, then {}                   read=2 matched=2 emitted=2 bytesRead=74 bytesWritten=74
{} then exact + CRLF                    read=2 matched=2 emitted=2 bytesRead=75 bytesWritten=74
two exact records                       read=2 matched=2 emitted=2 bytesRead=130 bytesWritten=130
cap+1 + LF                              ok
cap+1 + CRLF                            ok
cap+1, no terminator                    ok
cap+1 + CR at EOF                       ok
exact + CR + CRLF (extra CR is text)    ok
cap+2 + LF                              ok
cap+100 + LF                            ok
cap+1 on line 2                         ok
cap+1 on line 3, CRLF before            ok
cap+1 after exact record                ok
cap+1 with trailing record              ok
-- cap 8192
exact + LF                              read=1 matched=1 emitted=1 bytesRead=8193 bytesWritten=8193
exact + CRLF                            read=1 matched=1 emitted=1 bytesRead=8194 bytesWritten=8193
exact, no terminator                    read=1 matched=1 emitted=1 bytesRead=8192 bytesWritten=8193
exact + CR at EOF                       read=1 matched=1 emitted=1 bytesRead=8193 bytesWritten=8193
exact + LF, then {}                     read=2 matched=2 emitted=2 bytesRead=8202 bytesWritten=8202
exact + CRLF, then {}                   read=2 matched=2 emitted=2 bytesRead=8202 bytesWritten=8202
{} then exact + CRLF                    read=2 matched=2 emitted=2 bytesRead=8203 bytesWritten=8202
two exact records                       read=2 matched=2 emitted=2 bytesRead=16386 bytesWritten=16386
cap+1 + LF                              ok
cap+1 + CRLF                            ok
cap+1, no terminator                    ok
cap+1 + CR at EOF                       ok
exact + CR + CRLF (extra CR is text)    ok
cap+2 + LF                              ok
cap+100 + LF                            ok
cap+1 on line 2                         ok
cap+1 on line 3, CRLF before            ok
cap+1 after exact record                ok
cap+1 with trailing record              ok
-- cap 65536
exact + LF                              read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=65537
exact + CRLF                            read=1 matched=1 emitted=1 bytesRead=65538 bytesWritten=65537
exact, no terminator                    read=1 matched=1 emitted=1 bytesRead=65536 bytesWritten=65537
exact + CR at EOF                       read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=65537
exact + LF, then {}                     read=2 matched=2 emitted=2 bytesRead=65546 bytesWritten=65546
exact + CRLF, then {}                   read=2 matched=2 emitted=2 bytesRead=65546 bytesWritten=65546
{} then exact + CRLF                    read=2 matched=2 emitted=2 bytesRead=65547 bytesWritten=65546
two exact records                       read=2 matched=2 emitted=2 bytesRead=131074 bytesWritten=131074
cap+1 + LF                              ok
cap+1 + CRLF                            ok
cap+1, no terminator                    ok
cap+1 + CR at EOF                       ok
exact + CR + CRLF (extra CR is text)    ok
cap+2 + LF                              ok
cap+100 + LF                            ok
cap+1 on line 2                         ok
cap+1 on line 3, CRLF before            ok
cap+1 after exact record                ok
cap+1 with trailing record              ok
-- maxRecordBytes=1
empty input                             read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0
skip blank line                         read=0 matched=0 emitted=0 bytesRead=3 bytesWritten=0
{} is 2 bytes                           ok
{} no terminator                        ok
{} CRLF                                 ok
blank line 1 fails before {} on line 2  ok
{} on line 2, blank skipped             ok
one byte x is not an object             ok
one byte 5 is not an object             ok
-- default cap is 1 MiB
default: exact + LF                     read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577
default: exact + CRLF                   read=1 matched=1 emitted=1 bytesRead=1048578 bytesWritten=1048577
default: exact, no terminator           read=1 matched=1 emitted=1 bytesRead=1048576 bytesWritten=1048577
default: cap+1 + LF                     JsonFuse\RecordException line=1 record=1 offset=0
default: cap+1 + CRLF                   JsonFuse\RecordException line=1 record=1 offset=0
default: cap+1, no terminator           JsonFuse\RecordException line=1 record=1 offset=0
explicit 8388608: exact + LF            read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609
explicit 8388608: cap+1 + LF            JsonFuse\RecordException line=1 record=1 offset=0
smaller cap 4194304: 4 MiB + 1          JsonFuse\RecordException line=1 record=1 offset=0
smaller cap 4194304: 4 MiB              read=1 matched=1 emitted=1 bytesRead=4194305 bytesWritten=4194305
-- an 8 MiB record of 1,1,1,... succeeds under memory_limit=128M
string(4) "128M"
8 MiB array record + LF                 read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=9
8 MiB array record, no LF               read=1 matched=1 emitted=1 bytesRead=8388608 bytesWritten=9
then a small run still works            read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
-- a lazy 100x-cap line without LF is rejected early
JsonFuse\RecordException line=1 record=1 offset=0
message leaks marker: false
bytes pulled from the stream: more than cap
bytes pulled within bound: true
input not fully consumed: true
on line 2: JsonFuse\RecordException line=2 record=2 offset=9
bytes pulled within bound: true
long line with far LF                   JsonFuse\RecordException line=1 record=1 offset=0
