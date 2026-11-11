--TEST--
jsonfuse: 100,000 Plan compile/free cycles, including failed compiles and 8-deep path plans, return memory to baseline
--EXTENSIONS--
jsonfuse
--FILE--
<?php
use JsonFuse\Plan;
use JsonFuse\PlanException;

$many = array_fill_keys(array_map(fn($i) => "field_$i", range(1, 128)), 'string?');
$wheres = array_fill_keys(array_map(fn($i) => "w$i", range(1, 16)), str_repeat('v', 200));
// 1.0 paths: 128 fields of 8 segments each (1024 path nodes, with defaults), and plans that fail late in the path rules.
$deep = [];
foreach (range(0, 127) as $i) {
    $deep["d$i"] = ['type' => 'string', 'path' => ["p$i", 'b', 'c', 'd', 'e', 'f', 'g', str_repeat('h', 100)], 'default' => 'x'];
}
$deepPrefix = $deep;
$deepPrefix['d127']['path'] = ['p0', 'b', 'c'];
$deepSegment = $deep;
$deepSegment['d100']['path'][7] = "\xff";

// Each variant is compiled; the failing ones fail after earlier parts were
// already built (fields ok, where bad; some fields ok, later field bad ...).
$variants = [
    'ok-small' => [['fields' => ['id' => 'int64']], true],
    'ok-full' => [['fields' => ['id' => 'int64', 'n' => 'string?', 'f' => 'float64', 'b' => 'bool?'],
        'where' => ['kind' => 'x', 'n' => 5, 'z' => null, 'f' => 1.5, 't' => true],
        'output' => 'ndjson', 'maxRecordBytes' => 1024, 'skipEmptyLines' => true], true],
    'ok-wide' => [['fields' => $many, 'where' => $wheres], true],
    'bad-late-field' => [['fields' => ['a' => 'int64', 'b' => 'string', 'c' => 'bogus']], false],
    'bad-where-after-fields' => [['fields' => $many, 'where' => ['a' => 1, 'b' => [1]]], false],
    'bad-where-nan' => [['fields' => ['a' => 'int64'], 'where' => ['a' => 'x', 'b' => NAN]], false],
    'bad-option-after-all' => [['fields' => $many, 'where' => $wheres, 'output' => 'json'], false],
    'bad-unknown-key' => [['fields' => $many, 'where' => $wheres, 'nope' => 1], false],
    'bad-maxRecordBytes' => [['fields' => $many, 'where' => $wheres, 'maxRecordBytes' => 0], false],
    'ok-deep' => [['fields' => $deep, 'where' => $wheres], true],
    'bad-deep-prefix' => [['fields' => $deep, 'where' => $wheres], false],
    'bad-deep-segment' => [['fields' => $deepSegment], false],
];
$variants['bad-deep-prefix'][0]['fields'] = $deepPrefix;

function cycle(array $variants, int $n, array &$tally): void
{
    $names = array_keys($variants);
    for ($i = 0; $i < $n; $i++) {
        $name = $names[$i % count($names)];
        [$spec, $shouldWork] = $variants[$name];
        try {
            $p = Plan::compile($spec);
            $tally[$shouldWork ? 'compiled' : 'unexpected_success']++;
            unset($p);
        } catch (PlanException $e) {
            $tally[$shouldWork ? 'unexpected_failure' : 'failed']++;
        }
    }
}

$tally = ['compiled' => 0, 'failed' => 0, 'unexpected_success' => 0, 'unexpected_failure' => 0];
cycle($variants, 2700, $tally);          // warm-up (allocator pools, caches)
gc_collect_cycles();
$tally = ['compiled' => 0, 'failed' => 0, 'unexpected_success' => 0, 'unexpected_failure' => 0];
$base = memory_get_usage();

cycle($variants, 100000, $tally);

gc_collect_cycles();
$delta = memory_get_usage() - $base;
foreach ($tally as $k => $v) {
    echo $k, ': ', $v, "\n";
}
// 100,000 cycles: even an 8-byte leak per cycle is 800 KB, far above this slack.
var_dump($delta <= 65536);
if ($delta > 65536) {
    echo "memory grew by $delta bytes\n";
}
?>
--EXPECT--
compiled: 33335
failed: 66665
unexpected_success: 0
unexpected_failure: 0
bool(true)
