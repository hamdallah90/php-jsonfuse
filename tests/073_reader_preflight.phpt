--TEST--
jsonfuse 1.0: reader growth over memory_limit raises RecordException instead of a fatal (the 8 MiB record names its cap, so the preflight and not the cap rejects it)
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
// memory_limit is not enforced when the Zend allocator is disabled (ASan runs).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit is not enforced with USE_ZEND_ALLOC=0');
?>
--INI--
memory_limit=8M
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

// The record holds a string `k`, so the plan declares it
$plan = jf_plan(['fields' => ['k' => 'string'], 'maxRecordBytes' => 8388608]);
$in = jf_gen([['{"k":"', 1], ['a', 8 * 1024 * 1024 - 8], ["\"}\n", 1]]);
$out = jf_out();
try {
    Runner::process($in, $out, $plan);
    echo "no exception\n";
} catch (Throwable $e) {
    echo get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
}
fclose($in);
fclose($out);

// Lines that fit the limit still run after the failure.
echo jf_run_str("{\"id\":1,\"a\":1}\n{\"id\":2,\"b\":[2,3]}\n"), "\n";
?>
--EXPECT--
JsonFuse\RecordException line=1 record=1 offset=0
read=2 matched=2 emitted=2 bytesRead=34 bytesWritten=18
