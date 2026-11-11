--TEST--
jsonfuse 1.0: inside a where entry the one-kind rule (number and string range operands) is checked after every operand, so a later 'in' count or bad operand is reported first
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$row = function (string $label, array $entry) {
    echo str_pad($label, 44), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['w' => $entry]]), "\n";
};
$many = range(1, 257);

$row("mixed kinds, then 'in' 257 members", ['>' => 1, '<' => 'a', 'in' => $many]);
$row("mixed kinds, then '>=' NAN", ['>' => 1, '<' => 'a', '>=' => NAN]);
$row("mixed kinds, then 'in' empty", ['<' => 'a', '>' => 1, 'in' => []]);
$row("mixed kinds, then '=' an array", ['<=' => 'a', '>=' => 1.5, '=' => [1]]);
$row("'in' 257 members, then mixed kinds", ['in' => $many, '>' => 1, '<' => 'a']);
$row('mixed kinds only', ['>' => 1, '<' => 'a']);
$row("mixed kinds, '=' and 'in' fine", ['>' => 1, '=' => 'x', '<' => 'a', 'in' => [1, 'b']]);
?>
--EXPECT--
mixed kinds, then 'in' 257 members          PlanException too_many option="where" field=null
mixed kinds, then '>=' NAN                  PlanException invalid_value option="where" field="w"
mixed kinds, then 'in' empty                PlanException invalid_value option="where" field="w"
mixed kinds, then '=' an array              PlanException invalid_value option="where" field="w"
'in' 257 members, then mixed kinds          PlanException too_many option="where" field=null
mixed kinds only                            PlanException invalid_value option="where" field="w"
mixed kinds, '=' and 'in' fine              PlanException invalid_value option="where" field="w"
