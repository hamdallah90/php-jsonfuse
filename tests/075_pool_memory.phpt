--TEST--
jsonfuse 1.0: memory preflight charges a chunk only for large requests, frees the old pool first, and the pool doubles (records over 1 MiB name their cap)
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
// memory_limit is not enforced when the Zend allocator is disabled (ASan runs).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit is not enforced with USE_ZEND_ALLOC=0');
?>
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

const MIB = 1048576;

echo "-- a request a chunk can satisfy is not charged a whole chunk\n";
foreach (['2M', '3M'] as $limit) {
    var_dump(ini_set('memory_limit', $limit) !== false);
    echo str_pad("trivial input under $limit", 34), jf_run_str("{\"id\":1}\n"), "\n";
    ini_set('memory_limit', '128M');
}

function run(string $data): string
{
    $in = jf_mem($data);
    // The writer writes the records; a spilling sink keeps the output out of memory_limit's accounting.
    $out = fopen('php://temp/maxmemory:65536', 'w+b');
    try {
        return jf_stats(Runner::process($in, $out, jf_plan(['fields' => ['k' => 'string'], 'maxRecordBytes' => 8388608])));
    } catch (Throwable $e) {
        return get_class($e) . ' ' . jf_ctx($e->getMessage());
    } finally {
        fclose($in);
        fclose($out);
    }
}

// The records hold a string `k`; the plan in run() and below declares it.
$rec = fn(int $n): string => '{"k":"' . str_repeat('a', $n - 8) . '"}';

echo "-- the pool grows by doubling, not more\n";
$size = 200000;
$in = jf_mem($rec($size) . "\n" . $rec($size + 1000) . "\n");
$out = fopen('php://temp/maxmemory:65536', 'w+b');
gc_collect_cycles();
$before = memory_get_usage();
memory_reset_peak_usage();
$stats = Runner::process($in, $out, jf_plan(['fields' => ['k' => 'string'], 'maxRecordBytes' => 8388608]));
$peak = memory_get_peak_usage() - $before;
echo jf_stats($stats), "\n";
$pool = 2 * (12 * $size + 256);           // max(need, min(2 * capacity, bound))
$reader = 2 * ($size + 1000 + 2 + 65536 + 4);
$writer = 2 * ($size + 1000 + 1024);      // the writer reserves one whole record, at most doubling
var_dump($peak <= $pool + $reader + $writer + 65536);
fclose($in);
fclose($out);

echo "-- the old pool is freed before the preflight for the new one\n";
$data = $rec(1000000) . "\n" . $rec(1500000) . "\n";
$base = memory_get_usage(true);
// Fits only without the first pool: reader, second pool and writer need about 27 MiB plus up to
// 6 MiB for the writer, with the first pool about 39 MiB.
ini_set('memory_limit', (string)($base + 34 * MIB));
echo run($data), "\n";
ini_set('memory_limit', '128M');
unset($data);

echo "-- a request at or above a chunk is charged in whole chunks\n";
$data = $rec(350000) . "\n";              // pool request 4.0 MiB, charged as 6 MiB
run($data);
gc_collect_cycles();
$base = memory_get_usage(true);
ini_set('memory_limit', (string)($base + 5 * MIB));
echo run($data), "\n";
ini_set('memory_limit', '128M');
?>
--EXPECT--
-- a request a chunk can satisfy is not charged a whole chunk
bool(true)
trivial input under 2M            read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
bool(true)
trivial input under 3M            read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
-- the pool grows by doubling, not more
read=2 matched=2 emitted=2 bytesRead=401002 bytesWritten=401002
bool(true)
-- the old pool is freed before the preflight for the new one
read=2 matched=2 emitted=2 bytesRead=2500002 bytesWritten=2500002
-- a request at or above a chunk is charged in whole chunks
JsonFuse\RecordException line=1 record=1 offset=0
