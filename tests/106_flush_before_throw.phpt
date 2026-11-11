--TEST--
jsonfuse 1.0: on a record error the buffered good records are written, at batch sizes below, at and above 64 KiB; the failing record is never written; no php_stream_flush on failure
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

$plan = Plan::compile(['fields' => ['s' => 'string']]);

/** A 4096-byte line whose projection is the same 4096 bytes. */
function line(int $i): string
{
    return '{"s":"' . str_repeat(chr(97 + $i % 26), 4096 - 9) . "\"}\n";
}

function run(string $label, string $input, string $want, Plan $plan): void
{
    $sink = jf_sink();
    try {
        $r = 'no exception: ' . jf_stats(Runner::process(jf_mem($input), $sink, $plan));
    } catch (Throwable $e) {
        $r = jf10_chain($e);
    }
    $s = JfSink::$last;
    echo $label, "\n  ", $r, "\n  output is exactly the good records: ", var_export($s->data === $want, true),
        ' (', strlen($s->data), ' bytes) flushes=', $s->flushes, "\n";
}

foreach ([1 => 'below 64 KiB', 16 => 'exactly 64 KiB', 17 => 'above 64 KiB'] as $k => $label) {
    $good = '';
    for ($i = 0; $i < $k; $i++) {
        $good .= line($i);
    }
    run("$label: $k good records, then a type mismatch", $good . "{\"s\":1}\n" . line(99), $good, $plan);
}

$good = line(0) . line(1);
run('a failing record larger than the batch is not written in part',
    $good . '{"s":"' . str_repeat('z', 70000) . "\",}\n" . line(2), $good, $plan);

$where = Plan::compile(['fields' => ['s' => 'string'], 'where' => ['m' => true]]);
run('records that do not match are not written',
    "{\"s\":\"a\",\"m\":true}\n{\"s\":\"b\",\"m\":false}\n{\"s\":\"c\",\"m\":true}\n{\"m\":true}\n",
    "{\"s\":\"a\"}\n{\"s\":\"c\"}\n", $where);
run('nothing buffered: nothing written',
    "{\"s\":\"b\",\"m\":false}\n{\"m\":true}\n", '', $where);
?>
--EXPECT--
below 64 KiB: 1 good records, then a type mismatch
  RecordException type_mismatch field="s" at=2/2/4096 [read=1 matched=1 emitted=1 bytesRead=4096 bytesWritten=4096]
  output is exactly the good records: true (4096 bytes) flushes=0
exactly 64 KiB: 16 good records, then a type mismatch
  RecordException type_mismatch field="s" at=17/17/65536 [read=16 matched=16 emitted=16 bytesRead=65536 bytesWritten=65536]
  output is exactly the good records: true (65536 bytes) flushes=0
above 64 KiB: 17 good records, then a type mismatch
  RecordException type_mismatch field="s" at=18/18/69632 [read=17 matched=17 emitted=17 bytesRead=69632 bytesWritten=69632]
  output is exactly the good records: true (69632 bytes) flushes=0
a failing record larger than the batch is not written in part
  RecordException invalid_json field=null at=3/3/8192 [read=2 matched=2 emitted=2 bytesRead=8192 bytesWritten=8192]
  output is exactly the good records: true (8192 bytes) flushes=0
records that do not match are not written
  RecordException missing_field field="s" at=4/4/58 [read=3 matched=2 emitted=2 bytesRead=58 bytesWritten=20]
  output is exactly the good records: true (20 bytes) flushes=0
nothing buffered: nothing written
  RecordException missing_field field="s" at=2/2/20 [read=1 matched=0 emitted=0 bytesRead=20 bytesWritten=0]
  output is exactly the good records: true (0 bytes) flushes=0
