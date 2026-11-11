--TEST--
jsonfuse: validation order per record - framing, JSON, root, duplicates, fields in declared order, where keys in declared order, then filters
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;
use JsonFuse\Runner;

/*
 * SPEC.md section 5 lists seven steps and says the first failure is reported. Every failure is a
 * RecordException with the same line/record/offset prefix, so the only way to observe WHICH
 * step failed is the "safe field name" the Error reporting section says an invalid record
 * message carries. Field names below are distinctive (fa_1, wk_b ...) and appear in no other
 * message text. Cases for steps 1-3 assert that no declared field is blamed. A message must
 * never carry record bytes: the values hold the marker SECRET.
 */
const ALL_NAMES = ['fa_1', 'fb_2', 'fc_3', 'fd_4', 'fx', 'wk_a', 'wk_b', 'wk_1', 'wk_2'];

function order(string $label, string $data, array $spec, string $ctx, ?string $names): void
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $s = Runner::process($in, $out, jf_plan($spec));
        $r = 'FAIL no exception, ' . jf_stats($s);
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (!($e instanceof RecordException)) {
            $r = 'FAIL class ' . get_class($e);
        } elseif (jf_ctx($m) !== $ctx) {
            $r = 'FAIL ctx ' . jf_ctx($m) . ' want ' . $ctx;
        } elseif (str_contains($m, 'SECRET')) {
            $r = 'FAIL message leaks record bytes';
        } elseif ($names !== null && !str_contains($m, $names)) {
            $r = "FAIL message does not name $names: $m";
        } else {
            $r = 'ok';
            foreach (ALL_NAMES as $other) {
                if ($other !== $names && str_contains($m, $other)) {
                    $r = "FAIL message names $other, want " . ($names ?? 'no field') . ": $m";
                }
            }
        }
    } finally {
        fclose($in);
        fclose($out);
    }
    echo str_pad($label, 70), $r, "\n";
}

const C1 = 'line=1 record=1 offset=0';

$P = ['fields' => ['fa_1' => 'int64', 'fb_2' => 'string', 'fc_3' => 'bool?'], 'where' => ['wk_1' => 'a', 'wk_2' => 5]];

echo "-- steps 1 to 3 blame no field\n";
order('1 size cap beats a syntax error', '{"fa_1":"SECRETSECRETSECRET",' . "\n", $P + ['maxRecordBytes' => 16], C1, null);
order('1 size cap beats missing fields', '{"zzzzzzzzzzzzzzzzzzzzzzzz":1}' . "\n", $P + ['maxRecordBytes' => 16], C1, null);
order('1 blank first line without skipEmptyLines', "\n" . '{"fa_1":"SECRET"}' . "\n", $P, C1, null);
order('2 syntax error beats a type error before it', '{"fa_1":"SECRET","fb_2":}' . "\n", $P, C1, null);
order('2 syntax error beats a missing field', '{"fb_2":"SECRET",}' . "\n", $P, C1, null);
order('2 invalid UTF-8 beats a missing field', "{\"fb_2\":\"\xC0\x80\"}\n", $P, C1, null);
order('2 invalid UTF-8 in an unreferenced key', "{\"fa_1\":1,\"fb_2\":\"s\",\"\xFF\":1}\n", $P, C1, null);
order('2 lone surrogate beats duplicate keys', '{"fa_1":1,"fa_1":2,"u":"\ud800"}' . "\n", $P, C1, null);
order('2 nesting beyond 511 beats a missing field', '{"u":' . str_repeat('[', 511) . str_repeat(']', 511) . "}\n", $P, C1, null);
order('3 root array beats everything', '[{"fa_1":"SECRET"}]' . "\n", $P, C1, null);
order('3 root string', '"SECRET"' . "\n", $P, C1, null);
order('3 root null', "null\n", $P, C1, null);

echo "-- step 4 duplicates come before field checks\n";
order('4 duplicate fb_2 beats a type error in fa_1', '{"fa_1":"SECRET","fb_2":"a","fb_2":"b","fc_3":null}' . "\n", $P, C1, 'fb_2');
order('4 duplicate fa_1 with both values valid', '{"fa_1":1,"fb_2":"a","fa_1":1}' . "\n", $P, C1, 'fa_1');
order('4 duplicate where-only key beats missing fields', '{"wk_1":"a","wk_1":"a"}' . "\n", $P, C1, 'wk_1');
order('4 duplicate fb_2 beats a non-scalar where value', '{"fa_1":1,"fb_2":"s","wk_2":[1],"fb_2":"t"}' . "\n", $P, C1, 'fb_2');
order('4 duplicate where key wk_2 beats a type error', '{"fa_1":"SECRET","wk_2":5,"wk_2":6}' . "\n", $P, C1, 'wk_2');
order('4 duplicate through a \u escape', '{"fa_1":1,"fb_2":"s","fa_\u0031":2}' . "\n", $P, C1, 'fa_1');

