--TEST--
jsonfuse 1.0: 'in' takes a list of 1 to 256 scalars and matches by the '=' rule; empty, non-list and bad members are invalid_value, 257 members too_many
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$lits = ['1', '1.0', '"1"', '0', '-0', '0.0', '-0.0', 'true', 'false', 'null', '""', '"a"', '"b"', '255', '256', '1e400'];
$rows = [
    ['in [1, "1", true]', ['in' => [1, '1', true]]],
    ['in [1.0, null]', ['in' => [1.0, null]]],
    ['in [0]', ['in' => [0]]],
    ['in [-0.0]', ['in' => [-0.0]]],
    ['in ["a", "a", "a"]', ['in' => ['a', 'a', 'a']]],
    ['in [false, 0, ""]', ['in' => [false, 0, '']]],
    ['in 0..255 (256 members)', ['in' => range(0, 255)]],
    ['in ["b"] via a path', ['path' => ['v'], 'in' => ['b']]],
];
jf10_match_rows($rows, $lits);

echo "-- compile checks\n";
$c = fn ($op) => jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => ['in' => $op]]]);
foreach ([
    'empty list' => [],
    '257 members' => range(0, 256),
    'string keys' => ['a' => 1],
    'list with a hole' => [1 => 1],
    'a scalar' => 5,
    'null' => null,
    'a nested list' => [[1]],
    'NAN member' => [1, NAN],
    'INF member' => [INF],
    'bad UTF-8 member' => ['ok', "\xff"],
    '1025-byte member' => [str_repeat('m', 1025)],
    '1024-byte member' => [str_repeat('m', 1024)],
    'object member' => [new stdClass()],
] as $label => $op) {
    echo str_pad($label, 24), $c($op), "\n";
}

echo "-- a record array or object is where_not_scalar\n";
echo jf10_match(['in' => [1]], ['1', '[1]']), "\n";
echo jf10_match(['in' => [1]], ['{"x":1}']), "\n";
?>
--EXPECT--
in [1, "1", true]                  [1 "1" true]
in [1.0, null]                     [1.0 null]
in [0]                             [0 -0]
in [-0.0]                          [0.0 -0.0]
in ["a", "a", "a"]                 ["a"]
in [false, 0, ""]                  [0 -0 false ""]
in 0..255 (256 members)            [1 0 -0 255]
in ["b"] via a path                ["b"]
-- compile checks
empty list              PlanException invalid_value option="where" field="v"
257 members             PlanException too_many option="where" field=null
string keys             PlanException invalid_value option="where" field="v"
list with a hole        PlanException invalid_value option="where" field="v"
a scalar                PlanException invalid_value option="where" field="v"
null                    PlanException invalid_value option="where" field="v"
a nested list           PlanException invalid_value option="where" field="v"
NAN member              PlanException invalid_value option="where" field="v"
INF member              PlanException invalid_value option="where" field="v"
bad UTF-8 member        PlanException invalid_value option="where" field="v"
1025-byte member        PlanException invalid_value option="where" field="v"
1024-byte member        ok
object member           PlanException invalid_value option="where" field="v"
-- a record array or object is where_not_scalar
RecordException where_not_scalar field="v" at=2/2/14 [read=1 matched=1 emitted=1 bytesRead=14 bytesWritten=8]
RecordException where_not_scalar field="v" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
