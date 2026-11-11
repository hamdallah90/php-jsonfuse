--TEST--
jsonfuse: pclose() of the output during an explicit stream_eof callback raises StreamException
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

// stream_read returns '' and the implicit eof says false, so the runner calls
// php_stream_eof() itself; that second stream_eof closes $out.
final class EofPclose
{
    public $context;
    public static int $calls = 0;
    public static $victim;

    public function stream_open($p, $m, $o, &$op)
    {
        return true;
    }

    public function stream_read($n)
    {
        return '';
    }

    public function stream_eof()
    {
        if (++self::$calls === 2) {
            pclose(self::$victim);
            return true;
        }
        return false;
    }

    public function stream_set_option($o, $a, $b)
    {
        return false;
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_close()
    {
    }
}

stream_wrapper_register('jfeof', EofPclose::class);
$in = fopen('jfeof://x', 'rb');
$out = jf_out();
EofPclose::$victim = $out;

try {
    echo jf_stats(Runner::process($in, $out, jf_plan())), "\n";
} catch (Throwable $e) {
    echo get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
}
var_dump(EofPclose::$calls, is_resource($out), fclose($in));
?>
--EXPECT--
JsonFuse\StreamException line=1 record=1 offset=0
int(2)
bool(false)
bool(true)
