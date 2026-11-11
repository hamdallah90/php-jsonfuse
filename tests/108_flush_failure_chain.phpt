--TEST--
jsonfuse 1.0: when writing the buffered records fails after a record error, write_failed carries the recorded exception as previous, a thrown exception gets it appended to its chain, and progress counts the accepted bytes
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

const P = "{\"id\":1}\n";
$input = "{\"id\":1}\n{\"id\":2}\n{\"id\":\"x\"}\n";

function attempt(string $label, $in, array $sinkCfg): void
{
    $sink = jf_sink($sinkCfg);
    try {
        $r = 'no exception: ' . jf_stats(Runner::process($in, $sink, jf_plan()));
    } catch (Throwable $e) {
        $r = jf10_chain($e) . (isset($sinkCfg['exc']) && $e === $sinkCfg['exc'] ? ' (the thrown object)' : '');
    }
    echo $label, "\n  ", $r, "\n  out=", json_encode(JfSink::$last->data), ' flushes=', JfSink::$last->flushes, "\n";
}

attempt('write returns 0 at the first byte', jf_mem($input), ['mode' => 'zero']);
attempt('write returns false at the first byte', jf_mem($input), ['mode' => 'false']);
attempt('write returns 0 after 13 bytes, inside the second record', jf_mem($input), ['mode' => 'zero', 'after' => 13]);
attempt('write throws at the first byte', jf_mem($input), ['mode' => 'throw', 'exc' => new JfBoom('sink')]);
attempt('write throws after 9 bytes, the thrown exception has its own previous', jf_mem($input),
    ['mode' => 'throw', 'after' => 9, 'exc' => new JfBoom('sink', 0, new LogicException('inner'))]);
attempt('the recorded failure is read_failed', jf_wrap(P . P . P, 18, ['mode' => 'false_read', 'after' => 18]), ['mode' => 'zero']);
?>
--EXPECT--
write returns 0 at the first byte
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0] <- RecordException type_mismatch field="id" at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0]
  out="" flushes=0
write returns false at the first byte
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0] <- RecordException type_mismatch field="id" at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0]
  out="" flushes=0
write returns 0 after 13 bytes, inside the second record
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=1 bytesRead=18 bytesWritten=13] <- RecordException type_mismatch field="id" at=3/3/18 [read=2 matched=2 emitted=1 bytesRead=18 bytesWritten=13]
  out="{\"id\":1}\n{\"id" flushes=0
write throws at the first byte
  JfBoom <- RecordException type_mismatch field="id" at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0] (the thrown object)
  out="" flushes=0
write throws after 9 bytes, the thrown exception has its own previous
  JfBoom <- LogicException <- RecordException type_mismatch field="id" at=3/3/18 [read=2 matched=2 emitted=1 bytesRead=18 bytesWritten=9] (the thrown object)
  out="{\"id\":1}\n" flushes=0
the recorded failure is read_failed
  StreamException write_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0] <- StreamException read_failed field=null at=3/3/18 [read=2 matched=2 emitted=0 bytesRead=18 bytesWritten=0]
  out="" flushes=0
