--TEST--
jsonfuse 1.0: the walk enters objects only below path nodes that a path continues from, so its depth is bounded by the plan, never by the record: 500 levels below an 8-segment leaf, beside a path and under unreferenced keys (run it under ASan)
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

function deep(int $n): string
{
    return str_repeat('{"a":', $n) . '1' . str_repeat('}', $n);
}

/** Members "k":... of $path down to $leaf, as JSON text without the outer braces. */
function nest(array $path, string $leaf): string
{
    $k = array_shift($path);
    return json_encode($k) . ':' . ($path === [] ? $leaf : '{' . nest($path, $leaf) . '}');
}

$eight = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
$spec = ['fields' => ['id' => 'int64', 'v' => ['type' => 'int64?', 'path' => $eight, 'default' => null]]];
foreach ([
    'leaf holds 500 levels' => '{"id":1,' . nest($eight, deep(500)) . '}',
    'leaf holds an object of path keys' => '{"id":2,' . nest($eight, '{' . nest($eight, '1') . '}') . '}',
    'sibling of the leaf holds 500 levels' => '{"id":3,' . nest(['a', 'b', 'c', 'd', 'e', 'f', 'g'], '{"h":7,"i":' . deep(500) . '}') . '}',
    'unreferenced key holds 500 levels' => '{"id":4,"z":' . deep(500) . ',' . nest($eight, '8') . '}',
    'path keys repeated deep below the leaf' => '{"id":5,' . nest($eight, '{"x":' . deep(300) . ',"x":2}') . '}',
] as $label => $rec) {
    [$o, $r] = jf10_run($rec . "\n", $spec);
    echo str_pad($label, 40), str_starts_with($r, 'read=') ? rtrim($o, "\n") : preg_replace('/ \[.*\]$/', '', $r), "\n";
}
?>
--EXPECT--
leaf holds 500 levels                   RecordException type_mismatch field="v" at=1/1/0
leaf holds an object of path keys       RecordException type_mismatch field="v" at=1/1/0
sibling of the leaf holds 500 levels    {"id":3,"v":7}
unreferenced key holds 500 levels       {"id":4,"v":8}
path keys repeated deep below the leaf  RecordException type_mismatch field="v" at=1/1/0
