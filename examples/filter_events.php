<?php
/**
 * Filters a small event log: keeps purchases, projects two fields, and shows how to get
 * all-or-nothing output, which JsonFuse does not provide itself.
 *
 * Run: php -d extension=modules/jsonfuse.so examples/filter_events.php
 */
declare(strict_types=1);

use JsonFuse\JsonFuseException;
use JsonFuse\Plan;
use JsonFuse\Runner;

$events = <<<'NDJSON'
{"event":"purchase","user_id":1,"amount":19.5,"sku":"A1"}
{"event":"view","user_id":2,"amount":0,"sku":"A2"}
{"event":"purchase","user_id":3,"amount":5,"sku":"B7","note":"gift é"}
NDJSON;

$input = tempnam(sys_get_temp_dir(), 'jf_in');
$outputPath = tempnam(sys_get_temp_dir(), 'jf_out');
file_put_contents($input, $events . "\n");

$plan = Plan::compile([
    'fields' => ['user_id' => 'int64', 'amount' => 'float64'],
    'where' => ['event' => 'purchase'],
]);

// Write to a temporary file and rename after success: on an error, partial output stays behind.
$temporary = $outputPath . '.partial';
$in = fopen($input, 'rb');
$out = fopen($temporary, 'wb');
try {
    $stats = Runner::process($in, $out, $plan);
    fclose($out);
    rename($temporary, $outputPath);
    printf("read %d, matched %d, emitted %d, %d bytes written\n", $stats->recordsRead, $stats->recordsMatched, $stats->recordsEmitted, $stats->bytesWritten);
    echo file_get_contents($outputPath);
} catch (JsonFuseException $e) {
    fclose($out);
    unlink($temporary);
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    fclose($in);
    unlink($input);
    @unlink($outputPath);
}
