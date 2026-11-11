--TEST--
jsonfuse 1.0: a present intermediate of any non-object kind, null included, raises path_not_object even with a default; a missing intermediate makes the field missing
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\RecordException;
use JsonFuse\Runner;

function one(string $record, array $fields): string
{
    [$o, $r] = jf10_run($record . "\n", ['fields' => $fields]);
    return str_starts_with($r, 'read=') ? rtrim($o, "\n") : preg_replace('/ \[.*\]$/', '', $r);
}

$plain = ['v' => ['type' => 'int64', 'path' => ['a', 'b']]];
$withDefault = ['v' => ['type' => 'int64', 'path' => ['a', 'b'], 'default' => 0]];
$three = ['v' => ['type' => 'int64', 'path' => ['a', 'b', 'c']]];

echo "-- intermediate kinds, without and with a default\n";
foreach (['[1]', '[]', '"s"', '""', '1', '-1.5', 'true', 'false', 'null', '{"b":4}', '{}'] as $lit) {
    echo str_pad("a = $lit", 16), str_pad(one("{\"a\":$lit}", $plain), 60), one("{\"a\":$lit}", $withDefault), "\n";
}
echo str_pad('a missing', 16), str_pad(one('{"x":1}', $plain), 60), one('{"x":1}', $withDefault), "\n";

echo "-- a deeper intermediate\n";
foreach (['{"a":{"b":5}}', '{"a":{"b":null}}', '{"a":{"b":[{"c":1}]}}', '{"a":{"b":{"c":3}}}', '{"a":{"x":1}}', '{"a":{"b":{}}}'] as $rec) {
    echo str_pad($rec, 24), one($rec, $three), "\n";
}

echo "-- the leaf is type-checked as before\n";
echo str_pad('leaf is an object', 24), one('{"a":{"b":{}}}', $plain), "\n";
echo str_pad('leaf is null', 24), one('{"a":{"b":null}}', $plain), "\n";

echo "-- fields are resolved in declared order\n";
$order = ['m' => ['type' => 'int64', 'path' => ['p', 'q']], 'n' => ['type' => 'int64', 'path' => ['a', 'b']]];
echo str_pad('first missing, second not object', 36), one('{"a":7}', $order), "\n";
echo str_pad('first not object, second missing', 36), one('{"p":[]}', $order), "\n";

echo "-- position and progress\n";
jf10_show('third record', "{\"a\":{\"b\":1}}\n{\"a\":{}}\n{\"a\":null}\n", ['fields' => $withDefault]);
?>
--EXPECT--
-- intermediate kinds, without and with a default
a = [1]         RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = []          RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = "s"         RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = ""          RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = 1           RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = -1.5        RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = true        RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = false       RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = null        RecordException path_not_object field="v" at=1/1/0          RecordException path_not_object field="v" at=1/1/0
a = {"b":4}     {"v":4}                                                     {"v":4}
a = {}          RecordException missing_field field="v" at=1/1/0            {"v":0}
a missing       RecordException missing_field field="v" at=1/1/0            {"v":0}
-- a deeper intermediate
{"a":{"b":5}}           RecordException path_not_object field="v" at=1/1/0
{"a":{"b":null}}        RecordException path_not_object field="v" at=1/1/0
{"a":{"b":[{"c":1}]}}   RecordException path_not_object field="v" at=1/1/0
{"a":{"b":{"c":3}}}     {"v":3}
{"a":{"x":1}}           RecordException missing_field field="v" at=1/1/0
{"a":{"b":{}}}          RecordException missing_field field="v" at=1/1/0
-- the leaf is type-checked as before
leaf is an object       RecordException type_mismatch field="v" at=1/1/0
leaf is null            RecordException null_field field="v" at=1/1/0
-- fields are resolved in declared order
first missing, second not object    RecordException missing_field field="m" at=1/1/0
first not object, second missing    RecordException path_not_object field="m" at=1/1/0
-- position and progress
third record
  > {"v":1}
  > {"v":0}
  = RecordException path_not_object field="v" at=3/3/23 [read=2 matched=2 emitted=2 bytesRead=23 bytesWritten=16]
