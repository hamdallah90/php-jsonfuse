--TEST--
jsonfuse: an execution timeout stops the run at the next refill, with no PHP callback involved
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
if (!function_exists('popen')) die('skip popen is not available');
if (!function_exists('set_time_limit')) die('skip set_time_limit is not available');
?>
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

// An infinite plain stream: without the refill check the C loop never returns to the VM.
// 8.4 and later also print the in-flight StreamException before the fatal, hence the %A.
$in = popen("yes '{\"id\":1}' 2>/dev/null", 'r');
// Every record is written: a sink that holds nothing, or php://memory would reach memory_limit first.
$out = fopen('/dev/null', 'wb');
set_time_limit(1);
Runner::process($in, $out, jf_plan());
echo "not reached\n";
?>
--EXPECTF--
%AFatal error: Maximum execution time of 1 second exceeded in %s on line %d
