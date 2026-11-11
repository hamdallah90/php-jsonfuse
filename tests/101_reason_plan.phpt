--TEST--
jsonfuse 1.0: every plan reason code reachable with the basic grammar, with getOption() and getField()
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$f = ['a' => 'int64'];
function c(string $label, array $spec): void
{
    echo str_pad($label, 40), ' ', jf10_compile($spec), "\n";
}

/** compact() keeps a variable named "1" as a string key, and $x[1] then adds the int key 1. */
function same_name(string $value): array
{
    ${'1'} = $value;
    $x = compact('1');
    $x[1] = $value;
    return $x;
}

echo "-- unknown_option: getOption() and getField() are null for an unknown top-level key\n";
c('key Fields', ['fields' => $f, 'Fields' => $f]);
c('int key 0', ['fields' => $f, 0 => 1]);
c('key maxrecordbytes', ['fields' => $f, 'maxrecordbytes' => 10]);

echo "-- missing_option\n";
c('empty spec', []);
c('where only', ['where' => ['a' => 1]]);

echo "-- invalid_option\n";
c('fields is a string', ['fields' => 'a']);
c('fields is empty', ['fields' => []]);
c('where is a string', ['fields' => $f, 'where' => 'a']);
c('where is null', ['fields' => $f, 'where' => null]);
c('output csv', ['fields' => $f, 'output' => 'csv']);
c('output NDJSON', ['fields' => $f, 'output' => 'NDJSON']);
c('maxRecordBytes 0', ['fields' => $f, 'maxRecordBytes' => 0]);
c('maxRecordBytes 67108865', ['fields' => $f, 'maxRecordBytes' => 67108865]);
c('maxRecordBytes "1024"', ['fields' => $f, 'maxRecordBytes' => '1024']);
c('maxRecordBytes 1024.0', ['fields' => $f, 'maxRecordBytes' => 1024.0]);
c('skipEmptyLines 1', ['fields' => $f, 'skipEmptyLines' => 1]);
c('skipEmptyLines "true"', ['fields' => $f, 'skipEmptyLines' => 'true']);

echo "-- too_many\n";
$many = [];
for ($i = 0; $i < 129; $i++) {
    $many["f$i"] = 'int64';
}
c('129 fields', ['fields' => $many]);
c('128 fields', ['fields' => array_slice($many, 0, 128)]);
$w = [];
for ($i = 0; $i < 17; $i++) {
    $w["w$i"] = $i;
}
c('17 where entries', ['fields' => $f, 'where' => $w]);
c('16 where entries', ['fields' => $f, 'where' => array_slice($w, 0, 16)]);

echo "-- invalid_name: getField() is null, the name itself is the invalid part\n";
c('field name not UTF-8', ['fields' => ["\xff" => 'int64']]);
c('field name surrogate', ['fields' => ["\xed\xa0\x80" => 'int64']]);
c('field name 1025 bytes', ['fields' => [str_repeat('n', 1025) => 'int64']]);
c('field name 1024 bytes', ['fields' => [str_repeat('n', 1024) => 'int64']]);
c('where name not UTF-8', ['fields' => $f, 'where' => ["\xc0\xaf" => 1]]);
c('where name 1025 bytes', ['fields' => $f, 'where' => [str_repeat('n', 1025) => 1]]);

echo "-- invalid_type\n";
foreach (['INT64', 'int', 'int64??', '?int64', ' int64', 'int64 ', '', 'float', 'double'] as $t) {
    c('type ' . var_export($t, true), ['fields' => ['a' => $t]]);
}
c('type is the int 5', ['fields' => ['a' => 5]]);
c('type is null', ['fields' => ['a' => null]]);
c('type is true', ['fields' => ['a' => true]]);
c('second field bad', ['fields' => ['a' => 'int64', 'b' => 'str']]);

