--TEST--
jsonfuse: writer growth goes through the memory preflight, so a record the pool and reader fit but the writer cannot raises RecordException, never a fatal
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
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\RecordException;
use JsonFuse\Runner;

const STEP = 262144;

/*
 * One 800,000-byte record, one `s` field. SPEC.md section 6: the pool needs 12 * L + 256 (9,600,256, charged as
 * 10 MiB because it is at least a chunk), the reader about 1 MiB and the writer L + prefix + 40 + 2. A sweep of
 * limits around that sum crosses the point where the pool and the reader fit and the writer does not: every run
 * must end in success or a RecordException, never in PHP's memory fatal, so the writer is preflighted too.
 */
$plan = Plan::compile(['fields' => ['s' => 'string']]);
$input = [['{"s":"', 1], ['a', 800000 - 8], ["\"}\n", 1]];

function run(array $input, Plan $plan): string
{
    $in = jf_gen($input);
    $out = jf_sink(['keep' => false]);
    try {
        Runner::process($in, $out, $plan);
        return 'success';
    } catch (RecordException $e) {
        return $e->getReason() === 'memory_limit' ? 'refused' : 'other: ' . $e->getReason();
    } finally {
        fclose($in);
        fclose($out);
    }
}

$results = [];
for ($k = 36; $k <= 64; $k++) {
    gc_collect_cycles();
    gc_mem_caches();
    ini_set('memory_limit', (string)(memory_get_usage(true) + $k * STEP));
    $results[$k] = run($input, $plan);
    ini_set('memory_limit', '128M');
}
echo 'limits swept: ', count($results), "\n";
echo 'every outcome success or a RecordException naming memory_limit: ', var_export(array_diff(array_unique($results), ['success', 'refused']) === [], true), "\n";
echo 'the smallest limit was refused: ', var_export($results[36] === 'refused', true), "\n";
echo 'the largest limit succeeded: ', var_export($results[64] === 'success', true), "\n";
echo "no fatal\n";
?>
--EXPECT--
limits swept: 29
every outcome success or a RecordException naming memory_limit: true
the smallest limit was refused: true
the largest limit succeeded: true
no fatal
