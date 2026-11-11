--TEST--
jsonfuse 1.0: a failing final flush on a stream that is not a userland wrapper raises StreamException flush_failed with full progress
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
if (!in_array('compress.zlib', stream_get_wrappers(), true)) die('skip compress.zlib stream wrapper is not available');
if (!file_exists('/dev/full')) die('skip /dev/full is not available');
?>
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

// gzflush() reaches /dev/full, so php_stream_flush returns non-zero after the write succeeded.
$out = fopen('compress.zlib:///dev/full', 'wb');
$in = jf_mem("{\"id\":1}\n{\"id\":2}\n");
try {
    echo 'no exception: ' . jf_stats(Runner::process($in, $out, jf_plan())) . "\n";
} catch (Throwable $e) {
    echo jf10_chain($e), "\n";
}
fclose($in);
@fclose($out);
?>
--EXPECT--
StreamException flush_failed field=null at=3/3/18 [read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18]
