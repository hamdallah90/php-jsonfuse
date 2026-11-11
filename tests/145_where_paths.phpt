--TEST--
jsonfuse 1.0: where entries with nested paths: missing intermediates never match, non-object intermediates are path_not_object, non-scalar leaves where_not_scalar; shared and prefix paths across sections; where never sees defaults
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

function w(string $label, string $records, array $spec): void
{
    [$o, $r] = jf10_run($records, $spec + ['fields' => ['i' => 'int64']]);
    echo str_pad($label, 44), str_starts_with($r, 'read=') ? '[' . implode(' ', array_map(fn ($l) => json_decode($l, true)['i'], jf_lines($o))) . ']' : preg_replace('/ \[.*\]$/', '', $r), "\n";
}

$ab = ['where' => ['w' => ['path' => ['a', 'b'], '=' => 1]]];
echo "-- matching on a nested path\n";
w('= 1 at a.b', "{\"i\":1,\"a\":{\"b\":1}}\n{\"i\":2,\"a\":{\"b\":2}}\n{\"i\":3,\"a\":{}}\n{\"i\":4}\n{\"i\":5,\"b\":1}\n", $ab);
w('8 segments, string range', "{\"i\":1,\"p\":{\"q\":{\"r\":{\"s\":{\"t\":{\"u\":{\"v\":{\"w\":\"m\"}}}}}}}}\n{\"i\":2,\"p\":{}}\n",
    ['where' => ['w' => ['path' => ['p', 'q', 'r', 's', 't', 'u', 'v', 'w'], '>' => 'a']]]);
echo "-- non-object intermediates and non-scalar leaves\n";
foreach (['[1]', '"s"', '5', 'true', 'null'] as $lit) {
    w("a = $lit", "{\"i\":1,\"a\":$lit}\n", $ab);
}
foreach (['=' => 1, 'in' => [1], '>' => 0, '<' => 'z'] as $op => $operand) {
    w("leaf array under '$op'", "{\"i\":1,\"a\":{\"b\":[1]}}\n", ['where' => ['w' => ['path' => ['a', 'b'], $op => $operand]]]);
}
w('leaf object', "{\"i\":1,\"a\":{\"b\":{}}}\n", $ab);

echo "-- a where path shared with a fields path\n";
$shared = ['fields' => ['i' => 'int64', 'x' => ['type' => 'int64', 'path' => ['a', 'b'], 'default' => 7]],
    'where' => ['w' => ['path' => ['a', 'b'], '=' => 7]]];
w('field type-checked first', "{\"i\":1,\"a\":{\"b\":\"q\"}}\n", $shared);
w('missing leaf: default out, where unmatched', "{\"i\":1,\"a\":{}}\n{\"i\":2,\"a\":{\"b\":7}}\n{\"i\":3}\n", $shared);

echo "-- the walk follows where paths too; fields come first in getField()\n";
w('duplicate under a where-only path', "{\"i\":1,\"a\":{\"b\":1,\"b\":2}}\n", $ab);
w('duplicate intermediate on both sections', "{\"i\":1,\"a\":{\"b\":1,\"c\":2},\"a\":{}}\n",
    ['fields' => ['i' => 'int64', 'f' => ['type' => 'int64', 'path' => ['a', 'c']]], 'where' => ['w' => ['path' => ['a', 'b'], '=' => 1]]]);
w('field path_not_object before where checks', "{\"i\":1,\"a\":[]}\n",
    ['fields' => ['i' => 'int64', 'f' => ['type' => 'int64', 'path' => ['a', 'c']]], 'where' => ['w' => ['path' => ['z', 'b'], '=' => 1]]]);

echo "-- compile rules across sections\n";
$c = fn (array $spec) => jf10_compile($spec + ['fields' => ['i' => 'int64']]);
echo str_pad('fields and where share a path', 44), $c(['fields' => ['x' => ['type' => 'int64', 'path' => ['a', 'b']]], 'where' => ['w' => ['path' => ['a', 'b'], '=' => 1]]]), "\n";
echo str_pad('two where entries, one path', 44), $c(['where' => ['v' => ['path' => ['a', 'b'], '>' => 1], 'w' => ['path' => ['a', 'b'], '<' => 9]]]), "\n";
echo str_pad('where name and where path', 44), $c(['where' => ['a' => 1, 'w' => ['path' => ['a'], '=' => 2]]]), "\n";
echo str_pad('where path under a field leaf', 44), $c(['fields' => ['a' => 'int64'], 'where' => ['w' => ['path' => ['a', 'b'], '=' => 1]]]), "\n";
echo str_pad('where leaf over a field path', 44), $c(['fields' => ['x' => ['type' => 'int64', 'path' => ['a', 'b']]], 'where' => ['a' => 1]]), "\n";
echo str_pad('where path of 9 segments', 44), $c(['where' => ['w' => ['path' => array_fill(0, 9, 's'), '=' => 1]]]), "\n";
echo str_pad('where path with an int segment', 44), $c(['where' => ['w' => ['path' => ['a', 0], '=' => 1]]]), "\n";
echo str_pad('where path segment not UTF-8', 44), $c(['where' => ['w' => ['path' => ["\xff"], '=' => 1]]]), "\n";
?>
--EXPECT--
-- matching on a nested path
= 1 at a.b                                  [1]
8 segments, string range                    [1]
-- non-object intermediates and non-scalar leaves
a = [1]                                     RecordException path_not_object field="w" at=1/1/0
a = "s"                                     RecordException path_not_object field="w" at=1/1/0
a = 5                                       RecordException path_not_object field="w" at=1/1/0
a = true                                    RecordException path_not_object field="w" at=1/1/0
a = null                                    RecordException path_not_object field="w" at=1/1/0
leaf array under '='                        RecordException where_not_scalar field="w" at=1/1/0
leaf array under 'in'                       RecordException where_not_scalar field="w" at=1/1/0
leaf array under '>'                        RecordException where_not_scalar field="w" at=1/1/0
leaf array under '<'                        RecordException where_not_scalar field="w" at=1/1/0
leaf object                                 RecordException where_not_scalar field="w" at=1/1/0
-- a where path shared with a fields path
field type-checked first                    RecordException type_mismatch field="x" at=1/1/0
missing leaf: default out, where unmatched  [2]
-- the walk follows where paths too; fields come first in getField()
duplicate under a where-only path           RecordException duplicate_key field="w" at=1/1/0
duplicate intermediate on both sections     RecordException duplicate_key field="f" at=1/1/0
field path_not_object before where checks   RecordException path_not_object field="f" at=1/1/0
-- compile rules across sections
fields and where share a path               ok
two where entries, one path                 PlanException duplicate_name option="where" field="w"
where name and where path                   PlanException duplicate_name option="where" field="w"
where path under a field leaf               PlanException invalid_path option="where" field="w"
where leaf over a field path                PlanException invalid_path option="where" field="a"
where path of 9 segments                    PlanException invalid_path option="where" field="w"
where path with an int segment              PlanException invalid_path option="where" field="w"
where path segment not UTF-8                PlanException invalid_name option="where" field=null
