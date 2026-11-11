--TEST--
jsonfuse 1.0: stream reason codes with position and progress; refusals before reading report line 1, record 1, offset 0 and zero progress; read failures write the buffered records first
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

const P = "{\"id\":1}\n";

function show(string $label, callable $run): void
{
    try {
        $r = jf_stats($run());
    } catch (Throwable $e) {
        $r = jf10_chain($e);
    }
    echo $label, "\n  ", $r, "\n";
}

echo "-- same_stream\n";
$f = jf_mem(P);
show('the same resource twice', fn () => Runner::process($f, $f, jf_plan()));

echo "-- stream_in_use\n";
$out = jf_out();
$in = jf_wrap(P . P, 100, ['on_read' => function (int $n) use (&$in, &$out) {
    if ($n === 1) {
        show('nested run on the held input', fn () => Runner::process($in, jf_out(), jf_plan()));
        show('nested run on the held output', fn () => Runner::process(jf_mem(P), $out, jf_plan()));
    }
}]);
show('the outer run', fn () => Runner::process($in, $out, jf_plan()));

JfStr::$data['in'] = P;
JfStr::$onWrite['out'] = function () {
    (new Fiber(fn () => null))->start();
};
$fin = fopen('jfstr://in', 'rb');
$fout = fopen('jfstr://out', 'wb');
show('a run whose callback switched fibers', fn () => Runner::process($fin, $fout, jf_plan()));
show('a new run on its input', fn () => Runner::process($fin, jf_out(), jf_plan()));
show('a new run on its output', fn () => Runner::process(jf_mem(P), $fout, jf_plan()));

echo "-- stream_closed: buffered records are discarded\n";
$victim = jf_out();
$in = jf_wrap(P . P . P, 9, ['on_read' => function (int $n) use (&$victim) {
    if ($n === 2) {
        pclose($victim);
    }
}]);
show('output closed by pclose() in the second read', fn () => Runner::process($in, $victim, jf_plan()));

echo "-- read_failed and no_data: buffered records are written first\n";
foreach (['false_read' => 'read returns false', 'empty_read' => "read returns '' before EOF"] as $mode => $label) {
    $in = jf_wrap(P . P . P, 18, ['mode' => $mode, 'after' => 18]);
    $out = jf_out();
    show("$label after two records", fn () => Runner::process($in, $out, jf_plan()));
    echo '  out=', json_encode(jf_out_bytes($out)), "\n";
    $in = jf_wrap(P . P . P, 18, ['mode' => $mode]);
    show("$label at the first read", fn () => Runner::process($in, jf_out(), jf_plan()));
}

echo "-- write_failed during the final write of a run that read all its input\n";
foreach ([['zero', 0], ['false', 0], ['zero', 13]] as [$mode, $after]) {
    $sink = jf_sink(['mode' => $mode, 'after' => $after]);
    show("write returns $mode after $after bytes", fn () => Runner::process(jf_mem(P . P), $sink, jf_plan()));
    echo '  out=', json_encode(JfSink::$last->data), ' flushes=', JfSink::$last->flushes, "\n";
}
JfStr::$onWrite = [];
unset($fin, $fout);
?>
--EXPECT--
-- same_stream
the same resource twice
  StreamException same_stream field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- stream_in_use
nested run on the held input
  StreamException stream_in_use field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
nested run on the held output
  StreamException stream_in_use field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
the outer run
  read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
a run whose callback switched fibers
  read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
a new run on its input
  StreamException stream_in_use field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
a new run on its output
  StreamException stream_in_use field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- stream_closed: buffered records are discarded
output closed by pclose() in the second read
  StreamException stream_closed field=null at=2/2/9 [read=1 matched=1 emitted=0 bytesRead=9 bytesWritten=0]
-- read_failed and no_data: buffered records are written first
read returns false after two records
  StreamException read_failed field=null at=3/3/18 [read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18]
  out="{\"id\":1}\n{\"id\":1}\n"
read returns false at the first read
  StreamException read_failed field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
read returns '' before EOF after two records
  StreamException no_data field=null at=3/3/18 [read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18]
  out="{\"id\":1}\n{\"id\":1}\n"
read returns '' before EOF at the first read
  StreamException no_data field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- write_failed during the final write of a run that read all its input
write returns zero after 0 bytes
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0]
  out="" flushes=0
write returns false after 0 bytes
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0]
  out="" flushes=0
write returns zero after 13 bytes
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=1 bytesRead=18 bytesWritten=13]
  out="{\"id\":1}\n{\"id" flushes=0
  closed in
  closed out
