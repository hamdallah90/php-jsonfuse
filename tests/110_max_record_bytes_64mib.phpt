--TEST--
jsonfuse 1.0: at maxRecordBytes 67108864 a record of exactly 64 MiB is accepted and one byte more is record_too_large (peaks near 0.9 GB)
--EXTENSIONS--
jsonfuse
--INI--
memory_limit=-1
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const CAP = 67108864;
$plan = Plan::compile(['fields' => ['id' => 'int64'], 'maxRecordBytes' => CAP]);

foreach ([CAP, CAP + 1] as $n) {
    // {"id":1,"z":"...."} of exactly $n bytes, generated lazily.
    $in = jf_gen([['{"id":1,"z":"', 1], ['a', $n - 15], ['"}', 1], ["\n", 1]]);
    try {
        echo "$n bytes: " . jf_stats(Runner::process($in, jf_out(), $plan)) . "\n";
    } catch (Throwable $e) {
        echo "$n bytes: ", jf10_exc($e), "\n";
    }
    fclose($in);
}
?>
--EXPECT--
67108864 bytes: read=1 matched=1 emitted=1 bytesRead=67108865 bytesWritten=9
67108865 bytes: RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
