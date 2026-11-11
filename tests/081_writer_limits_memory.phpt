--TEST--
jsonfuse 1.0: the writer's memory stays bounded across repeated large records and runs, and large records fit the documented limits (8 MiB records name their cap)
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
// memory_limit and memory_get_usage() are not meaningful when the Zend allocator is disabled (ASan runs).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit is not enforced with USE_ZEND_ALLOC=0');
?>
--INI--
memory_limit=128M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const MIB = 1048576;

/**
 * Companion of 081_writer_limits: the parts that read memory_get_usage()/memory_limit, split out so the
 * rest still runs under USE_ZEND_ALLOC=0. Records are 512 KiB inside a 1 MiB cap.
 */
$cap = MIB;
$recLen = 512 * 1024;
$plan = Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => $cap]);
$rec = [['{"s":"', 1], ['a', $recLen - 8], ["\"}\n", 1]];

function pieces(array $rec, int $n): array
{
    $p = [];
    for ($i = 0; $i < $n; $i++) {
        foreach ($rec as $x) {
            $p[] = $x;
        }
    }
    return $p;
}

echo "-- 40 records of 512 KiB: memory is flat from the fifth write on\n";
$samples = [];
$in = jf_gen(pieces($rec, 40));
$out = jf_sink(['keep' => false, 'on_write' => function () use (&$samples) {
    $samples[] = memory_get_usage();
}]);
gc_collect_cycles();
$before = memory_get_usage();
memory_reset_peak_usage();
$s = Runner::process($in, $out, $plan);
$peak = memory_get_peak_usage() - $before;
echo jf_stats($s), "\n";
$k = JfSink::$last;
echo 'output digest: ', $k->sha256() === hash('sha256', str_repeat('{"s":"' . str_repeat('a', $recLen - 8) . "\"}\n", 40)) ? 'ok' : 'FAIL', "\n";
echo 'at least 10 writes: ', var_export(count($samples) >= 10, true), "\n";
$tail = array_slice($samples, 4);
echo 'spread of memory_get_usage() over the later writes at most 1.5 MiB: ', var_export($tail !== [] && max($tail) - min($tail) <= 1.5 * MIB, true), "\n";
// SPEC.md section 6: parser 12 * cap + 256, reader cap + 2 + 64 KiB + 4, writer cap + 40 * fields + prefix + 2; PHP's own side 4 MiB
$bound = 12 * $cap + 256 + $cap + 2 + 65536 + 4 + $cap + 40 + 64 + 2 + 4 * MIB;
echo 'peak growth within the documented bound: ', var_export($peak <= $bound, true), "\n";
fclose($in);
fclose($out);

echo "-- repeated runs return all memory\n";
$usage = [];
for ($i = 0; $i < 15; $i++) {
    $in = jf_gen(pieces($rec, 3));
    $out = jf_sink(['keep' => false]);
    Runner::process($in, $out, $plan);
    fclose($in);
    fclose($out);
    JfSink::$last = null;
    JfWrap::$last = null;
    gc_collect_cycles();
    $usage[] = memory_get_usage();
}
echo 'usage after run 15 within 64 KiB of run 3: ', var_export($usage[14] - $usage[2] <= 65536, true), "\n";

echo "-- failing runs return the writer's memory too\n";
$usage = [];
for ($i = 0; $i < 15; $i++) {
    $in = jf_gen(array_merge(pieces($rec, 2), [['{"s":1}', 1], ["\n", 1]]));
    $out = jf_sink(['keep' => false]);
    try {
        Runner::process($in, $out, $plan);
    } catch (JsonFuse\RecordException $e) {
    }
    unset($e);
    fclose($in);
    fclose($out);
    JfSink::$last = null;
    JfWrap::$last = null;
    gc_collect_cycles();
    $usage[] = memory_get_usage();
}
echo 'usage after run 15 within 64 KiB of run 3: ', var_export($usage[14] - $usage[2] <= 65536, true), "\n";

echo "-- a record of exactly 1 MiB made of escapes fits memory_limit 32M\n";
ini_set('memory_limit', '32M');
$n = intdiv($cap - 8, 6);
$r = ($cap - 8) - 6 * $n;
$in = jf_gen([['{"s":"', 1], ['b', $r], ['\u0001', $n], ["\"}\n", 1]]);
$out = jf_sink(['keep' => false]);
try {
    $s = Runner::process($in, $out, $plan);
    $want = hash('sha256', '{"s":"' . str_repeat('b', $r) . str_repeat('\u0001', $n) . "\"}\n");
    echo jf_stats($s), ' digest ', JfSink::$last->sha256() === $want ? 'ok' : 'FAIL', "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
ini_set('memory_limit', '128M');
fclose($in);
fclose($out);

echo "-- an explicit 8 MiB cap with escapes fits the default 128M\n";
$n = intdiv(8 * MIB - 8, 6);
$r = (8 * MIB - 8) - 6 * $n;
$in = jf_gen([['{"s":"', 1], ['b', $r], ['\u0001', $n], ["\"}\n", 1]]);
$out = jf_sink(['keep' => false]);
try {
    $s = Runner::process($in, $out, Plan::compile(['fields' => ['s' => 'string'], 'maxRecordBytes' => 8388608]));
    $h = hash_init('sha256');
    hash_update($h, '{"s":"' . str_repeat('b', $r));
    for ($i = 0; $i < $n; $i += 100000) {
        hash_update($h, str_repeat('\u0001', min(100000, $n - $i)));
    }
    hash_update($h, "\"}\n");
    echo jf_stats($s), ' digest ', JfSink::$last->sha256() === hash_final($h) ? 'ok' : 'FAIL', "\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
fclose($in);
fclose($out);
echo "done\n";
?>
--EXPECT--
-- 40 records of 512 KiB: memory is flat from the fifth write on
read=40 matched=40 emitted=40 bytesRead=20971560 bytesWritten=20971560
output digest: ok
at least 10 writes: true
spread of memory_get_usage() over the later writes at most 1.5 MiB: true
peak growth within the documented bound: true
-- repeated runs return all memory
usage after run 15 within 64 KiB of run 3: true
-- failing runs return the writer's memory too
usage after run 15 within 64 KiB of run 3: true
-- a record of exactly 1 MiB made of escapes fits memory_limit 32M
read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577 digest ok
-- an explicit 8 MiB cap with escapes fits the default 128M
read=1 matched=1 emitted=1 bytesRead=8388609 bytesWritten=8388609 digest ok
done
