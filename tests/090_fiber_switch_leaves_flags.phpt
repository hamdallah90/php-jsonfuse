--TEST--
jsonfuse 1.0: a fiber that starts and finishes inside a run's callback leaves NO_FCLOSE set on both streams; fclose() is refused and the streams are still freed
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

function refused($in, $out): string
{
    try {
        Runner::process($in, $out, jf_plan());
        return 'no exception';
    } catch (Throwable $e) {
        return get_class($e);
    }
}

JfStr::$data['in'] = "{\"id\":1}\n{\"id\":2}\n";
// Any switch counts, also one into a fiber that returns at once.
JfStr::$onWrite['out'] = function () {
    $f = new Fiber(fn () => 'returned');
    $f->start();
    echo '  callback fiber ', $f->getReturn(), "\n";
};
$in = fopen('jfstr://in', 'rb');
$out = fopen('jfstr://out', 'wb');

echo "-- the run\n";
$r = jf_stats(Runner::process($in, $out, jf_plan()));
echo '  ', $r, "\n";

echo "-- afterwards fclose() of either stream warns and returns false\n";
echo '  in:  ', jf10_fclose($in), "\n";
echo '  out: ', jf10_fclose($out), "\n";

echo "-- a new run on either stream is refused\n";
echo '  in:  ', refused($in, jf_out()), "\n";
echo '  out: ', refused(jf_mem("{\"id\":3}\n"), $out), "\n";

echo "-- each stream is freed when its last reference goes\n";
unset($in);
echo "  after unset(\$in)\n";
unset($out);
echo "  after unset(\$out)\n";
?>
--EXPECT--
-- the run
  callback fiber returned
  read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
-- afterwards fclose() of either stream warns and returns false
  in:  fclose=false warning=E_WARNING
  out: fclose=false warning=E_WARNING
-- a new run on either stream is refused
  in:  JsonFuse\StreamException
  out: JsonFuse\StreamException
-- each stream is freed when its last reference goes
  closed in
  after unset($in)
  closed out
  after unset($out)
