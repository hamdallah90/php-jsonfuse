--TEST--
jsonfuse 1.0: two fields named by the string key "1" and the int key 1 raise duplicate_name in array form too, with or without defaults or different paths, so one value is never projected twice
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * compact() keeps a variable named "1" as a string key, and a later $a[1] adds the int key,
 * so both entries resolve to the name "1" (see 013). The record is long enough that a second
 * copy of its value could not fit the room the writer reserves for one.
 */
function fields_with(array $first, $second): array
{
    ${'1'} = $first;
    $fields = compact('1');
    $fields[1] = $second;
    return $fields;
}

$long = ['type' => 'string', 'default' => str_repeat("\x01", 1024)];
foreach ([
    'array, array' => fields_with(['type' => 'string'], ['type' => 'string']),
    'string, array' => fields_with(['type' => 'string'], 'string'),
    'both with defaults' => fields_with($long, $long),
    'first with a default' => fields_with($long, ['type' => 'string']),
    'two different paths' => fields_with(['type' => 'string', 'path' => ['x']], ['type' => 'string', 'path' => ['y', 'z']]),
    'a path and no path' => fields_with(['type' => 'string', 'path' => ['x']], 'string'),
] as $label => $fields) {
    echo str_pad($label, 24), implode(', ', array_map('gettype', array_keys($fields))), ': ', jf10_compile(['fields' => $fields]), "\n";
}

echo "-- one entry runs\n";
$data = '{"1":"' . str_repeat('x', 100000) . "\"}\n";
[$o, $r] = jf10_run($data . "{}\n", ['fields' => [1 => $long]]);
echo $r, "\n";
var_dump($o === $data . '{"1":"' . str_repeat('\u0001', 1024) . "\"}\n");
?>
--EXPECT--
array, array            string, integer: PlanException duplicate_name option="fields" field="1"
string, array           string, integer: PlanException duplicate_name option="fields" field="1"
both with defaults      string, integer: PlanException duplicate_name option="fields" field="1"
first with a default    string, integer: PlanException duplicate_name option="fields" field="1"
two different paths     string, integer: PlanException duplicate_name option="fields" field="1"
a path and no path      string, integer: PlanException duplicate_name option="fields" field="1"
-- one entry runs
read=2 matched=2 emitted=2 bytesRead=100012 bytesWritten=106162
bool(true)
