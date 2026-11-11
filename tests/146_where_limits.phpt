--TEST--
jsonfuse 1.0: a plan at the filter storage bound (16 entries of 8-segment paths, 256 in members and five more operands of 1024 bytes each) compiles and matches; a 17th entry is too_many
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

$v = str_repeat('m', 1024);
$where = [];
$tree = ['i' => 1];
for ($e = 0; $e < 16; $e++) {
    $path = [];
    for ($d = 0; $d < 8; $d++) {
        $path[] = "e{$e}d$d";
    }
    $members = [$v];
    for ($k = 1; $k < 256; $k++) {
        $members[] = str_pad("$e/$k", 1024, 'z');
    }
    $where["w$e"] = ['path' => $path, 'in' => $members, '=' => $v, '>=' => $v, '<=' => $v,
        '>' => str_repeat('l', 1024), '<' => str_repeat('n', 1024)];
    $node = &$tree;
    foreach ($path as $seg) {
        $node[$seg] ??= [];
        $node = &$node[$seg];
    }
    $node = $e === 15 ? '' : $v;
    unset($node);
}
$plan = Plan::compile(['fields' => ['i' => 'int64'], 'where' => $where]);
$last = json_encode($tree, JSON_THROW_ON_ERROR);
$tree2 = json_decode($last, true);
$tree2['i'] = 2;
array_walk_recursive($tree2, function (&$x) use ($v) {
    if ($x === '') {
        $x = $v;
    }
});
$in = jf_mem($last . "\n" . json_encode($tree2, JSON_THROW_ON_ERROR) . "\n");
$out = jf_out();
$s = Runner::process($in, $out, $plan);
echo 'stats: read=', $s->recordsRead, ' matched=', $s->recordsMatched, "\n";
echo 'output: ', jf_out_bytes($out);

echo "-- 17 entries\n";
$where['w16'] = 1;
echo jf10_compile(['fields' => ['i' => 'int64'], 'where' => $where]), "\n";
?>
--EXPECT--
stats: read=2 matched=1
output: {"i":2}
-- 17 entries
PlanException too_many option="where" field=null
