--TEST--
jsonfuse 1.0: operators of one entry are ANDed, contradictory entries compile and never match, entries are ANDed with each other; an entry without an operator is invalid_value and an unknown key is unknown_option
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$lits = ['1', '2', '3', '5', '7', '2.5', '"a"', '"b"', '"x"', 'null'];
jf10_match_rows([
    ["= 5 and > 1", ['=' => 5, '>' => 1]],
    ["in [1,2,3] and >= 2", ['in' => [1, 2, 3], '>=' => 2]],
    ["in ['b', 5] and > 'a'", ['in' => ['b', 5], '>' => 'a']],
    ["> 1 and < 7 and in [2.5, 7, 3]", ['>' => 1, '<' => 7, 'in' => [2.5, 7, 3]]],
    ["= 1 and > 5 (contradictory)", ['=' => 1, '>' => 5]],
    ["> 5 and < 1 (contradictory)", ['>' => 5, '<' => 1]],
    ["in [1, 2] and = 3 (contradictory)", ['in' => [1, 2], '=' => 3]],
    ["= 'x' and > 5 (compiles)", ['=' => 'x', '>' => 5]],
    ["= null and in [null]", ['=' => null, 'in' => [null]]],
], $lits);

echo "-- entries are ANDed with each other\n";
jf10_show('w = x and v >= 2', "{\"i\":1,\"v\":2,\"w\":\"x\"}\n{\"i\":2,\"v\":1,\"w\":\"x\"}\n{\"i\":3,\"v\":3,\"w\":\"y\"}\n{\"i\":4,\"v\":3}\n",
    ['fields' => ['i' => 'int64'], 'where' => ['w' => 'x', 'v' => ['>=' => 2]]]);

echo "-- compile checks\n";
$c = fn ($e) => jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => $e]]);
foreach ([
    'empty entry' => [],
    'path only' => ['path' => ['v']],
    "'!='" => ['!=' => 1],
    "'=='" => ['==' => 1],
    "' ='" => [' =' => 1],
    "'IN'" => ['IN' => [1]],
    "int key 0" => [0 => 1],
    "a list [1, 2]" => [1, 2],
    "unknown beside '>'" => ['>' => 1, 'foo' => 2],
    "unknown only" => ['foo' => 1],
    "'between'" => ['between' => [1, 5]],
    "'=' holding a list" => ['=' => [1]],
] as $label => $e) {
    echo str_pad($label, 22), $c($e), "\n";
}
echo str_pad('entry named by int 3', 22), jf10_compile(['fields' => ['i' => 'int64'], 'where' => [3 => ['bad' => 1]]]), "\n";
echo str_pad('second entry', 22), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['a' => 1, 'b' => ['<' => 1, 'x' => 0]]]), "\n";
// Keys that start with "path" are not "path".
echo str_pad("'paths' on entry a", 22), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['a' => ['paths' => ['a'], '=' => 1]]]), "\n";
echo str_pad("'path ' on entry a", 22), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['a' => ['path ' => ['a'], '=' => 1]]]), "\n";
?>
--EXPECT--
= 5 and > 1                        [5]
in [1,2,3] and >= 2                [2 3]
in ['b', 5] and > 'a'              ["b"]
> 1 and < 7 and in [2.5, 7, 3]     [3 2.5]
= 1 and > 5 (contradictory)        []
> 5 and < 1 (contradictory)        []
in [1, 2] and = 3 (contradictory)  []
= 'x' and > 5 (compiles)           []
= null and in [null]               [null]
-- entries are ANDed with each other
w = x and v >= 2
  > {"i":1}
  = read=4 matched=1 emitted=1 bytesRead=80 bytesWritten=8
-- compile checks
empty entry           PlanException invalid_value option="where" field="v"
path only             PlanException invalid_value option="where" field="v"
'!='                  PlanException unknown_option option="where" field="v"
'=='                  PlanException unknown_option option="where" field="v"
' ='                  PlanException unknown_option option="where" field="v"
'IN'                  PlanException unknown_option option="where" field="v"
int key 0             PlanException unknown_option option="where" field="v"
a list [1, 2]         PlanException unknown_option option="where" field="v"
unknown beside '>'    PlanException unknown_option option="where" field="v"
unknown only          PlanException unknown_option option="where" field="v"
'between'             PlanException unknown_option option="where" field="v"
'=' holding a list    PlanException invalid_value option="where" field="v"
entry named by int 3  PlanException unknown_option option="where" field="3"
second entry          PlanException unknown_option option="where" field="b"
'paths' on entry a    PlanException unknown_option option="where" field="a"
'path ' on entry a    PlanException unknown_option option="where" field="a"
