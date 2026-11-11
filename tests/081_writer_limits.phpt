--TEST--
jsonfuse 1.0: records at an explicit 8 MiB cap are written intact, including worst-case escape runs, widest numbers and widest field names, at the default memory_limit
--EXTENSIONS--
jsonfuse
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * Large outputs are never held in PHP: the sink keeps a running sha256 and counters only, and the
 * input is generated lazily. The expected digest is built from the same pieces, independently.
 */

/** Expected sha256 of the concatenation of [string, repeat] pieces. */
function digest(array $pieces): string
{
    $h = hash_init('sha256');
    foreach ($pieces as [$s, $n]) {
        if (strlen($s) * $n <= 65536) {
            hash_update($h, str_repeat($s, $n));
            continue;
        }
        if (strlen($s) > 65536) {
            for ($i = 0; $i < $n; $i++) {
                hash_update($h, $s);
            }
            continue;
        }
        $block = str_repeat($s, intdiv(65536, strlen($s)));
        $per = intdiv(65536, strlen($s));
        for ($left = $n; $left > 0; $left -= $per) {
            hash_update($h, $left >= $per ? $block : str_repeat($s, $left));
        }
    }
    return hash_final($h);
}

/** Run lazy input pieces through $plan into a hashing sink; returns [class|none, stats, sha256, accepted bytes]. */
function run(array $inPieces, Plan $plan): array
{
    $in = jf_gen($inPieces);
    $out = jf_sink(['keep' => false]);
    try {
        $r = ['none', Runner::process($in, $out, $plan)];
    } catch (Throwable $e) {
        $r = [get_class($e), $e];
    }
    $k = JfSink::$last;
    $r[] = $k->sha256();
    $r[] = $k->accepted;
    fclose($in);
    fclose($out);
    return $r;
}

function verdict(string $label, array $r, array $outPieces, int $emitted = 1): void
{
    [$class, $s, $sha, $accepted] = $r;
    $want = digest($outPieces);
    $len = 0;
    foreach ($outPieces as [$p, $n]) {
        $len += strlen($p) * $n;
    }
    echo str_pad($label, 58), $class, ' ';
    if ($class !== 'none') {
        echo jf_ctx($s->getMessage()), "\n";
        return;
    }
    echo jf_stats($s), ' digest ', $sha === $want ? 'ok' : 'FAIL', ' length ', $accepted === $len && $s->bytesWritten === $len ? 'ok' : "FAIL $accepted/{$s->bytesWritten}/$len", "\n";
}

$splan = fn(int $cap) => Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => $cap]);

echo "-- a record of exactly the cap is written intact, whatever terminates it\n";
foreach ([64, 4096, 65536, 1048576] as $cap) {
    foreach (['LF' => "\n", 'CRLF' => "\r\n", 'none' => ''] as $name => $term) {
        $r = run([['{"s":"', 1], ['a', $cap - 8], ["\"}", 1], [$term, 1]], $splan($cap));
        verdict("cap $cap, $name", $r, [['{"s":"', 1], ['a', $cap - 8], ["\"}\n", 1]]);
    }
}
$r = run([['{"s":"', 1], ['a', 8 * 1048576 - 8], ["\"}\n", 1]], Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => 8388608]));
verdict('default cap 8 MiB, LF', $r, [['{"s":"', 1], ['a', 8 * 1048576 - 8], ["\"}\n", 1]]);
$r = run([['{"s":"', 1], ['a', 8 * 1048576 - 8], ["\"}", 1]], Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => 8388608]));
verdict('default cap 8 MiB, no terminator', $r, [['{"s":"', 1], ['a', 8 * 1048576 - 8], ["\"}\n", 1]]);

