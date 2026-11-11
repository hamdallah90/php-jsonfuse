--TEST--
jsonfuse: nested Runner::process from a callback works on different streams and is refused on a stream already in use
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;
use JsonFuse\StreamException;


function refused(callable $run): string
{
    try {
        $run();
        return 'not refused';
    } catch (StreamException $e) {
        return $e->getReason() === 'stream_in_use' ? 'refused' : 'wrong reason: ' . $e->getReason();
    }
}

$plan = jf_plan();

echo "-- nested run on streams the outer run does not hold\n";
$nestedIn = jf_mem("{\"id\":1}\n");
$nestedOut = jf_out();
$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out, $plan, $nestedIn, $nestedOut) {
    if ($n === 1) {
        echo 'nested: ', jf_stats(Runner::process($nestedIn, $nestedOut, $plan)), "\n";
        // The nested run released only its own streams.
        var_dump(fclose($nestedIn), fclose($nestedOut));
        // The outer run still holds its streams.
        var_dump(fclose($in), fclose($out));
    }
}]);
$out = jf_out();
echo jf_stats(Runner::process($in, $out, $plan)), "\n";
var_dump(fclose($in), fclose($out));

echo "-- nested run sharing a stream with the outer run is refused\n";
$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out, $plan) {
    if ($n === 1) {
        echo 'shared output: ', refused(fn() => Runner::process(jf_mem("{\"id\":1}\n"), $out, $plan)), "\n";
        echo 'shared input: ', refused(fn() => Runner::process($in, jf_out(), $plan)), "\n";
    }
}]);
$out = jf_out();
echo jf_stats(Runner::process($in, $out, $plan)), "\n";
var_dump(fclose($in), fclose($out));
?>
--EXPECTF--
-- nested run on streams the outer run does not hold
nested: read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
bool(true)
bool(true)

Warning: %s in %s on line %d

Warning: %s in %s on line %d
bool(false)
bool(false)
read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
bool(true)
bool(true)
-- nested run sharing a stream with the outer run is refused
shared output: refused
shared input: refused
read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
bool(true)
bool(true)