echo "-- invalid_value\n";
c('where NAN', ['fields' => $f, 'where' => ['w' => NAN]]);
c('where INF', ['fields' => $f, 'where' => ['w' => INF]]);
c('where -INF', ['fields' => $f, 'where' => ['w' => -INF]]);
c('where object', ['fields' => $f, 'where' => ['w' => new stdClass()]]);
c('where string not UTF-8', ['fields' => $f, 'where' => ['w' => "ok\xff"]]);
c('where string 1025 bytes', ['fields' => $f, 'where' => ['w' => str_repeat('v', 1025)]]);
c('where string 1024 bytes', ['fields' => $f, 'where' => ['w' => str_repeat('v', 1024)]]);
c('where empty string', ['fields' => $f, 'where' => ['w' => '']]);
c('where -0.0, null, false, PHP_INT_MIN', ['fields' => $f, 'where' => ['p' => -0.0, 'q' => null, 'r' => false, 's' => PHP_INT_MIN]]);

echo "-- duplicate_name: the string key \"1\" and the int key 1\n";
c('two fields named 1', ['fields' => same_name('int64')]);
c('two where entries named 1', ['fields' => $f, 'where' => same_name('x')]);
c('a field and a where entry named 1', ['fields' => [1 => 'int64'], 'where' => [1 => 5]]);
?>
--EXPECT--
-- unknown_option: getOption() and getField() are null for an unknown top-level key
key Fields                               PlanException unknown_option option=null field=null
int key 0                                PlanException unknown_option option=null field=null
key maxrecordbytes                       PlanException unknown_option option=null field=null
-- missing_option
empty spec                               PlanException missing_option option="fields" field=null
where only                               PlanException missing_option option="fields" field=null
-- invalid_option
fields is a string                       PlanException invalid_option option="fields" field=null
fields is empty                          PlanException invalid_option option="fields" field=null
where is a string                        PlanException invalid_option option="where" field=null
where is null                            PlanException invalid_option option="where" field=null
output csv                               PlanException invalid_option option="output" field=null
output NDJSON                            PlanException invalid_option option="output" field=null
maxRecordBytes 0                         PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes 67108865                  PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes "1024"                    PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes 1024.0                    PlanException invalid_option option="maxRecordBytes" field=null
skipEmptyLines 1                         PlanException invalid_option option="skipEmptyLines" field=null
skipEmptyLines "true"                    PlanException invalid_option option="skipEmptyLines" field=null
-- too_many
129 fields                               PlanException too_many option="fields" field=null
128 fields                               ok
17 where entries                         PlanException too_many option="where" field=null
16 where entries                         ok
-- invalid_name: getField() is null, the name itself is the invalid part
field name not UTF-8                     PlanException invalid_name option="fields" field=null
field name surrogate                     PlanException invalid_name option="fields" field=null
field name 1025 bytes                    PlanException invalid_name option="fields" field=null
field name 1024 bytes                    ok
where name not UTF-8                     PlanException invalid_name option="where" field=null
where name 1025 bytes                    PlanException invalid_name option="where" field=null
-- invalid_type
type 'INT64'                             PlanException invalid_type option="fields" field="a"
type 'int'                               PlanException invalid_type option="fields" field="a"
type 'int64??'                           PlanException invalid_type option="fields" field="a"
type '?int64'                            PlanException invalid_type option="fields" field="a"
type ' int64'                            PlanException invalid_type option="fields" field="a"
type 'int64 '                            PlanException invalid_type option="fields" field="a"
type ''                                  PlanException invalid_type option="fields" field="a"
type 'float'                             PlanException invalid_type option="fields" field="a"
type 'double'                            PlanException invalid_type option="fields" field="a"
type is the int 5                        PlanException invalid_type option="fields" field="a"
type is null                             PlanException invalid_type option="fields" field="a"
type is true                             PlanException invalid_type option="fields" field="a"
second field bad                         PlanException invalid_type option="fields" field="b"
-- invalid_value
where NAN                                PlanException invalid_value option="where" field="w"
where INF                                PlanException invalid_value option="where" field="w"
where -INF                               PlanException invalid_value option="where" field="w"
where object                             PlanException invalid_value option="where" field="w"
where string not UTF-8                   PlanException invalid_value option="where" field="w"
where string 1025 bytes                  PlanException invalid_value option="where" field="w"
where string 1024 bytes                  ok
where empty string                       ok
where -0.0, null, false, PHP_INT_MIN     ok
-- duplicate_name: the string key "1" and the int key 1
two fields named 1                       PlanException duplicate_name option="fields" field="1"
two where entries named 1                PlanException duplicate_name option="where" field="1"
a field and a where entry named 1        ok
