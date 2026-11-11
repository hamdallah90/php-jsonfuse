--TEST--
jsonfuse 1.0: 128 fields of 8 distinct segments each (1024 path nodes) and 128 fields under one 7-segment prefix compile and project; compiling and freeing 8-deep plans returns memory to baseline
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/** The record that holds $value at every path, built as nested objects. */
function build(array $paths): string
{
    $tree = [];
    foreach ($paths as [$path, $value]) {
        $node = &$tree;
        foreach ($path as $seg) {
            $node[$seg] ??= [];
            $node = &$node[$seg];
        }
        $node = $value;
        unset($node);
    }
    return json_encode($tree, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
}

function check(string $label, array $fields, array $paths, string $want): void
{
    $out = jf_out();
    $s = Runner::process(jf_mem(build($paths) . "\n"), $out, Plan::compile(['fields' => $fields]));
    echo $label, ': ', jf_stats($s), ' output as expected: ', var_export(jf_out_bytes($out) === $want, true), "\n";
}

$fields = $paths = $want = [];
for ($i = 0; $i < 128; $i++) {
    $path = [];
    for ($d = 0; $d < 8; $d++) {
        $path[] = "s{$d}_$i";
    }
    $fields["f$i"] = ['type' => 'int64', 'path' => $path];
    $paths[] = [$path, $i];
    $want[] = "\"f$i\":$i";
}
check('distinct 8-segment paths', $fields, $paths, '{' . implode(',', $want) . "}\n");

$fields = $paths = $want = [];
for ($i = 0; $i < 128; $i++) {
    $path = ['a', 'b', 'c', 'd', 'e', 'f', 'g', "leaf$i"];
    $fields["f$i"] = ['type' => 'int64', 'path' => $path];
    $paths[] = [$path, -$i];
    $want[] = "\"f$i\":" . -$i;
}
check('one 7-segment prefix', $fields, $paths, '{' . implode(',', $want) . "}\n");

echo "-- lifecycle\n";
$spec = ['fields' => []];
for ($i = 0; $i < 128; $i++) {
    $spec['fields']["f$i"] = ['type' => 'string', 'path' => ["a$i", 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 'default' => 'x'];
}
Plan::compile($spec);
$before = memory_get_usage();
for ($n = 0; $n < 300; $n++) {
    $p = Plan::compile($spec);
    unset($p);
}
$after = memory_get_usage();
echo 'memory growth after 300 compiled and freed plans: ', $after - $before <= 4096 ? 'none' : ($after - $before) . ' bytes', "\n";
?>
--EXPECT--
distinct 8-segment paths: read=1 matched=1 emitted=1 bytesRead=10532 bytesWritten=1190 output as expected: true
one 7-segment prefix: read=1 matched=1 emitted=1 bytesRead=1743 bytesWritten=1317 output as expected: true
-- lifecycle
memory growth after 300 compiled and freed plans: none
