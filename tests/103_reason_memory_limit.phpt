--TEST--
jsonfuse 1.0: growth refused by the memory_limit preflight raises RecordException memory_limit, after the buffered good record is written
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
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile(['fields' => ['k' => 'string'], 'maxRecordBytes' => 16 * 1024 * 1024]);
$in = jf_gen([["{\"k\":\"x\"}\n", 1], ['{"k":"', 1], ['a', 8 * 1024 * 1024 - 8], ["\"}\n", 1]]);
$out = jf_out();
try {
    echo 'no exception: ' . jf_stats(Runner::process($in, $out, $plan)) . "\n";
} catch (Throwable $e) {
    echo jf10_exc($e), "\n";
}
echo 'out=', json_encode(jf_out_bytes($out)), "\n";
fclose($in);
fclose($out);
?>
--EXPECT--
RecordException memory_limit field=null at=2/2/10 [read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=10]
out="{\"k\":\"x\"}\n"
