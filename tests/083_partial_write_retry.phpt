--TEST--
jsonfuse: a write that accepts part of the buffer and then fails once is retried from the first unaccepted byte, and only accepted bytes count
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * SPEC.md section 5 and 6: on a partial write retry the remaining bytes and count only what was accepted.
 * PHP's stream layer loops over ordinary short writes itself and only hands the runner a partial count when a
 * later call in that loop fails. So stream_write accepts `first` bytes, fails once, then accepts everything.
 */
final class Flaky
{
    public $context;
    public static string $data = '';
    public static array $script = [];
    public static int $calls = 0;
    public static array $offered = [];

    public function stream_open($p, $m, $o, &$op)
    {
        self::$data = '';
        self::$calls = 0;
        self::$offered = [];
        return true;
    }

    public function stream_write($d)
    {
        self::$offered[] = strlen($d);
        $step = self::$script[self::$calls++] ?? 'all';
        if ($step === 'zero') {
            return 0;
        }
        if ($step === 'false') {
            return false;
        }
        $n = $step === 'all' ? strlen($d) : $step;
        self::$data .= substr($d, 0, $n);
        return $n;
    }

    public function stream_close()
    {
    }
}
stream_wrapper_register('jfflaky', Flaky::class);

$plan = Plan::compile(['fields' => ['id' => 'int64']]);
$data = '';
$want = '';
for ($i = 1; $i <= 300; $i++) {
    $data .= '{"id":' . $i . "}\n";
    $want .= '{"id":' . $i . "}\n";
}

foreach ([
    '5 bytes, then 0, then the rest' => [5, 'zero'],
    '5 bytes, then false, then the rest' => [5, 'false'],
    '1 byte, then 0, then the rest' => [1, 'zero'],
    '1000 bytes, then false, then the rest' => [1000, 'false'],
] as $label => [$first, $fail]) {
    Flaky::$script = [$first, $fail];
    $out = fopen('jfflaky://x', 'wb');
    $in = jf_mem($data);
    try {
        $s = Runner::process($in, $out, $plan);
        echo str_pad($label, 40), jf_stats($s),
            ' intact: ', var_export(Flaky::$data === $want, true),
            ' bytesWritten is accepted bytes: ', var_export($s->bytesWritten === strlen($want), true), "\n";
    } catch (Throwable $e) {
        echo str_pad($label, 40), get_class($e), "\n";
    }
    fclose($in);
    fclose($out);
}
?>
--EXPECT--
5 bytes, then 0, then the rest          read=300 matched=300 emitted=300 bytesRead=3192 bytesWritten=3192 intact: true bytesWritten is accepted bytes: true
5 bytes, then false, then the rest      read=300 matched=300 emitted=300 bytesRead=3192 bytesWritten=3192 intact: true bytesWritten is accepted bytes: true
1 byte, then 0, then the rest           read=300 matched=300 emitted=300 bytesRead=3192 bytesWritten=3192 intact: true bytesWritten is accepted bytes: true
1000 bytes, then false, then the rest   read=300 matched=300 emitted=300 bytesRead=3192 bytesWritten=3192 intact: true bytesWritten is accepted bytes: true
