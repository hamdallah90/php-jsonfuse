--TEST--
jsonfuse 1.0: a record that ends at the end of the first 64 KiB read, which is also the end of the reader's allocation, goes through the validating scan with the same output
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * The input is exactly one 65536-byte read and the reader's first buffer holds that plus the
 * padding, so the last record's closing LF is the buffer's last byte before the padding. The
 * eight string lengths put the closing quote at every offset of an 8-byte word; under ASan
 * with USE_ZEND_ALLOC=0 a word read that crosses the padding is reported. What this proves is
 * narrower than "reads nothing past the padding": only k = 0 reaches the end with an 8-byte word,
 * and only a read ending 2 or more bytes past the padding is caught (a 1-byte overrun is not).
 * A build with -DJF_SCAN_STATS also checks that the scan accepted both records of every input.
 */
$plan = Plan::compile(['fields' => ['n' => 'int64', 's' => 'string']]);
for ($k = 0; $k < 8; $k++) {
    $last = '{"n":2,"s":"' . str_repeat('w', 64 + $k) . "\"}\n";
    $head = '{"n":1,"s":"' . str_repeat('y', 65536 - strlen($last) - strlen("{\"n\":1,\"s\":\"\"}\n")) . "\"}\n";
    $in = jf_mem($head . $last);
    $out = jf_out();
    $before = jf10_scan_accepted();
    $stats = Runner::process($in, $out, $plan);
    if ($before !== null && ($got = jf10_scan_accepted() - $before) !== 2) {
        echo "SCAN COUNT $k: accepted $got, expected 2\n";
    }
    rewind($out);
    $lines = explode("\n", rtrim(stream_get_contents($out), "\n"));
    echo $k, ' ', strlen($head . $last), ' ', jf_stats($stats), ' ', strlen($lines[0]), ' ', $lines[1], "\n";
    fclose($in);
    fclose($out);
}
?>
--EXPECT--
0 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65456 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
1 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65455 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
2 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65454 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
3 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65453 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
4 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65452 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
5 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65451 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
6 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65450 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
7 65536 read=2 matched=2 emitted=2 bytesRead=65536 bytesWritten=65536 65449 {"n":2,"s":"wwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwwww"}
