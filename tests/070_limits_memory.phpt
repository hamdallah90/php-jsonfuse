--TEST--
jsonfuse 1.0: memory stays within the documented bound on overlong lines; a low memory_limit raises RecordException, not a fatal (the 8 MiB record names its cap)
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
// Companion of 070_limits: the part of its checks
// that depends on memory_limit / memory_get_peak_usage(), split out so the
// rest of 070_limits still runs under USE_ZEND_ALLOC=0.
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

const M = 'SECRETMARK';

// The records below hold a string `k`; the plan declares it.
function stream_run($in, array $over = []): string
{
    // The writer writes the record; a spilling sink keeps the output out of memory_limit's accounting.
    $out = fopen('php://temp/maxmemory:65536', 'w+b');
    try {
        return jf_stats(Runner::process($in, $out, jf_plan($over + ['fields' => ['k' => 'string']])));
    } catch (Throwable $e) {
        return get_class($e) . ' ' . jf_ctx($e->getMessage()) . (str_contains($e->getMessage(), M) ? ' LEAKS' : '');
    } finally {
        fclose($in);
        fclose($out);
    }
}

echo "-- peak memory while rejecting a lazy line 100x the cap\n";
$cap = 1048576;
// SPEC.md section 6: only the reader grows while an overlong line is rejected, to at most
// maxRecordBytes + 2 + 64 KiB + YYJSON_PADDING_SIZE. Another 64 KiB covers this test's own stream.
$bound = $cap + 2 + 65536 + 4 + 65536;
gc_collect_cycles();
$before = memory_get_usage();
memory_reset_peak_usage();
$r = stream_run(jf_gen([['{"a":"' . M, 1], ['x', 100 * $cap]]), ['maxRecordBytes' => $cap]);
$peak = memory_get_peak_usage() - $before;
echo $r, "\n";
echo 'peak growth within documented bound: ', var_export($peak <= $bound, true), "\n";

echo "-- an 8 MiB record under a low memory_limit\n";
$cap = 8 * 1024 * 1024;
$k = intdiv($cap - 18, 2);
$segs = [['{"k":"","a":[', 1], ['1,', $k], ['1', 1], [']', 1], [' ', 2], ["}\n", 1]];

$baseline = memory_get_usage();
foreach (['24M', '40M', '64M'] as $limit) {
    ini_set('memory_limit', $limit);
    echo str_pad("memory_limit=$limit", 32), stream_run(jf_gen($segs), ['maxRecordBytes' => $cap]), "\n";
    ini_set('memory_limit', '128M');
}
gc_collect_cycles();
echo 'no memory kept after the failures: ', var_export(memory_get_usage() - $baseline <= 65536, true), "\n";

ini_set('memory_limit', '24M');
echo str_pad('small record under 24M', 32), jf_run_str("{\"id\":1,\"a\":[1,1,1]}\n"), "\n";
echo str_pad('many small records under 24M', 32), jf_run_str(str_repeat("{\"id\":1,\"a\":[1,2,3]}\n", 20000)), "\n";
ini_set('memory_limit', '128M');

echo str_pad('same record under 128M', 32), stream_run(jf_gen($segs), ['maxRecordBytes' => $cap]), "\n";
ini_set('memory_limit', '-1');
echo str_pad('same record, no limit', 32), stream_run(jf_gen($segs), ['maxRecordBytes' => $cap]), "\n";
ini_set('memory_limit', '128M');

echo "-- a smaller cap needs less memory, so it fits a low limit\n";
ini_set('memory_limit', '32M');
echo str_pad('cap 1 MiB, record 1 MiB, 32M', 32), stream_run(jf_gen([['{"k":"', 1], ['a', 1048576 - 8], ["\"}\n", 1]]), ['maxRecordBytes' => 1048576]), "\n";
ini_set('memory_limit', '128M');
echo "done\n";
?>
--EXPECT--
-- peak memory while rejecting a lazy line 100x the cap
JsonFuse\RecordException line=1 record=1 offset=0
peak growth within documented bound: true
-- an 8 MiB record under a low memory_limit
memory_limit=24M                JsonFuse\RecordException line=1 record=1 offset=0
memory_limit=40M                JsonFuse\RecordException line=1 record=1 offset=0
memory_limit=64M                JsonFuse\RecordException line=1 record=1 offset=0
no memory kept after the failures: true
small record under 24M          read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=9
many small records under 24M    read=20000 matched=20000 emitted=20000 bytesRead=420000 bytesWritten=180000
same record under 128M          read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=9
same record, no limit           read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=9
-- a smaller cap needs less memory, so it fits a low limit
cap 1 MiB, record 1 MiB, 32M    read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577
done
