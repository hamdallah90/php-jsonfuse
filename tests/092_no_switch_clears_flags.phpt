--TEST--
jsonfuse 1.0: a run during which no fiber switch happens clears both stream flags, also inside a fiber and after an earlier run that did switch
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

$data = "{\"id\":1}\n{\"id\":2}\n";

echo "-- a run whose output callback runs PHP but no fiber\n";
JfStr::$data['in1'] = $data;
JfStr::$onWrite['out1'] = function () {
    echo "  callback without a fiber\n";
};
$in = fopen('jfstr://in1', 'rb');
$out = fopen('jfstr://out1', 'wb');
$r = jf_stats(Runner::process($in, $out, jf_plan()));
echo '  ', $r, "\n";
$r = jf10_fclose($in);
echo '  in:  ', $r, "\n";
$r = jf10_fclose($out);
echo '  out: ', $r, "\n";

echo "-- a run inside a fiber that suspends before and after the run, never during it\n";
$streams = [];
$f = new Fiber(function () use (&$streams, $data) {
    $streams = [jf_mem($data), jf_out()];
    Fiber::suspend('before');
    echo '  ', jf_stats(Runner::process($streams[0], $streams[1], jf_plan())), "\n";
    Fiber::suspend('after');
});
$v = $f->start();
echo '  suspended ', $v, "\n";
$v = $f->resume();
echo '  suspended ', $v, "\n";
echo '  in:  ', jf10_fclose($streams[0]), "\n";
echo '  out: ', jf10_fclose($streams[1]), "\n";
$f->resume();
var_dump($f->isTerminated());

echo "-- a run that switched leaves its own streams flagged, a later run without a switch does not\n";
JfStr::$data['in2'] = $data;
JfStr::$onWrite['out2'] = function () {
    (new Fiber(fn () => null))->start();
};
$in2 = fopen('jfstr://in2', 'rb');
$out2 = fopen('jfstr://out2', 'wb');
echo '  switched: ', jf_stats(Runner::process($in2, $out2, jf_plan())), "\n";
$in3 = jf_mem($data);
$out3 = jf_out();
echo '  later:    ', jf_stats(Runner::process($in3, $out3, jf_plan())), "\n";
echo '  later in:  ', jf10_fclose($in3), "\n";
echo '  later out: ', jf10_fclose($out3), "\n";
echo '  switched in:  ', jf10_fclose($in2), "\n";
echo '  switched out: ', jf10_fclose($out2), "\n";
JfStr::$onWrite = [];
unset($in2, $out2);
echo "-- done\n";
?>
--EXPECT--
-- a run whose output callback runs PHP but no fiber
  callback without a fiber
  read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  closed in1
  in:  fclose=true warning=none
  closed out1
  out: fclose=true warning=none
-- a run inside a fiber that suspends before and after the run, never during it
  suspended before
  read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  suspended after
  in:  fclose=true warning=none
  out: fclose=true warning=none
bool(true)
-- a run that switched leaves its own streams flagged, a later run without a switch does not
  switched: read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  later:    read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  later in:  fclose=true warning=none
  later out: fclose=true warning=none
  switched in:  fclose=false warning=E_WARNING
  switched out: fclose=false warning=E_WARNING
  closed in2
  closed out2
-- done
