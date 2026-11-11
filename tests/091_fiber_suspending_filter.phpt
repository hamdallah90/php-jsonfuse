--TEST--
jsonfuse 1.0: the output callback starts a fiber that suspends inside the input's php_user_filter; after the run fclose() of both streams is refused, the fiber then completes and both streams are freed
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Runner;

/** Passes data through; inside a fiber it suspends once, holding the bucket brigade. */
final class JfSuspendFilter extends php_user_filter
{
    public static int $suspends = 0;

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            stream_bucket_append($out, $bucket);
        }
        if (Fiber::getCurrent() !== null && self::$suspends === 0) {
            self::$suspends++;
            Fiber::suspend('inside the input filter');
        }
        return PSFS_PASS_ON;
    }
}
stream_filter_register('jf.suspend', JfSuspendFilter::class);

JfStr::$data['in'] = "{\"id\":1}\n{\"id\":2}\n";
$in = fopen('jfstr://in', 'rb');
stream_filter_append($in, 'jf.suspend', STREAM_FILTER_READ);
$out = fopen('jfstr://out', 'wb');

$fiber = null;
JfStr::$onWrite['out'] = function () use (&$fiber, $in) {
    if ($fiber !== null) {
        return;
    }
    // The run has read to EOF; seeking back makes the fiber's read reach the filter.
    $fiber = new Fiber(function () use ($in) {
        echo '  fiber seek ', fseek($in, 0), "\n";
        $data = fread($in, 8192);
        echo '  fiber read ', strlen($data), " bytes\n";
    });
    $v = $fiber->start();
    echo '  fiber suspended: ', $v, "\n";
};

echo "-- the run\n";
$r = jf_stats(Runner::process($in, $out, jf_plan()));
echo '  ', $r, "\n";

echo "-- the fiber is still suspended inside the filter: fclose() is refused\n";
echo '  in:  ', jf10_fclose($in), "\n";
echo '  out: ', jf10_fclose($out), "\n";

echo "-- the fiber then completes\n";
$fiber->resume();
var_dump($fiber->isTerminated());

echo "-- both streams are freed with their last reference\n";
JfStr::$onWrite = [];
unset($fiber, $in);
echo "  after unset(\$in)\n";
unset($out);
echo "  after unset(\$out)\n";
?>
--EXPECT--
-- the run
  fiber seek 0
  fiber suspended: inside the input filter
  read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
-- the fiber is still suspended inside the filter: fclose() is refused
  in:  fclose=false warning=E_WARNING
  out: fclose=false warning=E_WARNING
-- the fiber then completes
  fiber read 18 bytes
bool(true)
-- both streams are freed with their last reference
  closed in
  after unset($in)
  closed out
  after unset($out)
