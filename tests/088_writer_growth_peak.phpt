--TEST--
jsonfuse: growing the writer frees the old buffer before allocating the new one, so growth adds nothing to the peak
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
// memory_get_peak_usage() does not see allocations when the Zend allocator is disabled (ASan runs).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_get_peak_usage is not meaningful with USE_ZEND_ALLOC=0');
?>
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const CAP = 1048576;

$plan = Plan::compile([
    'fields' => ['s' => 'string'],
    'where' => ['k' => 'y'],
    'maxRecordBytes' => CAP,
]);

function record(string $kind, int $length): string
{
    $head = '{"k":"' . $kind . '","s":"';
    return $head . str_repeat('a', $length - strlen($head) - 2) . "\"}\n";
}

/*
 * The first record is cap-sized and filtered out: it brings the reader and the parser pool to their
 * final size before the writer allocates anything, so only the writer differs between the two runs.
 * Both runs end with a cap-sized matching record, and so with the same final writer buffer. The
 * growing run first writes a half-cap record, which leaves a half-cap buffer to replace.
 */
function peak(array $records, Plan $plan): array
{
    $in = jf_mem(implode('', $records));
    $out = fopen('/dev/null', 'wb');     // holds no bytes and runs no PHP during the write
    gc_collect_cycles();
    gc_mem_caches();
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $stats = Runner::process($in, $out, $plan);
    $peak = memory_get_peak_usage() - $before;
    fclose($in);
    fclose($out);
    return [$peak, $stats];
}

$half = (int)(CAP / 2);
[$direct, $a] = peak([record('n', CAP), record('y', CAP)], $plan);
[$growing, $b] = peak([record('n', CAP), record('y', $half), record('y', CAP)], $plan);

echo 'direct: ', jf_stats($a), "\n";
echo 'growing: ', jf_stats($b), "\n";
// Holding both buffers would add the whole half-cap buffer; a quarter of it separates that from page rounding.
echo 'growth adds less than a quarter of the old buffer to the peak: ', var_export($growing - $direct < $half / 4, true), "\n";
?>
--EXPECT--
direct: read=2 matched=1 emitted=1 bytesRead=2097154 bytesWritten=1048569
growing: read=3 matched=2 emitted=2 bytesRead=2621443 bytesWritten=1572850
growth adds less than a quarter of the old buffer to the peak: true
