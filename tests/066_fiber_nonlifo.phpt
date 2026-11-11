--TEST--
jsonfuse 1.0: a second run on a stream held by a suspended fiber is refused, and the run that suspended leaves its own streams flagged
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

$plan = jf_plan();

function run_in_fiber(string $name, $in, $out, $plan): Fiber
{
    return new Fiber(function () use ($name, $in, $out, $plan) {
        try {
            $stats = jf_stats(Runner::process($in, $out, $plan));
            echo $name, ': ', $stats, "\n";
        } catch (Throwable $e) {
            echo $name, ': ', get_class($e), "\n";
        }
    });
}

$suspendEachRead = ['on_read' => function () {
    Fiber::suspend();
}];

echo "-- B shares A's input, B starts while A is suspended inside the read\n";
$in = jf_wrap(str_repeat("{\"id\":1}\n", 8), 9, $suspendEachRead);
$outA = jf_out();
$outB = jf_out();
$a = run_in_fiber('A', $in, $outA, $plan);
$b = run_in_fiber('B', $in, $outB, $plan);
$a->start();
$b->start();
var_dump($b->isTerminated(), $a->isTerminated());
// A still holds the stream.
var_dump(@fclose($in));
while (!$a->isTerminated()) {
    $a->resume();
}
// A's run switched fibers, so A's two streams stay flagged; B never held $outB.
echo 'in:   ', jf10_fclose($in), "\n";
echo 'outA: ', jf10_fclose($outA), "\n";
echo 'outB: ', jf10_fclose($outB), "\n";

echo "-- B shares only A's output\n";
$inA = jf_wrap(str_repeat("{\"id\":1}\n", 8), 9, $suspendEachRead);
$inB = jf_mem("{\"id\":1}\n");
$out = jf_out();
$a = run_in_fiber('A', $inA, $out, $plan);
$b = run_in_fiber('B', $inB, $out, $plan);
$a->start();
$b->start();
var_dump($b->isTerminated());
while (!$a->isTerminated()) {
    $a->resume();
}
echo 'inA: ', jf10_fclose($inA), "\n";
echo 'inB: ', jf10_fclose($inB), "\n";
echo 'out: ', jf10_fclose($out), "\n";
?>
--EXPECT--
-- B shares A's input, B starts while A is suspended inside the read
B: JsonFuse\StreamException
bool(true)
bool(false)
bool(false)
A: read=8 matched=8 emitted=8 bytesRead=72 bytesWritten=72
in:   fclose=false warning=E_WARNING
outA: fclose=false warning=E_WARNING
outB: fclose=true warning=none
-- B shares only A's output
B: JsonFuse\StreamException
bool(true)
A: read=8 matched=8 emitted=8 bytesRead=72 bytesWritten=72
inA: fclose=false warning=E_WARNING
inB: fclose=true warning=none
out: fclose=false warning=E_WARNING
