--TEST--
jsonfuse: 10,000 mixed successful and failing runs leave no memory behind
--EXTENSIONS--
jsonfuse
--FILE--
<?php
// Run under ASan (USE_ZEND_ALLOC=0 ZEND_DONT_UNLOAD_MODULES=1 ASAN_OPTIONS=detect_leaks=1)
// for the native-leak check; the memory_get_usage() check below covers the Zend allocator.
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

$small = jf_plan();
$capped = jf_plan(['maxRecordBytes' => 64]);
$skip = jf_plan(['skipEmptyLines' => true]);

$big = '{"id":1,"keep":1,"pad":"' . str_repeat('p', 100000) . '","arr":[' . str_repeat('1,', 20000) . "2]}\n";
$bigBad = '{"id":1,"keep":1,"pad":"' . str_repeat('p', 100000) . '","arr":[' . str_repeat('1,', 20000) . "2,]}\n";
$deep = '{"a":' . str_repeat('[', 511) . '1' . str_repeat(']', 511) . "}\n";
$boom = new JfBoom('leak-test');

$scenarios = [
    0 => fn() => [jf_mem("{\"id\":1}\n{\"id\":2,\"a\":1}\n"), $small],
    1 => fn() => [jf_mem("{\"id\":1,\"a\":\"caf\u{e9}\"}\r\n{\"id\":2,\"b\":[1,2,{\"c\":null}]}"), $small],
    2 => fn() => [jf_mem("{\"id\":1}\n{\"a\":1,}\n"), $small],
    3 => fn() => [jf_mem("{\"a\":\"\xC0\x80\"}\n"), $small],
    4 => fn() => [jf_mem("{\"id\":1}\n" . jf_rec(65) . "\n"), $capped],
    5 => fn() => [jf_mem($deep), $small],
    6 => fn() => [jf_wrap("{\"id\":1}\n{\"id\":2,\"c\":3,\"d\":4}\n", 5, ['mode' => 'false_read', 'after' => 12]), $small],
    7 => fn() => [jf_wrap("{\"id\":1}\n{\"id\":2}\n", 5, ['mode' => 'throw_read', 'after' => 9, 'exc' => $boom]), $small],
    8 => fn() => [jf_mem("{\"id\":1}\n\n{\"id\":2}\n"), $small],
    9 => fn() => [jf_mem($big . $big), $small],
    10 => fn() => [jf_mem("{\"id\":1}\n" . $bigBad), $small],
    11 => fn() => [null, $small],
];

function run_all(array $scenarios, int $n, array &$tally): void
{
    $count = count($scenarios);
    for ($i = 0; $i < $n; $i++) {
        $s = $i % $count;
        [$in, $plan] = $scenarios[$s]();
        $out = jf_out();
        if ($in === null) {      // closed stream argument
            $in = jf_mem('');
            fclose($in);
        }
        try {
            Runner::process($in, $out, $plan);
            $key = 'success';
        } catch (Throwable $e) {
            $key = get_class($e);
        }
        $tally[$key] = ($tally[$key] ?? 0) + 1;
        if (is_resource($in)) {
            fclose($in);
        }
        fclose($out);
    }
}

$t = [];
run_all($scenarios, 600, $t);   // warm-up
unset($e);
gc_collect_cycles();
$t = [];
$base = memory_get_usage();
run_all($scenarios, 10000, $t);
gc_collect_cycles();
$delta = memory_get_usage() - $base;

ksort($t);
foreach ($t as $k => $v) {
    echo $k, ': ', $v, "\n";
}
echo 'total: ', array_sum($t), "\n";
// 10,000 runs: an 8-byte leak per run would already be 80 KB.
var_dump($delta <= 65536);
if ($delta > 65536) {
    echo "memory grew by $delta bytes\n";
}
?>
--EXPECT--
JfBoom: 833
JsonFuse\RecordException: 5000
JsonFuse\StreamException: 833
TypeError: 833
success: 2501
total: 10000
bool(true)
