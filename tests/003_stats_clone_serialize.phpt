--TEST--
jsonfuse: Stats can be cloned, serialized and unserialized, and the copies stay readonly
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;
use JsonFuse\Stats;

/* Stats holds no native state, so clone and serialization are allowed (SPEC.md). */
function try_write(Stats $s, string $prop): void
{
    try {
        $s->$prop = 99;
        echo "$prop written\n";
    } catch (Error $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}

$s = Runner::process(jf_mem("{\"id\":1}\n{\"id\":2,\"x\":true}\n"), jf_out(), jf_plan(['where' => ['id' => 2]]));
echo 'original: ', jf_stats($s), "\n";

$c = clone $s;
echo 'clone: ', get_class($c), ', ', jf_stats($c), ', same object: ', var_export($c === $s, true), "\n";
try_write($c, 'recordsRead');

$ser = serialize($s);
echo $ser, "\n";
$u = unserialize($ser);
echo 'unserialized: ', get_class($u), ', ', jf_stats($u), ', equal to original: ', var_export($u == $s, true), "\n";
try_write($u, 'bytesWritten');
echo 'original after: ', jf_stats($s), "\n";
?>
--EXPECT--
original: read=2 matched=1 emitted=1 bytesRead=27 bytesWritten=9
clone: JsonFuse\Stats, read=2 matched=1 emitted=1 bytesRead=27 bytesWritten=9, same object: false
Error: Cannot modify readonly property JsonFuse\Stats::$recordsRead
O:14:"JsonFuse\Stats":5:{s:11:"recordsRead";i:2;s:14:"recordsMatched";i:1;s:14:"recordsEmitted";i:1;s:9:"bytesRead";i:27;s:12:"bytesWritten";i:9;}
unserialized: JsonFuse\Stats, read=2 matched=1 emitted=1 bytesRead=27 bytesWritten=9, equal to original: true
Error: Cannot modify readonly property JsonFuse\Stats::$bytesWritten
original after: read=2 matched=1 emitted=1 bytesRead=27 bytesWritten=9