echo "-- strings of escapes that fill the cap: input spelling to output spelling\n";
// [label, input unit, output unit]
$units = [
    ['\u0001 (6 bytes, kept)', '\u0001', '\u0001'],
    ['\u001f (6 bytes, kept)', '\u001f', '\u001f'],
    ['\u0000 (6 bytes, kept)', '\u0000', '\u0000'],
    ['\n (2 bytes, kept)', '\n', '\n'],
    ['\" (2 bytes, kept)', '\"', '\"'],
    ['\\\\ (2 bytes, kept)', '\\\\', '\\\\'],
    ['mixed controls and quotes (kept)', '\u0000\b\f\n\r\t\u001f\"\\\\\u0001', '\u0000\b\f\n\r\t\u001f\"\\\\\u0001'],
    ['\u000a becomes \n', '\u000a', '\n'],
    ['\u0022 becomes \"', '\u0022', '\"'],
    ['\u005c becomes two backslashes', '\u005c', '\\\\'],
    ['\u002f becomes /', '\u002f', '/'],
    ['\/ becomes /', '\/', '/'],
    ['\u00e9 becomes raw e-acute', '\u00e9', "\u{e9}"],
    ['\u20ac becomes raw euro sign', '\u20ac', "\u{20AC}"],
    ['\ud83d\ude00 becomes raw emoji', '\ud83d\ude00', "\u{1F600}"],
    ['\u2028 becomes raw U+2028', '\u2028', "\u{2028}"],
    ['\u007f becomes raw DEL', '\u007f', "\x7f"],
    ['\u0008\u000c becomes \b\f', '\u0008\u000c', '\b\f'],
    ['raw e-acute (kept)', "\u{e9}", "\u{e9}"],
    ['raw emoji (kept)', "\u{1F600}", "\u{1F600}"],
];
foreach ([4096, 1048576, 8388608] as $cap) {
    foreach ($units as [$label, $in, $out]) {
        $n = intdiv($cap - 8, strlen($in));
        $r = $cap - 8 - $n * strlen($in);
        $plan = $cap === 8388608 ? Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => 8388608]) : $splan($cap);
        $res = run([['{"s":"', 1], ['a', $r], [$in, $n], ["\"}\n", 1]], $plan);
        verdict("cap $cap: $label", $res, [['{"s":"', 1], ['a', $r], [$out, $n], ["\"}\n", 1]]);
    }
}

echo "-- widest numbers: 128 float64 fields, the longest outputs from the shortest inputs, in a record of exactly the cap\n";
$names = [];
$fields = [];
for ($i = 0; $i < 128; $i++) {
    $names[] = sprintf('f%03d', $i);
    $fields[sprintf('f%03d', $i)] = 'float64';
}
foreach ([['1e20 (4 bytes in, 23 out)', '1e20', '100000000000000000000.0'], ['-1.7976931348623157e308', '-1.7976931348623157e308', '-1.7976931348623157e308'],
          ['5 (1 byte in, 3 out)', '5', '5.0'], ['-5e-324', '-5e-324', '-5e-324'], ['1e21', '1e21', '1e21'], ['0.1', '0.1', '0.1']] as [$label, $lit, $emit]) {
    foreach ([65536, 8388608] as $cap) {
        $members = [];
        $outs = [];
        foreach ($names as $nm) {
            $members[] = '"' . $nm . '":' . $lit;
            $outs[] = '"' . $nm . '":' . $emit;
        }
        $head = '{' . implode(',', $members) . ',"pad":"';
        $pad = $cap - strlen($head) - 2;
        $plan = Plan::compile(['fields' => $fields, 'maxRecordBytes' => $cap]);
        $res = run([[$head, 1], ['p', $pad], ["\"}\n", 1]], $plan);
        verdict("$label, cap $cap", $res, [['{' . implode(',', $outs) . "}\n", 1]]);
    }
}

echo "-- 128 int64 fields with 20-digit values\n";
$members = [];
$outs = [];
foreach ($names as $i => $nm) {
    $v = $i % 2 ? '-9223372036854775808' : '9223372036854775807';
    $members[] = '"' . $nm . '":' . $v;
    $outs[] = '"' . $nm . '":' . $v;
}
$intFields = array_fill_keys($names, 'int64');
$res = run([['{' . implode(',', $members) . "}\n", 1]], Plan::compile(['fields' => $intFields, 'maxRecordBytes' => 4096]));
verdict('cap 4096', $res, [['{' . implode(',', $outs) . "}\n", 1]]);

echo "-- widest names: 128 fields with 1024-byte names of control bytes, 20 records\n";
$lnames = [];
for ($i = 0; $i < 128; $i++) {
    $lnames[] = sprintf('%03d', $i) . str_repeat(chr(1 + $i % 31), 1021);
}
$lfields = array_fill_keys($lnames, 'int64');
$inMembers = [];
$outMembers = [];
foreach ($lnames as $i => $nm) {
    $inMembers[] = json_encode($nm) . ':' . $i;
    $outMembers[] = json_encode($nm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS) . ':' . $i;
}
$rec = '{' . implode(',', array_reverse($inMembers)) . "}\n";
$outRec = '{' . implode(',', $outMembers) . "}\n";
echo 'input record ', strlen($rec), ' bytes, output record ', strlen($outRec), " bytes\n";
// the input in small pieces, so that generating it stays cheap
$pieces = [];
for ($r = 0; $r < 20; $r++) {
    $pieces[] = ['{', 1];
    foreach (array_reverse($inMembers) as $k => $m) {
        $pieces[] = [($k ? ',' : '') . $m, 1];
    }
    $pieces[] = ["}\n", 1];
}
$res = run($pieces, Plan::compile(['fields' => $lfields, 'maxRecordBytes' => 8388608]));
verdict('20 records, 8 MiB cap', $res, [[$outRec, 20]], 20);
$res = run(array_slice($pieces, 0, 3 * 130), Plan::compile(['fields' => $lfields, 'maxRecordBytes' => strlen($rec) - 1]));
verdict('3 records, cap exactly the record', $res, [[$outRec, 3]], 3);

