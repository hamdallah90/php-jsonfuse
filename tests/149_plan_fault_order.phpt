--TEST--
jsonfuse 1.0: a plan with several faults reports the first in the compile fault order: unknown keys, missing fields, the fields option and entries, the where option and entries, the other options, then cross-entry conflicts; inside an entry the path before the operators and the in count before its members
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$row = function (string $label, array $spec) {
    echo str_pad($label, 44), jf10_compile($spec), "\n";
};
$f = ['i' => 'int64'];
$many = range(1, 257);

echo "-- (a) top-level options and entry faults, in the fixed sequence\n";
$row('unknown top-level key, no fields', ['bogus' => 1]);
$row('unknown top-level key, bad field type', ['fields' => ['i' => 'int8'], 'bogus' => 1]);
$row('unknown top-level key, bad where operand', ['bogus' => 1, 'fields' => $f, 'where' => ['w' => ['=' => NAN]]]);
$row("'where' not an array, bad field type", ['fields' => ['i' => 'int8'], 'where' => 'x']);
$row('17 where entries, one of them bad', ['fields' => $f, 'where' => array_fill_keys(range(1, 16), 1) + ['w' => ['foo' => 1]]]);
$row('maxRecordBytes 0, bad where path', ['fields' => $f, 'where' => ['w' => ['path' => [0], '=' => 1]], 'maxRecordBytes' => 0]);
$row("'where' not an array, equal field paths", ['fields' => ['a' => 'int64', 'b' => ['type' => 'int64', 'path' => ['a']]], 'where' => 'x']);
$row('skipEmptyLines 1, equal field paths', ['fields' => ['a' => 'int64', 'b' => ['type' => 'int64', 'path' => ['a']]], 'skipEmptyLines' => 1]);

echo "-- (c) the path before the operators in one entry\n";
$row("int segment, '=' NAN", ['fields' => $f, 'where' => ['w' => ['path' => ['a', 0], '=' => NAN]]]);
$row("'=' NAN before an int segment", ['fields' => $f, 'where' => ['w' => ['=' => NAN, 'path' => [0]]]]);
$row("bad segment bytes, empty 'in'", ['fields' => $f, 'where' => ['w' => ['path' => ["\xff"], 'in' => []]]]);
$row('int segment, no operator', ['fields' => $f, 'where' => ['w' => ['path' => [0]]]]);
$row('path of 9 bad segments, mixed range kinds', ['fields' => $f, 'where' => ['w' => ['path' => array_fill(0, 9, "\xff"), '>' => 1, '<' => 'a']]]);
$row('bad bytes, then an int segment', ['fields' => $f, 'where' => ['w' => ['path' => ["\xff", 0], '=' => 1]]]);
$row('int segment, then bad bytes', ['fields' => $f, 'where' => ['w' => ['path' => [0, "\xff"], '=' => 1]]]);
$row('unknown key, bad path', ['fields' => $f, 'where' => ['w' => ['path' => [0], 'foo' => 1]]]);

echo "-- (d) the in count before its members, operators in key order\n";
$row('257 members, the last NAN', ['fields' => $f, 'where' => ['w' => ['in' => [...range(1, 256), NAN]]]]);
$row('257 members, the first an array', ['fields' => $f, 'where' => ['w' => ['in' => [[1], ...range(1, 256)]]]]);
$row("'in' 257 members, then '=' NAN", ['fields' => $f, 'where' => ['w' => ['in' => $many, '=' => NAN]]]);
$row("'=' NAN, then 'in' 257 members", ['fields' => $f, 'where' => ['w' => ['=' => NAN, 'in' => $many]]]);
$row("'>' INF, then 'in' 257 members", ['fields' => $f, 'where' => ['w' => ['>' => INF, 'in' => $many]]]);
$row("unknown key, 'in' 257 members", ['fields' => $f, 'where' => ['w' => ['in' => $many, 'foo' => 1]]]);

