<?php
// bench_jsonfuse.php <mode> <shape> <fixture> <output> [--expect-reject]
// One timed process running the real extension through JsonFuse\Plan and JsonFuse\Runner. Prints one result line.
declare(strict_types=1);
require __DIR__ . '/lib.php';

use JsonFuse\Plan;
use JsonFuse\RecordException;
use JsonFuse\Runner;

[, $mode, $shape, $fixture, $output] = $argv;
$expectReject = in_array('--expect-reject', $argv, true);

jf_assert_engine($mode);
if (!extension_loaded('jsonfuse')) {
    fwrite(STDERR, "jsonfuse extension is not loaded\n");
    exit(3);
}

$plan = Plan::compile(jf_plan_spec($shape) + ['output' => 'ndjson']);

for ($i = (int)(getenv('WARMUPS') ?: 0); $i > 0; $i--) {
    $in = fopen($fixture, 'rb');
    $out = fopen($output, 'wb');
    try {
        Runner::process($in, $out, $plan);
    } catch (RecordException $e) {
        if (!$expectReject) {
            throw $e;
        }
    }
    fclose($in);
    fclose($out);
}

$in = fopen($fixture, 'rb');
$out = fopen($output, 'wb');

$t0 = hrtime(true);
$outcome = 'accepted';
$stats = null;
try {
    $stats = Runner::process($in, $out, $plan);
} catch (RecordException $e) {
    $outcome = 'rejected';
    if (!$expectReject) {
        throw $e;
    }
}
$ns = hrtime(true) - $t0;
fclose($in);
fclose($out);

if ($expectReject && $outcome !== 'rejected') {
    fwrite(STDERR, "expected a RecordException, but the record was accepted\n");
    exit(6);
}

$s = jf_engine_state();
printf(
    "ns=%d read=%d matched=%d emitted=%d bytes_in=%d bytes_out=%d peak_php=%d opcache=%d jit_on=%d jit_used=%d outcome=%s\n",
    $ns,
    $stats?->recordsRead ?? 0,
    $stats?->recordsMatched ?? 0,
    $stats?->recordsEmitted ?? 0,
    $stats?->bytesRead ?? 0,
    $stats?->bytesWritten ?? 0,
    memory_get_peak_usage(true),
    $s['opcache'] ? 1 : 0,
    $s['jit_on'] ? 1 : 0,
    $s['jit_buffer'] - $s['jit_free'],
    $outcome
);
