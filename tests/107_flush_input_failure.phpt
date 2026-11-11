--TEST--
jsonfuse 1.0: read failures flush like record errors, through user wrappers and user filters; exceptions thrown by an input wrapper discard the buffered records and propagate unchanged
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

const P = "{\"id\":1}\n";

final class JfUpper extends php_user_filter
{
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $bucket->data = strtoupper($bucket->data);
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('jf.upper', JfUpper::class);

function attempt($in, $out): string
{
    try {
        return 'no exception: ' . jf_stats(Runner::process($in, $out, jf_plan()));
    } catch (Throwable $e) {
        return jf10_chain($e) . ($e === ($GLOBALS['boom'] ?? null) ? ' (the same object)' : '');
    }
}

echo "-- flushing input failures, output through a user wrapper\n";
foreach (['false_read', 'empty_read'] as $mode) {
    $sink = jf_sink();
    echo $mode, "\n  ", attempt(jf_wrap(P . P . P, 18, ['mode' => $mode, 'after' => 18]), $sink), "\n";
    echo '  out=', json_encode(JfSink::$last->data), ' flushes=', JfSink::$last->flushes, "\n";
}

echo "-- a record error, output through a user write filter\n";
$out = jf_out();
stream_filter_append($out, 'jf.upper', STREAM_FILTER_WRITE);
echo '  ', attempt(jf_mem("{\"id\":1,\"k\":\"a\"}\n{\"id\":2,\"k\":\"b\"}\n{\"id\":null}\n"), $out), "\n";
echo '  out=', json_encode(jf_out_bytes($out)), "\n";

echo "-- non-flushing: an exception thrown by the input wrapper while records are buffered\n";
$boom = new JfBoom('from the wrapper');
foreach (['throw_read', 'throw_eof'] as $mode) {
    $sink = jf_sink();
    echo $mode, "\n  ", attempt(jf_wrap(P . P . P, 9, ['mode' => $mode, 'after' => 18, 'exc' => $boom]), $sink), "\n";
    echo '  writes=', JfSink::$last->writes, ' flushes=', JfSink::$last->flushes, "\n";
}
?>
--EXPECT--
-- flushing input failures, output through a user wrapper
false_read
  StreamException read_failed field=null at=3/3/18 [read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18]
  out="{\"id\":1}\n{\"id\":1}\n" flushes=0
empty_read
  StreamException no_data field=null at=3/3/18 [read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18]
  out="{\"id\":1}\n{\"id\":1}\n" flushes=0
-- a record error, output through a user write filter
  RecordException null_field field="id" at=3/3/34 [read=2 matched=2 emitted=2 bytesRead=34 bytesWritten=18]
  out="{\"ID\":1}\n{\"ID\":2}\n"
-- non-flushing: an exception thrown by the input wrapper while records are buffered
throw_read
  JfBoom (the same object)
  writes=0 flushes=0
throw_eof
  JfBoom (the same object)
  writes=0 flushes=0
