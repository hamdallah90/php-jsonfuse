--TEST--
jsonfuse 1.0: 128 fields whose defaults are 1024-byte strings that escape six-fold are written intact from 2-byte records under maxRecordBytes 2; the writer bound includes default outputs (run under ASan)
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

$fields = [];
$parts = [];
for ($i = 0; $i < 128; $i++) {
    $name = sprintf('f%03d', $i);
    $fields[$name] = ['type' => 'string', 'default' => str_repeat("\x01", 1024)];
    $parts[] = '"' . $name . '":"' . str_repeat('\u0001', 1024) . '"';
}
$line = '{' . implode(',', $parts) . "}\n";
$plan = Plan::compile(['fields' => $fields, 'maxRecordBytes' => 2]);

$sink = jf_sink();
$s = Runner::process(jf_mem("{}\n{}\n{}\n"), $sink, $plan);
echo jf_stats($s), "\n";
echo 'line bytes: ', strlen($line), "\n";
var_dump(JfSink::$last->data === str_repeat($line, 3));

echo "-- one present value among the defaults\n";
$line2 = str_replace('"f064":"' . str_repeat('\u0001', 1024) . '"', '"f064":"v"', $line);
$sink = jf_sink();
$s = Runner::process(jf_mem("{\"f064\":\"v\"}\n{}\n"), $sink, Plan::compile(['fields' => $fields]));
echo jf_stats($s), "\n";
var_dump(JfSink::$last->data === $line2 . $line);
?>
--EXPECT--
read=3 matched=3 emitted=3 bytesRead=9 bytesWritten=2363142
line bytes: 787714
bool(true)
-- one present value among the defaults
read=2 matched=2 emitted=2 bytesRead=16 bytesWritten=1569285
bool(true)
