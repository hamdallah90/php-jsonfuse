--TEST--
jsonfuse 1.0: a key not in the grammar inside a field spec raises unknown_option with getOption() 'fields' and getField() naming the entry
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

foreach ([
    'Default' => ['a' => ['type' => 'int64', 'Default' => 1]],
    'TYPE beside type' => ['a' => ['type' => 'int64', 'TYPE' => 'int64']],
    'int key 0 beside type' => ['a' => ['type' => 'int64', 0 => 'x']],
    'list form [int64]' => ['a' => ['int64']],
    'only an unknown key' => ['a' => ['foo' => 1]],
    'nullable key' => ['a' => ['type' => 'int64', 'nullable' => true]],
    'entry named by an int key' => [5 => ['type' => 'int64', 'bad' => 1]],
    'second entry' => ['a' => 'int64', 'b' => ['type' => 'bool', 'default' => true, 'where' => 1]],
] as $label => $fields) {
    echo str_pad($label, 28), jf10_compile(['fields' => $fields]), "\n";
}
echo str_pad('default at the top level', 28), jf10_compile(['fields' => ['a' => 'int64'], 'default' => 1]), "\n";
echo str_pad('type and default only', 28), jf10_compile(['fields' => ['a' => ['default' => 1, 'type' => 'int64']]]), "\n";
?>
--EXPECT--
Default                     PlanException unknown_option option="fields" field="a"
TYPE beside type            PlanException unknown_option option="fields" field="a"
int key 0 beside type       PlanException unknown_option option="fields" field="a"
list form [int64]           PlanException unknown_option option="fields" field="a"
only an unknown key         PlanException unknown_option option="fields" field="a"
nullable key                PlanException unknown_option option="fields" field="a"
entry named by an int key   PlanException unknown_option option="fields" field="5"
second entry                PlanException unknown_option option="fields" field="b"
default at the top level    PlanException unknown_option option=null field=null
type and default only       ok
