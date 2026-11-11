--TEST--
jsonfuse: a non-zero flush on a stream that is not a userland wrapper raises StreamException, and a failing run does not flush a stream whose flush has an effect
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
if (!in_array('compress.zlib', stream_get_wrappers(), true)) die('skip compress.zlib stream wrapper is not available');
if (!file_exists('/dev/full')) die('skip /dev/full is not available');
?>
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\StreamException;

$plan = Plan::compile(['fields' => ['id' => 'int64']]);

echo "-- gzflush() reaches /dev/full: php_stream_flush returns non-zero\n";
$out = fopen('compress.zlib:///dev/full', 'wb');
$in = jf_mem('{"id":1}' . "\n");
try {
    $s = Runner::process($in, $out, $plan);
    echo 'no exception: ', jf_stats($s), "\n";
} catch (StreamException $e) {
    echo 'StreamException ', sprintf('line=%d record=%d offset=%d', $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset()), ' ', $e->getReason() === 'flush_failed' ? 'expected reason' : $e->getReason(), "\n";
}
fclose($in);
@fclose($out);

echo "-- a failing run after real writes leaves a gzip stream unflushed\n";
// Record A (~70 KB) fills the writer (p is projected); reserving for B writes A out, so the run has written
// something when C fails. Without a flush the gzip layer keeps that output buffered, so the file size does not
// move. Every read serves 64 bytes and C alone fills its own read, so A and B are processed before C's read begins.
$plan2 = Plan::compile(['fields' => ['id' => 'int64', 'p' => 'string']]);
$path = jf_tmp('084');
$a = '{"id":1,"p":"' . str_repeat('a', 64 * 1100 - 13 - 3) . "\"}\n";
$b = '{"id":2,"p":"' . str_repeat('b', 64 - 13 - 3) . "\"}\n";
$c = '[' . str_repeat(' ', 64 - 3) . "]\n";
$data = $a . $b . $c;
$failing = (strlen($a) + strlen($b)) / 64 + 1;
$out = fopen('compress.zlib://' . $path, 'wb');
$snap = null;
$in = jf_wrap($data, 64, ['on_read' => function (int $call) use (&$snap, $failing, $path) {
    if ($call === $failing) {
        clearstatcache();
        $snap = filesize($path);
    }
}]);
try {
    Runner::process($in, $out, $plan2);
    echo "no exception\n";
} catch (JsonFuse\RecordException $e) {
    clearstatcache();
    echo 'RecordException ', jf_ctx($e->getMessage()), ' file size unchanged since the failing read began: ', var_export($snap === filesize($path), true), "\n";
}
fclose($in);
fclose($out);
unlink($path);
?>
--CLEAN--
<?php
require __DIR__ . '/jf_helper.inc';
jf_clean('084');
?>
--EXPECT--
-- gzflush() reaches /dev/full: php_stream_flush returns non-zero
StreamException line=2 record=2 offset=9 expected reason
-- a failing run after real writes leaves a gzip stream unflushed
RecordException line=3 record=3 offset=70464 file size unchanged since the failing read began: true