echo "-- step 5 fields in declared order\n";
$Q = ['fields' => ['fd_4' => 'int64', 'fa_1' => 'string', 'fb_2' => 'bool', 'fc_3' => 'float64']];
order('5 four wrong types, record order differs', '{"fb_2":"SECRET","fc_3":"SECRET","fa_1":1,"fd_4":"x"}' . "\n", $Q, C1, 'fd_4');
order('5 declared-first is missing, second is wrong', '{"fa_1":1,"fb_2":"SECRET"}' . "\n", $Q, C1, 'fd_4');
order('5 declared-first is fine, second wrong, third missing', '{"fd_4":1,"fa_1":5}' . "\n", $Q, C1, 'fa_1');
order('5 first two fine, third null, fourth missing', '{"fd_4":1,"fa_1":"s","fb_2":null}' . "\n", $Q, C1, 'fb_2');
order('5 first three fine, fourth is RAW', '{"fd_4":1,"fa_1":"s","fb_2":true,"fc_3":1e400}' . "\n", $Q, C1, 'fc_3');
order('5 only the last declared field is wrong, record-first', '{"fc_3":"SECRET","fd_4":1,"fa_1":"s","fb_2":true}' . "\n", $Q, C1, 'fc_3');
order('5 null in an earlier non-nullable beats a later wrong type', '{"fd_4":null,"fa_1":"s","fb_2":"SECRET","fc_3":1}' . "\n", $Q, C1, 'fd_4');
order('5 field error beats a non-scalar where value', '{"wk_1":[1]}' . "\n", ['fields' => ['fa_1' => 'int64'], 'where' => ['wk_1' => 'a']], C1, 'fa_1');
order('5 type error in a record that does not match the filter', '{"fa_1":"SECRET","wk_1":"zzz"}' . "\n", ['fields' => ['fa_1' => 'int64'], 'where' => ['wk_1' => 'a']], C1, 'fa_1');
order('5 key in both fields and where, wrong type', '{"fa_1":"SECRET"}' . "\n", ['fields' => ['fa_1' => 'int64'], 'where' => ['fa_1' => 5]], C1, 'fa_1');
order('5 key in both fields and where, null not allowed', '{"fa_1":null}' . "\n", ['fields' => ['fa_1' => 'int64'], 'where' => ['fa_1' => null]], C1, 'fa_1');

echo "-- step 6 where keys in declared order, before any filter is evaluated\n";
$W = ['fields' => ['fx' => 'int64'], 'where' => ['wk_b' => 1, 'wk_a' => 2]];
order('6 both arrays, record order a then b: declared b first', '{"fx":1,"wk_a":[1],"wk_b":{}}' . "\n", $W, C1, 'wk_b');
order('6 only wk_a is an array', '{"fx":1,"wk_a":[1],"wk_b":1}' . "\n", $W, C1, 'wk_a');
order('6 wk_b mismatches, wk_a is an array', '{"fx":1,"wk_b":9,"wk_a":[1]}' . "\n", $W, C1, 'wk_a');
order('6 wk_b missing, wk_a is an object', '{"fx":1,"wk_a":{}}' . "\n", $W, C1, 'wk_a');
order('6 wk_b is an array, wk_a already mismatches', '{"fx":1,"wk_a":3,"wk_b":[]}' . "\n", $W, C1, 'wk_b');

echo "-- step 7 never raises: a clean mismatch is not an error\n";
foreach ([
    ['valid record, mismatching filter', '{"fx":1,"wk_b":9,"wk_a":2}', 'nomatch'],
    ['valid record, filter keys missing', '{"fx":1}', 'nomatch'],
    ['valid record, filter keys of another type', '{"fx":1,"wk_b":"1","wk_a":null}', 'nomatch'],
    ['valid record, filter keys RAW numbers', '{"fx":1,"wk_b":1e400,"wk_a":-1e400}', 'nomatch'],
    ['valid record, all filters match', '{"fx":1,"wk_b":1,"wk_a":2}', 'match'],
] as [$label, $record, $want]) {
    $got = jf_v($record, $W);
    echo str_pad($label, 70), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
}

