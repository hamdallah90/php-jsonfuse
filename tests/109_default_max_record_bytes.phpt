--TEST--
jsonfuse 1.0: the default maxRecordBytes is 1 MiB, the range stays 1 to 64 MiB, and the cap counts JSON text without its LF or CRLF
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const CAP = 1048576;

/** A lazily generated record of exactly $n JSON bytes whose projection of k is the same $n bytes. */
function rec(int $n, string $term)
{
    return jf_gen([['{"k":"', 1], ['a', $n - 8], ['"}', 1], [$term, 1]]);
}

function srun($in, array $over = []): string
{
    $out = jf_sink(['keep' => false]);
    try {
        return jf_stats(Runner::process($in, $out, Plan::compile($over + ['fields' => ['k' => 'string']])));
    } catch (Throwable $e) {
        return jf10_exc($e);
    }
}

echo "-- default cap\n";
foreach (['LF' => "\n", 'CRLF' => "\r\n", 'no terminator' => ''] as $label => $term) {
    echo str_pad("1048576 bytes + $label", 34), srun(rec(CAP, $term)), "\n";
    echo str_pad("1048577 bytes + $label", 34), srun(rec(CAP + 1, $term)), "\n";
}
echo "-- explicit caps\n";
echo str_pad('cap 1048576: 1048576 bytes', 34), srun(rec(CAP, "\n"), ['maxRecordBytes' => CAP]), "\n";
echo str_pad('cap 1048576: 1048577 bytes', 34), srun(rec(CAP + 1, "\n"), ['maxRecordBytes' => CAP]), "\n";
echo str_pad('cap 8388608: 1048577 bytes', 34), srun(rec(CAP + 1, "\n"), ['maxRecordBytes' => 8388608]), "\n";

echo "-- cap 1\n";
foreach (["1\n", "1\r\n", '1', "12\n", "12"] as $data) {
    echo str_pad('cap 1: ' . json_encode($data), 34), srun(jf_mem($data), ['maxRecordBytes' => 1]), "\n";
}

echo "-- range\n";
foreach ([1, 67108864, 0, -1, 67108865, PHP_INT_MAX, PHP_INT_MIN] as $v) {
    echo str_pad("maxRecordBytes $v", 36), jf10_compile(['fields' => ['k' => 'string'], 'maxRecordBytes' => $v]), "\n";
}
?>
--EXPECT--
-- default cap
1048576 bytes + LF                read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577
1048577 bytes + LF                RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
1048576 bytes + CRLF              read=1 matched=1 emitted=1 bytesRead=1048578 bytesWritten=1048577
1048577 bytes + CRLF              RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
1048576 bytes + no terminator     read=1 matched=1 emitted=1 bytesRead=1048576 bytesWritten=1048577
1048577 bytes + no terminator     RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- explicit caps
cap 1048576: 1048576 bytes        read=1 matched=1 emitted=1 bytesRead=1048577 bytesWritten=1048577
cap 1048576: 1048577 bytes        RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
cap 8388608: 1048577 bytes        read=1 matched=1 emitted=1 bytesRead=1048578 bytesWritten=1048578
-- cap 1
cap 1: "1\n"                      RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
cap 1: "1\r\n"                    RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
cap 1: "1"                        RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
cap 1: "12\n"                     RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
cap 1: "12"                       RecordException record_too_large field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- range
maxRecordBytes 1                    ok
maxRecordBytes 67108864             ok
maxRecordBytes 0                    PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes -1                   PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes 67108865             PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes 9223372036854775807  PlanException invalid_option option="maxRecordBytes" field=null
maxRecordBytes -9223372036854775808 PlanException invalid_option option="maxRecordBytes" field=null