echo "-- per-entry faults before cross-entry conflicts, fields before where\n";
$dup = ['a' => 'int64', 'b' => ['type' => 'int64', 'path' => ['a']]];
$pre = ['a' => 'int64', 'b' => ['type' => 'int64', 'path' => ['a', 'x']]];
$row('equal field paths, where operand NAN', ['fields' => $dup, 'where' => ['w' => ['=' => NAN]]]);
$row('prefix field paths, where unknown key', ['fields' => $pre, 'where' => ['w' => ['foo' => 1]]]);
$row('equal field paths, where bad segment', ['fields' => $dup, 'where' => ['w' => ['path' => ["\xff"], '=' => 1]]]);
$row('equal field paths, scalar where NAN', ['fields' => $dup, 'where' => ['w' => NAN]]);
$row('where p prefix of a field, where q bad', ['fields' => ['a' => 'int64'], 'where' => ['p' => ['path' => ['a', 'x'], '=' => 1], 'q' => ['in' => []]]]);
$row('where p equal to where o, where q bad', ['fields' => $f, 'where' => ['o' => 1, 'p' => ['path' => ['o'], '=' => 2], 'q' => ['in' => $many]]]);
$row('bad field type, bad where operand', ['fields' => ['i' => 'int8'], 'where' => ['w' => ['=' => NAN]]]);
$row('equal field paths, where prefix of a field', ['fields' => $dup, 'where' => ['w' => ['path' => ['a', 'y'], '=' => 1]]]);
?>
--EXPECT--
-- (a) top-level options and entry faults, in the fixed sequence
unknown top-level key, no fields            PlanException unknown_option option=null field=null
unknown top-level key, bad field type       PlanException unknown_option option=null field=null
unknown top-level key, bad where operand    PlanException unknown_option option=null field=null
'where' not an array, bad field type        PlanException invalid_type option="fields" field="i"
17 where entries, one of them bad           PlanException too_many option="where" field=null
maxRecordBytes 0, bad where path            PlanException invalid_path option="where" field="w"
'where' not an array, equal field paths     PlanException invalid_option option="where" field=null
skipEmptyLines 1, equal field paths         PlanException invalid_option option="skipEmptyLines" field=null
-- (c) the path before the operators in one entry
int segment, '=' NAN                        PlanException invalid_path option="where" field="w"
'=' NAN before an int segment               PlanException invalid_path option="where" field="w"
bad segment bytes, empty 'in'               PlanException invalid_name option="where" field=null
int segment, no operator                    PlanException invalid_path option="where" field="w"
path of 9 bad segments, mixed range kinds   PlanException invalid_path option="where" field="w"
bad bytes, then an int segment              PlanException invalid_name option="where" field=null
int segment, then bad bytes                 PlanException invalid_path option="where" field="w"
unknown key, bad path                       PlanException unknown_option option="where" field="w"
-- (d) the in count before its members, operators in key order
257 members, the last NAN                   PlanException too_many option="where" field=null
257 members, the first an array             PlanException too_many option="where" field=null
'in' 257 members, then '=' NAN              PlanException too_many option="where" field=null
'=' NAN, then 'in' 257 members              PlanException invalid_value option="where" field="w"
'>' INF, then 'in' 257 members              PlanException invalid_value option="where" field="w"
unknown key, 'in' 257 members               PlanException unknown_option option="where" field="w"
-- per-entry faults before cross-entry conflicts, fields before where
equal field paths, where operand NAN        PlanException invalid_value option="where" field="w"
prefix field paths, where unknown key       PlanException unknown_option option="where" field="w"
equal field paths, where bad segment        PlanException invalid_name option="where" field=null
equal field paths, scalar where NAN         PlanException invalid_value option="where" field="w"
where p prefix of a field, where q bad      PlanException invalid_value option="where" field="q"
where p equal to where o, where q bad       PlanException too_many option="where" field=null
bad field type, bad where operand           PlanException invalid_type option="fields" field="i"
equal field paths, where prefix of a field  PlanException duplicate_name option="fields" field="b"