echo "-- the first failing record ends the run\n";
$R = ['fields' => ['fa_1' => 'int64'], 'where' => ['wk_1' => 'a']];
$ok = '{"fa_1":1,"wk_1":"a"}';
$no = '{"fa_1":2,"wk_1":"b"}';
order('valid non-matching record, then a type error on line 2', "$no\n" . '{"fa_1":"SECRET","wk_1":"a"}' . "\n", $R, 'line=2 record=2 offset=' . (strlen($no) + 1), 'fa_1');
order('two bad records: the first is reported', '{"fa_1":"SECRET"}' . "\n" . '{"fa_1":[1]}' . "\n", $R, C1, 'fa_1');
order('syntax error on line 2 beats a type error on line 3', "$ok\n{\"fa_1\":1,}\n" . '{"fa_1":"SECRET"}' . "\n", $R, 'line=2 record=2 offset=' . (strlen($ok) + 1), null);
order('duplicate on line 3 after two matches', "$ok\n$ok\n" . '{"fa_1":1,"fa_1":1}' . "\n" . "{\n", $R, 'line=3 record=3 offset=' . (2 * strlen($ok) + 2), 'fa_1');
order('oversize line 2 beats a bad line 3', "$ok\n" . str_repeat(' ', 80) . '{"fa_1":"SECRET"}' . "\n" . "[\n", $R + ['maxRecordBytes' => 64], 'line=2 record=2 offset=' . (strlen($ok) + 1), null);
order('blank line 2 (no skip) beats a type error on line 3', "$ok\n\n" . '{"fa_1":"SECRET"}' . "\n", $R, 'line=2 record=2 offset=' . (strlen($ok) + 1), null);
order('blank line 2 skipped, type error on line 3 is record 2', "$ok\n\n" . '{"fa_1":"SECRET"}' . "\n", $R + ['skipEmptyLines' => true], 'line=3 record=2 offset=' . (strlen($ok) + 2), 'fa_1');
?>
--EXPECT--
-- steps 1 to 3 blame no field
1 size cap beats a syntax error                                       ok
1 size cap beats missing fields                                       ok
1 blank first line without skipEmptyLines                             ok
2 syntax error beats a type error before it                           ok
2 syntax error beats a missing field                                  ok
2 invalid UTF-8 beats a missing field                                 ok
2 invalid UTF-8 in an unreferenced key                                ok
2 lone surrogate beats duplicate keys                                 ok
2 nesting beyond 511 beats a missing field                            ok
3 root array beats everything                                         ok
3 root string                                                         ok
3 root null                                                           ok
-- step 4 duplicates come before field checks
4 duplicate fb_2 beats a type error in fa_1                           ok
4 duplicate fa_1 with both values valid                               ok
4 duplicate where-only key beats missing fields                       ok
4 duplicate fb_2 beats a non-scalar where value                       ok
4 duplicate where key wk_2 beats a type error                         ok
4 duplicate through a \u escape                                       ok
-- step 5 fields in declared order
5 four wrong types, record order differs                              ok
5 declared-first is missing, second is wrong                          ok
5 declared-first is fine, second wrong, third missing                 ok
5 first two fine, third null, fourth missing                          ok
5 first three fine, fourth is RAW                                     ok
5 only the last declared field is wrong, record-first                 ok
5 null in an earlier non-nullable beats a later wrong type            ok
5 field error beats a non-scalar where value                          ok
5 type error in a record that does not match the filter               ok
5 key in both fields and where, wrong type                            ok
5 key in both fields and where, null not allowed                      ok
-- step 6 where keys in declared order, before any filter is evaluated
6 both arrays, record order a then b: declared b first                ok
6 only wk_a is an array                                               ok
6 wk_b mismatches, wk_a is an array                                   ok
6 wk_b missing, wk_a is an object                                     ok
6 wk_b is an array, wk_a already mismatches                           ok
-- step 7 never raises: a clean mismatch is not an error
valid record, mismatching filter                                      ok
valid record, filter keys missing                                     ok
valid record, filter keys of another type                             ok
valid record, filter keys RAW numbers                                 ok
valid record, all filters match                                       ok
-- the first failing record ends the run
valid non-matching record, then a type error on line 2                ok
two bad records: the first is reported                                ok
syntax error on line 2 beats a type error on line 3                   ok
duplicate on line 3 after two matches                                 ok
oversize line 2 beats a bad line 3                                    ok
blank line 2 (no skip) beats a type error on line 3                   ok
blank line 2 skipped, type error on line 3 is record 2                ok
