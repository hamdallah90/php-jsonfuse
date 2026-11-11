--TEST--
jsonfuse 1.0: an array field spec ['type' => ...] is the same plan as the type string; a missing or invalid type in it is invalid_type
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$input = "{\"a\":1,\"b\":null,\"c\":\"x\",\"d\":true}\n{\"a\":-2,\"b\":2.5,\"c\":\"\",\"d\":false}\n";

echo "-- string form and array form give the same output and stats\n";
$string = ['fields' => ['a' => 'int64', 'b' => 'float64?', 'c' => 'string', 'd' => 'bool']];
$array = ['fields' => ['a' => ['type' => 'int64'], 'b' => ['type' => 'float64?'], 'c' => ['type' => 'string'], 'd' => ['type' => 'bool']]];
$mixed = ['fields' => ['a' => 'int64', 'b' => ['type' => 'float64?'], 'c' => 'string', 'd' => ['type' => 'bool']]];
[$o1, $r1] = jf10_run($input, $string);
jf10_print('string form', $o1, $r1);
foreach (['array form' => $array, 'mixed forms' => $mixed] as $label => $spec) {
    [$o, $r] = jf10_run($input, $spec);
    echo $label, ' same: ', var_export($o === $o1 && $r === $r1, true), "\n";
}

echo "-- invalid_type in an array spec\n";
foreach ([
    'empty array' => [],
    'default only' => ['default' => 1],
    'type INT64' => ['type' => 'INT64'],
    'type int64??' => ['type' => 'int64??'],
    'type the int 5' => ['type' => 5],
    'type null' => ['type' => null],
    'type an array' => ['type' => ['int64']],
] as $label => $fs) {
    echo str_pad($label, 20), jf10_compile(['fields' => ['id' => 'int64', 'a' => $fs]]), "\n";
}
?>
--EXPECT--
-- string form and array form give the same output and stats
string form
  > {"a":1,"b":null,"c":"x","d":true}
  > {"a":-2,"b":2.5,"c":"","d":false}
  = read=2 matched=2 emitted=2 bytesRead=68 bytesWritten=68
array form same: true
mixed forms same: true
-- invalid_type in an array spec
empty array         PlanException invalid_type option="fields" field="a"
default only        PlanException invalid_type option="fields" field="a"
type INT64          PlanException invalid_type option="fields" field="a"
type int64??        PlanException invalid_type option="fields" field="a"
type the int 5      PlanException invalid_type option="fields" field="a"
type null           PlanException invalid_type option="fields" field="a"
type an array       PlanException invalid_type option="fields" field="a"
