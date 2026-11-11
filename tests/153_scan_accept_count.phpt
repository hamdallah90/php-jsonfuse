--TEST--
jsonfuse 1.0: a build with -DJF_SCAN_STATS counts the records the validating scan accepts; records it hands to yyjson and nested plans are not counted
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
require __DIR__ . '/jf10_helper.inc';
if (jf10_scan_accepted() === null) die('skip needs a build with -DJF_SCAN_STATS (the lanes of .github/ci/sanitizer.sh)');
?>
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * The counter that 151, 152 and tests/jf_scan_diff.inc read exists only with -DJF_SCAN_STATS.
 * This test skips without it, so a gate lane that requires every test to pass also shows that
 * those checks ran.
 */
function accepted(string $input, array $spec): string
{
    $before = jf10_scan_accepted();
    $in = jf_mem($input);
    $out = jf_out();
    try {
        $r = jf_stats(Runner::process($in, $out, Plan::compile($spec)));
    } catch (Throwable $e) {
        $r = get_class($e);
    }
    fclose($in);
    fclose($out);
    return (jf10_scan_accepted() - $before) . ' ' . $r;
}

$flat = ['fields' => ['s' => 'string', 'n' => 'int64']];
echo accepted("{\"s\":\"a\",\"n\":1}\n{\"s\":\"b\",\"n\":2}\n{\"s\":\"c\",\"n\":3}\n", $flat), "\n";
echo accepted("{\"s\":\"a\",\"n\":1}\n{\"s\":\"b\",\"n\":2,\"z\":tru}\n{\"s\":\"c\",\"n\":3}\n", $flat), "\n";
echo accepted("{\"s\":\"a\",\"n\":1,\"z\":1e400}\n{\"s\":\"b\",\"n\":2,\"w\":1e400}\n", $flat + ['where' => ['w' => ['>=' => 0]]]), "\n";
echo accepted("{\"m\":{\"k\":\"x\"}}\n", ['fields' => ['k' => ['type' => 'string', 'path' => ['m', 'k']]]]), "\n";
?>
--EXPECT--
3 read=3 matched=3 emitted=3 bytesRead=48 bytesWritten=48
1 JsonFuse\RecordException
1 read=2 matched=0 emitted=0 bytesRead=52 bytesWritten=0
0 read=1 matched=1 emitted=1 bytesRead=16 bytesWritten=10
