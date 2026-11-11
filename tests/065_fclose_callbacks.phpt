--TEST--
jsonfuse: fclose() is refused after earlier read callbacks and from stream_eof, including the explicit eof call
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

final class Hooked
{
    public $context;
    public static array $cfg = [];
    public static int $reads = 0;
    public static int $eofs = 0;
    private int $pos = 0;

    public function stream_open($p, $m, $o, &$op)
    {
        self::$reads = self::$eofs = 0;
        return true;
    }

    public function stream_read($n)
    {
        (self::$cfg['read'])(++self::$reads);
        $s = substr(self::$cfg['data'], $this->pos, 4);
        $this->pos += strlen($s);
        return $s;
    }

    public function stream_eof()
    {
        (self::$cfg['eof'])(++self::$eofs);
        // With late_eof the eof reported during the final read is false, so the
        // runner makes the explicit php_stream_eof() call.
        if ($this->pos >= strlen(self::$cfg['data'])) {
            return empty(self::$cfg['late_eof']) || self::$eofs > self::$cfg['late_eof'];
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

stream_wrapper_register('jfhook', Hooked::class);

function run(array $cfg): void
{
    global $in, $out;
    Hooked::$cfg = $cfg + ['data' => "{\"\":1}\n{\"\":1}\n", 'read' => fn() => null, 'eof' => fn() => null];
    $in = fopen('jfhook://x', 'rb');
    $out = jf_out();
    echo jf_stats(Runner::process($in, $out, jf_plan(['fields' => ['' => 'int64']]))), "\n";
    var_dump(fclose($in), fclose($out));
}

$tryClose = function (string $where, $stream) {
    echo $where, ': ';
    var_dump(fclose($stream));
};

echo "-- from stream_read, after earlier read callbacks\n";
run(['read' => function (int $n) use ($tryClose) {
    global $in, $out;
    if ($n === 3) {
        $tryClose('read 3 input', $in);
    }
    if ($n === 4) {
        $tryClose('read 4 output', $out);
    }
}]);

echo "-- from stream_eof, after earlier read callbacks\n";
run(['eof' => function (int $n) use ($tryClose) {
    global $in, $out;
    if ($n === 2) {
        $tryClose('eof 2 input', $in);
        $tryClose('eof 2 output', $out);
    }
}]);

echo "-- from the explicit eof call after an empty read\n";
run(['late_eof' => 5, 'eof' => function (int $n) use ($tryClose) {
    global $in, $out;
    if ($n === 6) {
        $tryClose('explicit eof input', $in);
        $tryClose('explicit eof output', $out);
    }
}]);
?>
--EXPECTF--
-- from stream_read, after earlier read callbacks
read 3 input: 
Warning: %s in %s on line %d
bool(false)
read 4 output: 
Warning: %s in %s on line %d
bool(false)
read=2 matched=2 emitted=2 bytesRead=14 bytesWritten=14
bool(true)
bool(true)
-- from stream_eof, after earlier read callbacks
eof 2 input: 
Warning: %s in %s on line %d
bool(false)
eof 2 output: 
Warning: %s in %s on line %d
bool(false)
read=2 matched=2 emitted=2 bytesRead=14 bytesWritten=14
bool(true)
bool(true)
-- from the explicit eof call after an empty read
explicit eof input: 
Warning: %s in %s on line %d
bool(false)
explicit eof output: 
Warning: %s in %s on line %d
bool(false)
read=2 matched=2 emitted=2 bytesRead=14 bytesWritten=14
bool(true)
bool(true)
