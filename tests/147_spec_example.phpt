--TEST--
jsonfuse 1.0: the complete example of SPEC.md, byte for byte: output, exception class, reason, getters and progress
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\RecordException;
use JsonFuse\Runner;

$plan = Plan::compile([
    'fields' => [
        'id'       => 'int64',
        'city'     => ['path' => ['address', 'city'], 'type' => 'string?', 'default' => null],
        'tier'     => ['type' => 'string', 'default' => 'free'],
        'amount'   => 'float64',
        'discount' => ['type' => 'float64', 'default' => 0],
    ],
    'where' => [
        'event'   => 'purchase',
        'amount'  => ['>=' => 10, '<' => 1000.0],
        'day'     => ['>=' => '2026-11-01', '<' => '2026-12-01'],
        'country' => ['path' => ['address', 'country'], 'in' => ['DE', 'FR', 'NL']],
    ],
]);
$input = '{"id":7,"event":"purchase","day":"2026-11-09","amount":25,"address":{"city":"Berlin","country":"DE"}}' . "\n"
    . '{"id":8,"event":"purchase","day":"2026-11-09","amount":5.5,"address":{"country":"US"}}' . "\n"
    . '{"id":9,"event":"refund","day":"2026-11-10","amount":30,"address":null}' . "\n";
echo 'line lengths: ', implode(' ', array_map('strlen', jf_lines($input))), "\n";
$in = jf_mem($input);
$out = jf_out();
try {
    Runner::process($in, $out, $plan);
    echo "no exception\n";
} catch (RecordException $e) {
    echo jf10_exc($e), "\n";
    var_dump($e->getReason() === 'path_not_object', $e->getField() === 'city', $e->getInputLine() === 3,
        $e->getRecordNumber() === 3, $e->getByteOffset() === 189);
}
$o = jf_out_bytes($out);
echo 'output (', strlen($o), ' bytes): ', $o;
?>
--EXPECT--
line lengths: 102 87 72
RecordException path_not_object field="city" at=3/3/189 [read=2 matched=1 emitted=1 bytesRead=189 bytesWritten=68]
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
output (68 bytes): {"id":7,"city":"Berlin","tier":"free","amount":25.0,"discount":0.0}