echo "-- many small records through a tiny writer\n";
$res = run([['{"s":"x"}' . "\n", 20000]], $splan(64));
verdict('20000 records, cap 64', $res, [['{"s":"x"}' . "\n", 20000]], 20000);
$res = run([['{"s":"x"}' . "\r\n", 20000]], $splan(9));
verdict('20000 records, cap 9 (record is 9 bytes)', $res, [['{"s":"x"}' . "\n", 20000]], 20000);

echo "-- a record at the cap among small ones\n";
$cap = 65536;
$big = '{"s":"' . str_repeat('\u0001', intdiv($cap - 8, 6)) . str_repeat('b', ($cap - 8) % 6) . '"}';
$res = run([['{"s":"a"}' . "\n", 100], [$big . "\n", 1], ['{"s":"c"}' . "\n", 100], [$big . "\n", 2]], $splan($cap));
verdict('100 small, big, 100 small, 2 big', $res, [['{"s":"a"}' . "\n", 100], [$big . "\n", 1], ['{"s":"c"}' . "\n", 100], [$big . "\n", 2]], 203);
?>
--EXPECT--
-- a record of exactly the cap is written intact, whatever terminates it
cap 64, LF                                                none read=1 matched=1 emitted=1 bytesRead=65 bytesWritten=65 digest ok length ok
cap 64, CRLF                                              none read=1 matched=1 emitted=1 bytesRead=66 bytesWritten=65 digest ok length ok
cap 64, none                                              none read=1 matched=1 emitted=1 bytesRead=64 bytesWritten=65 digest ok length ok
cap 4096, LF                                              none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096, CRLF                                            none read=1 matched=1 emitted=1 bytesRead=4098 bytesWritten=4097 digest ok length ok
cap 4096, none                                            none read=1 matched=1 emitted=1 bytesRead=4096 bytesWritten=4097 digest ok length ok
cap 65536, LF                                             none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=65537 digest ok length ok
cap 65536, CRLF                                           none read=1 matched=1 emitted=1 bytesRead=65538 bytesWritten=65537 digest ok length ok
cap 65536, none                                           none read=1 matched=1 emitted=1 bytesRead=65536 bytesWritten=65537 digest ok length ok
cap 1048576, LF                                           none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576, CRLF                                         none read=1 matched=1 emitted=1 bytesRead=1048578 bytesWritten=1048577 digest ok length ok
cap 1048576, none                                         none read=1 matched=1 emitted=1 bytesRead=1048576 bytesWritten=1048577 digest ok length ok
default cap 8 MiB, LF                                     none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
default cap 8 MiB, no terminator                          none read=1 matched=1 emitted=1 bytesRead=8388608 bytesWritten=8388609 digest ok length ok
-- strings of escapes that fill the cap: input spelling to output spelling
cap 4096: \u0001 (6 bytes, kept)                          none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \u001f (6 bytes, kept)                          none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \u0000 (6 bytes, kept)                          none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \n (2 bytes, kept)                              none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \" (2 bytes, kept)                              none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \\ (2 bytes, kept)                              none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: mixed controls and quotes (kept)                none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: \u000a becomes \n                               none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1373 digest ok length ok
cap 4096: \u0022 becomes \"                               none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1373 digest ok length ok
cap 4096: \u005c becomes two backslashes                  none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1373 digest ok length ok
cap 4096: \u002f becomes /                                none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=692 digest ok length ok
cap 4096: \/ becomes /                                    none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=2053 digest ok length ok
cap 4096: \u00e9 becomes raw e-acute                      none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1373 digest ok length ok
cap 4096: \u20ac becomes raw euro sign                    none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=2054 digest ok length ok
cap 4096: \ud83d\ude00 becomes raw emoji                  none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1377 digest ok length ok
cap 4096: \u2028 becomes raw U+2028                       none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=2054 digest ok length ok
cap 4096: \u007f becomes raw DEL                          none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=692 digest ok length ok
cap 4096: \u0008\u000c becomes \b\f                       none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=1377 digest ok length ok
cap 4096: raw e-acute (kept)                              none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 4096: raw emoji (kept)                                none read=1 matched=1 emitted=1 bytesRead=4097 bytesWritten=4097 digest ok length ok
cap 1048576: \u0001 (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \u001f (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \u0000 (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \n (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \" (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \\ (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: mixed controls and quotes (kept)             none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: \u000a becomes \n                            none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349533 digest ok length ok
cap 1048576: \u0022 becomes \"                            none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349533 digest ok length ok
cap 1048576: \u005c becomes two backslashes               none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349533 digest ok length ok
cap 1048576: \u002f becomes /                             none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=174772 digest ok length ok
cap 1048576: \/ becomes /                                 none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=524293 digest ok length ok
cap 1048576: \u00e9 becomes raw e-acute                   none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349533 digest ok length ok
cap 1048576: \u20ac becomes raw euro sign                 none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=524294 digest ok length ok
cap 1048576: \ud83d\ude00 becomes raw emoji               none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349537 digest ok length ok
cap 1048576: \u2028 becomes raw U+2028                    none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=524294 digest ok length ok
cap 1048576: \u007f becomes raw DEL                       none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=174772 digest ok length ok
cap 1048576: \u0008\u000c becomes \b\f                    none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=349537 digest ok length ok
cap 1048576: raw e-acute (kept)                           none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 1048576: raw emoji (kept)                             none read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok length ok
cap 8388608: \u0001 (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \u001f (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \u0000 (6 bytes, kept)                       none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \n (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \" (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \\ (2 bytes, kept)                           none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: mixed controls and quotes (kept)             none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: \u000a becomes \n                            none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: \u0022 becomes \"                            none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: \u005c becomes two backslashes               none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: \u002f becomes /                             none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1398109 digest ok length ok
cap 8388608: \/ becomes /                                 none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=4194309 digest ok length ok
cap 8388608: \u00e9 becomes raw e-acute                   none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: \u20ac becomes raw euro sign                 none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=4194309 digest ok length ok
cap 8388608: \ud83d\ude00 becomes raw emoji               none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: \u2028 becomes raw U+2028                    none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=4194309 digest ok length ok
cap 8388608: \u007f becomes raw DEL                       none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1398109 digest ok length ok
cap 8388608: \u0008\u000c becomes \b\f                    none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=2796209 digest ok length ok
cap 8388608: raw e-acute (kept)                           none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
cap 8388608: raw emoji (kept)                             none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok length ok
-- widest numbers: 128 float64 fields, the longest outputs from the shortest inputs, in a record of exactly the cap
1e20 (4 bytes in, 23 out), cap 65536                      none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=3970 digest ok length ok
1e20 (4 bytes in, 23 out), cap 8388608                    none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=3970 digest ok length ok
-1.7976931348623157e308, cap 65536                        none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=3970 digest ok length ok
-1.7976931348623157e308, cap 8388608                      none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=3970 digest ok length ok
5 (1 byte in, 3 out), cap 65536                           none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=1410 digest ok length ok
5 (1 byte in, 3 out), cap 8388608                         none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1410 digest ok length ok
-5e-324, cap 65536                                        none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=1922 digest ok length ok
-5e-324, cap 8388608                                      none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1922 digest ok length ok
1e21, cap 65536                                           none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=1538 digest ok length ok
1e21, cap 8388608                                         none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1538 digest ok length ok
0.1, cap 65536                                            none read=1 matched=1 emitted=1 bytesRead=65537 bytesWritten=1410 digest ok length ok
0.1, cap 8388608                                          none read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=1410 digest ok length ok
-- 128 int64 fields with 20-digit values
cap 4096                                                  none read=1 matched=1 emitted=1 bytesRead=3522 bytesWritten=3522 digest ok length ok
-- widest names: 128 fields with 1024-byte names of control bytes, 20 records
input record 703620 bytes, output record 703620 bytes
20 records, 8 MiB cap                                     none read=20 matched=20 emitted=20 bytesRead=14072400 bytesWritten=14072400 digest ok length ok
3 records, cap exactly the record                         none read=3 matched=3 emitted=3 bytesRead=2110860 bytesWritten=2110860 digest ok length ok
-- many small records through a tiny writer
20000 records, cap 64                                     none read=20000 matched=20000 emitted=20000 bytesRead=200000 bytesWritten=200000 digest ok length ok
20000 records, cap 9 (record is 9 bytes)                  none read=20000 matched=20000 emitted=20000 bytesRead=220000 bytesWritten=200000 digest ok length ok
-- a record at the cap among small ones
100 small, big, 100 small, 2 big                          none read=203 matched=203 emitted=203 bytesRead=198611 bytesWritten=198611 digest ok length ok
