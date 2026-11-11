--TEST--
jsonfuse: output stream behaviour - short writes, zero and failing writes, throwing callbacks, flush handling, fclose/pclose from callbacks, read-only output, no writes after an error
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\StreamException;

const M = 'SECRETMARK';

/** n records {"id":i,"z":"SECRETMARK"}: the plan below projects only id. */
function input(int $n): string
{
    $s = '';
    for ($i = 1; $i <= $n; $i++) {
        $s .= '{"id":' . $i . ',"z":"' . M . "\"}\n";
    }
    return $s;
}

/** The output the n records must produce. */
function expected(int $n): string
{
    $s = '';
    for ($i = 1; $i <= $n; $i++) {
        $s .= '{"id":' . $i . "}\n";
    }
    return $s;
}

function plan(array $over = []): Plan
{
    return Plan::compile($over + ['fields' => ['id' => 'int64']]);
}

/** Class of the Throwable a run raises ('none' when it succeeds), plus the sink it wrote to. */
function attempt($in, $out, ?Plan $plan = null): array
{
    try {
        $s = Runner::process($in, $out, $plan ?? plan());
        return ['none', $s];
    } catch (Throwable $e) {
        return [get_class($e), $e];
    }
}

echo "-- short writes: 1, 2, 3, 5, 7 and 4097 bytes per stream_write give the same output and counters\n";
foreach ([[1, 3000], [2, 3000], [3, 3000], [5, 1000], [7, 8000], [4097, 8000], [1, 0], [3, 1]] as [$max, $n]) {
    foreach (['sink' => 'jf_sink', 'sink without stream_flush' => 'jf_sink_nf'] as $kind => $open) {
        $out = $open(['max' => $max]);
        [$class, $s] = attempt(jf_mem(input($n)), $out);
        $k = $kind === 'sink' ? JfSink::$last : JfSinkNf::$last;
        $want = expected($n);
        $okSizes = true;
        foreach ($k->sizes as [$offered, $took]) {
            $okSizes = $okSizes && $took <= $max && $took <= $offered;
        }
        $ok = $class === 'none' && $k->data === $want && $s->bytesWritten === strlen($want) && $s->recordsEmitted === $n
            && $k->accepted === $s->bytesWritten && $okSizes && $k->afterError === 0;
        echo str_pad("max $max, $n records, $kind", 56), $class === 'none' ? jf_stats($s) : $class, ' ', $ok ? 'ok' : 'FAIL', "\n";
        fclose($out);
    }
}

echo "-- a record far larger than a stream chunk, written 4097 or 8191 bytes at a time\n";
foreach ([4097, 8191, 8193] as $max) {
    $big = str_repeat("ab\n\"\u{e9}", 30000);               // 150000+ bytes of string value, escapes included
    $data = '{"s":' . json_encode($big, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "}\n";
    $out = jf_sink(['max' => $max, 'keep' => true]);
    [$class, $s] = attempt(jf_mem($data), $out, Plan::compile(['fields' => ['s' => 'string']]));
    $k = JfSink::$last;
    $want = '{"s":' . json_encode($big, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS) . "}\n";
    echo str_pad("max $max", 56), $class, ' output intact: ', var_export($k->data === $want, true), ' bytesWritten == length: ', var_export($class === 'none' && $s->bytesWritten === strlen($want), true), "\n";
    fclose($out);
}

echo "-- stream_write returning 0 or false raises StreamException and stops all writing\n";
// PHP's stream layer loops over a short write by itself and may call the wrapper once more inside the same
// php_stream_write(); a failure after a partial acceptance is therefore seen by the wrapper once or twice.
function failing(string $label, array $cfg, int $n = 4, array $planOver = []): void
{
    $guard = function (int $calls) {
        if ($calls > 200000) {
            throw new LogicException('runaway write loop');
        }
    };
    $out = jf_sink($cfg + ['on_write' => $guard]);
    [$class, $e] = attempt(jf_mem(input($n)), $out, plan($planOver));
    $k = JfSink::$last;
    $msg = $e instanceof Throwable ? $e->getMessage() : '';
    echo str_pad($label, 52), $class,
        ' prefix=', var_export(str_starts_with(expected($n), $k->data), true),
        ' accepted=', $k->accepted,
        ($cfg['after'] ?? 0) === 0 ? ' failing calls=' . $k->failures : ' failing calls in 1..2: ' . var_export($k->failures >= 1 && $k->failures <= 2, true),
        $class === 'none' ? ' stats=' . jf_stats($e) : '',
        str_contains($msg, M) ? ' LEAKS-MARKER' : '', "\n";
    fclose($out);
}
failing('zero at the first write', ['mode' => 'zero']);
failing('false at the first write', ['mode' => 'false']);
failing('zero after 5 bytes (mid-record)', ['mode' => 'zero', 'after' => 5]);
failing('false after 5 bytes (mid-record)', ['mode' => 'false', 'after' => 5]);
failing('zero after 9 bytes (record boundary)', ['mode' => 'zero', 'after' => 9], 4);
failing('zero after 13 bytes, 1 byte per write', ['mode' => 'zero', 'after' => 13, 'max' => 1]);
failing('false after 13 bytes, 2 bytes per write', ['mode' => 'false', 'after' => 13, 'max' => 2]);
failing('zero after 20000 bytes of a long output', ['mode' => 'zero', 'after' => 20000, 'max' => 777], 5000);
failing('false after 20000 bytes of a long output', ['mode' => 'false', 'after' => 20000, 'max' => 4096], 5000);
failing('zero, small records cap', ['mode' => 'zero', 'after' => 100], 600, ['maxRecordBytes' => 64]);
failing('false, small records cap', ['mode' => 'false', 'after' => 100], 600, ['maxRecordBytes' => 64]);

echo "-- a throwing stream_write or stream_flush: the same object, no previous\n";
function throwing(string $label, array $cfg, Throwable $boom, int $n = 3): void
{
    $out = jf_sink($cfg + ['exc' => $boom]);
    [$class, $e] = attempt(jf_mem(input($n)), $out);
    $k = JfSink::$last;
    echo str_pad($label, 52), $class, ' same=', var_export($e === $boom, true), ' previous=', $e instanceof Throwable ? ($e->getPrevious() === null ? 'NULL' : get_class($e->getPrevious())) : 'n/a',
        ' message=', $e instanceof Throwable ? $e->getMessage() : '-', ' code=', $e instanceof Throwable ? $e->getCode() : '-',
        ' writesAfterError=', $k->afterError, ' prefix=', var_export(str_starts_with(expected($n), $k->data), true), "\n";
    fclose($out);
}
throwing('stream_write throws at once', ['mode' => 'throw'], new JfBoom('boom-write-0'));
throwing('stream_write throws after 5 bytes', ['mode' => 'throw', 'after' => 5], new JfBoom('boom-write-5'));
throwing('stream_write throws after 5 bytes, 1 per write', ['mode' => 'throw', 'after' => 5, 'max' => 1], new JfBoom('boom-write-5-1'));
throwing('stream_write throws a LengthException (code 42)', ['mode' => 'throw'], new LengthException('boom-length', 42));
throwing('stream_write throws a StreamException', ['mode' => 'throw'], new StreamException('mine'));
throwing('stream_write throws a TypeError', ['mode' => 'throw'], new TypeError('boom-type'));
throwing('stream_flush throws', ['flush' => 'throw'], new JfBoom('boom-flush'));
throwing('stream_flush throws a StreamException', ['flush' => 'throw'], new StreamException('mine-flush'));
$withPrev = new JfBoom('outer', 7, new RuntimeException('inner'));
throwing('thrown exception keeps its own previous', ['mode' => 'throw'], $withPrev);
echo 'its previous is still the inner one: ', get_class($withPrev->getPrevious()), "\n";

echo "-- stream_flush throwing: every record was written before it\n";
$boom = new JfBoom('boom-flush-2');
$out = jf_sink(['flush' => 'throw', 'exc' => $boom, 'max' => 4]);
[$class, $e] = attempt(jf_mem(input(50)), $out);
echo $class, ' same=', var_export($e === $boom, true), ' all output written: ', var_export(JfSink::$last->data === expected(50), true), ' flushes=', JfSink::$last->flushes, "\n";
fclose($out);

echo "-- flush is called once, after the final write\n";
foreach ([['1 record', 1, []], ['3 records', 3, []], ['3000 records', 3000, []], ['3000 records, 1 byte per write', 3000, ['max' => 1]], ['3000 records, cap 64', 3000, ['cap' => 64]]] as [$label, $n, $cfg]) {
    $planOver = isset($cfg['cap']) ? ['maxRecordBytes' => $cfg['cap']] : [];
    unset($cfg['cap']);
    $out = jf_sink($cfg);
    [$class, $s] = attempt(jf_mem(input($n)), $out, plan($planOver));
    $k = JfSink::$last;
    $ev = implode('', $k->events);
    $oneFlushLast = $k->flushes === 1 && substr($ev, -1) === 'f' && substr_count($ev, 'f') === 1 && $ev[0] === 'w' && preg_match('/^w+f$/', $ev) === 1;
    echo str_pad($label, 40), $class, ' flushes before fclose=', $k->flushes, ' writes then one flush last: ', var_export($oneFlushLast, true), "\n";
    fclose($out);
}

echo "-- flush result handling\n";
$out = jf_sink_nf();
[$class, $s] = attempt(jf_mem(input(3)), $out);
echo str_pad('wrapper without stream_flush', 52), $class, ' ', $class === 'none' ? jf_stats($s) : '', ' data ok: ', var_export(JfSinkNf::$last->data === expected(3), true), "\n";
fclose($out);
$out = jf_sink(['flush' => false]);
[$class, $s] = attempt(jf_mem(input(3)), $out);
echo str_pad('wrapper whose stream_flush returns false', 52), $class, ' ', $class === 'none' ? jf_stats($s) : '', ' data ok: ', var_export(JfSink::$last->data === expected(3), true), "\n";
fclose($out);
$path = jf_tmp('061');
try {
    foreach (['php://memory' => 'w+b', 'php://temp' => 'w+b', $path => 'wb'] as $target => $mode) {
        $out = fopen($target, $mode);
        [$class, $s] = attempt(jf_mem(input(3)), $out);
        echo str_pad('plain stream ' . ($target === $path ? 'file' : $target), 52), $class, ' ', $class === 'none' ? jf_stats($s) : '', "\n";
        fclose($out);
    }
    echo 'plain file holds the output: ', var_export(file_get_contents($path) === expected(3), true), "\n";
} finally {
    @unlink($path);
}

echo "-- bytesWritten counts only the bytes the stream accepted\n";
final class Overclaim
{
    public $context;
    public static string $data = '';
    public static int $claimed = 0;

    public function stream_open($p, $m, $o, &$op)
    {
        self::$data = '';
        self::$claimed = 0;
        return true;
    }

    public function stream_write($d)
    {
        self::$data .= $d;
        self::$claimed += strlen($d) + 10;
        return strlen($d) + 10;          // PHP clamps this to the length offered, with a warning
    }

    public function stream_flush()
    {
        return true;
    }

    public function stream_close()
    {
    }
}
stream_wrapper_register('jfover', Overclaim::class);
$warnings = 0;
set_error_handler(function () use (&$warnings) {
    $warnings++;
    return true;
});
$out = fopen('jfover://x', 'wb');
[$class, $s] = attempt(jf_mem(input(5)), $out);
restore_error_handler();
echo $class, ' ', $class === 'none' ? jf_stats($s) : '', ' stream holds ', strlen(Overclaim::$data), ' bytes, bytesWritten equals that: ',
    var_export($class === 'none' && $s->bytesWritten === strlen(Overclaim::$data), true), ', PHP warned: ', var_export($warnings > 0, true), "\n";
fclose($out);

echo "-- a partial acceptance is counted exactly\n";
foreach ([1, 4, 10, 100] as $max) {
    $out = jf_sink(['max' => $max]);
    [$class, $s] = attempt(jf_mem(input(40)), $out);
    $k = JfSink::$last;
    $sum = array_sum(array_column($k->sizes, 1));
    echo str_pad("max $max", 20), 'bytesWritten=', $s->bytesWritten, ' sum of accepted=', $sum, ' stream holds=', strlen($k->data), ' equal: ', var_export($s->bytesWritten === $sum && $sum === strlen($k->data), true), "\n";
    fclose($out);
}

echo "-- fclose() of either stream from stream_write or stream_flush is refused, the run succeeds\n";
foreach (['write' => 'on_write', 'flush' => 'on_flush'] as $where => $hook) {
    $in = jf_mem(input(3));
    $out = jf_sink([$hook => function () use (&$in, &$out, $where) {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        echo "$where:\n";
        var_dump(fclose($in));
        var_dump(fclose($out));
    }]);
    [$class, $s] = attempt($in, $out);
    echo $class, ' ', $class === 'none' ? jf_stats($s) : '', ' open: ', var_export(is_resource($in), true), ' ', var_export(is_resource($out), true), "\n";
    echo 'closable afterwards: ', is_resource($in) ? var_export(fclose($in), true) : 'ALREADY-CLOSED', ' ', is_resource($out) ? var_export(fclose($out), true) : 'ALREADY-CLOSED', "\n";
}

echo "-- pclose() of the input from stream_write or stream_flush raises StreamException, with no writes afterwards\n";
foreach (['write #1' => ['on_write', 1, 40, []], 'write #3 of many (cap 64)' => ['on_write', 3, 4000, ['cap' => 64]], 'flush' => ['on_flush', 1, 40, []]] as $label => [$hook, $at, $n, $extra]) {
    $planOver = isset($extra['cap']) ? ['maxRecordBytes' => $extra['cap']] : [];
    unset($extra['cap']);
    $in = jf_mem(input($n));
    $count = 0;
    $out = jf_sink($extra + [$hook => function ($n = 0) use (&$in, &$count, $at, $hook) {
        $count++;
        if ($count === $at) {
            pclose($in);
        }
    }]);
    [$class, $e] = attempt($in, $out, plan($planOver));
    $k = JfSink::$last;
    echo str_pad($label, 32), $class, ' input closed: ', var_export(!is_resource($in), true), ' output open: ', var_export(is_resource($out), true),
        $hook === 'on_write' ? ' writes after the pclose: ' . ($k->writes - $at) : ' flushes: ' . $k->flushes, "\n";
    if (is_resource($out)) {
        fclose($out);
    }
}

echo "-- a read-only output: zero matches succeed, a match raises StreamException\n";
$path = jf_tmp('061');
try {
    file_put_contents($path, '');
    set_error_handler(static fn() => true);
    foreach (['plain file, no match' => [fn() => fopen($path, 'rb'), ['where' => ['kind' => 'never']]],
              'plain file, match' => [fn() => fopen($path, 'rb'), []],
              'php://memory read-only, no match' => [fn() => fopen('php://memory', 'rb'), ['where' => ['kind' => 'never']]],
              'php://memory read-only, match' => [fn() => fopen('php://memory', 'rb'), []]] as $label => [$open, $over]) {
        $out = $open();
        $in = jf_mem("{\"id\":1,\"kind\":\"x\"}\n{\"id\":2}\n");
        [$class, $e] = attempt($in, $out, plan($over));
        $msg = $e instanceof Throwable ? $e->getMessage() : '';
        echo str_pad($label, 40), $class, $class === 'none' ? ' ' . jf_stats($e) : '', str_contains($msg, '{"id"') ? ' LEAKS-RECORD' : '',
            ' file size: ', filesize($path), ' out open: ', var_export(is_resource($out), true), "\n";
        fclose($in);
        fclose($out);
    }
    restore_error_handler();
} finally {
    @unlink($path);
}

echo "-- no writes after an input failure\n";
$snap = null;
$input = "{\"id\":1}\n";
for ($i = 0; $i < 4000; $i++) {
    $input .= '{"id":' . ($i % 10) . "}\n";
}
$in = jf_wrap($input, 997, [
    'mode' => 'throw_read', 'after' => strlen($input) - 20, 'exc' => $boom = new JfBoom('boom-read'),
    'on_read' => function () use (&$snap) {
        $snap = [JfSink::$last->writes, JfSink::$last->accepted];
    },
]);
$out = jf_sink(['max' => 61]);
$full = "{\"id\":1}\n" . implode('', array_map(fn($i) => '{"id":' . ($i % 10) . "}\n", range(0, 3999)));
[$class, $e] = attempt($in, $out, plan(['maxRecordBytes' => 64]));
$k = JfSink::$last;
echo $class, ' same=', var_export($e === $boom, true), ' output so far is a prefix of the full output: ', var_export(str_starts_with($full, $k->data), true),
    ' some output had been written before the failing read: ', var_export($snap[1] > 0, true),
    ' writes after the failing read began: ', $k->writes - $snap[0], ', bytes: ', $k->accepted - $snap[1], "\n";
fclose($in);
fclose($out);

echo "-- the output is left open and usable after success and after failure\n";
$out = jf_out();
[$class, $s] = attempt(jf_mem(input(2)), $out);
var_dump(is_resource($out), fwrite($out, 'tail'), jf_out_bytes($out));
fclose($out);
$out = jf_out();
[$class, $e] = attempt(jf_mem(input(2) . "[]\n"), $out);
echo $class, "\n";
var_dump(is_resource($out), fwrite($out, 'tail'));
echo json_encode(strlen(jf_out_bytes($out)) >= 4 && str_ends_with(jf_out_bytes($out), 'tail')), "\n";
fclose($out);
$out = jf_sink();
[$class, $e] = attempt(jf_mem(input(2) . "[]\n"), $out);
echo $class, "\n";
var_dump(is_resource($out), fwrite($out, 'tail'), fclose($out));
?>
--CLEAN--
<?php
require __DIR__ . '/jf_helper.inc';
jf_clean('061');
?>
--EXPECTF--
-- short writes: 1, 2, 3, 5, 7 and 4097 bytes per stream_write give the same output and counters
max 1, 3000 records, sink                               read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 1, 3000 records, sink without stream_flush          read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 2, 3000 records, sink                               read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 2, 3000 records, sink without stream_flush          read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 3, 3000 records, sink                               read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 3, 3000 records, sink without stream_flush          read=3000 matched=3000 emitted=3000 bytesRead=85893 bytesWritten=34893 ok
max 5, 1000 records, sink                               read=1000 matched=1000 emitted=1000 bytesRead=27893 bytesWritten=10893 ok
max 5, 1000 records, sink without stream_flush          read=1000 matched=1000 emitted=1000 bytesRead=27893 bytesWritten=10893 ok
max 7, 8000 records, sink                               read=8000 matched=8000 emitted=8000 bytesRead=230893 bytesWritten=94893 ok
max 7, 8000 records, sink without stream_flush          read=8000 matched=8000 emitted=8000 bytesRead=230893 bytesWritten=94893 ok
max 4097, 8000 records, sink                            read=8000 matched=8000 emitted=8000 bytesRead=230893 bytesWritten=94893 ok
max 4097, 8000 records, sink without stream_flush       read=8000 matched=8000 emitted=8000 bytesRead=230893 bytesWritten=94893 ok
max 1, 0 records, sink                                  read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0 ok
max 1, 0 records, sink without stream_flush             read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0 ok
max 3, 1 records, sink                                  read=1 matched=1 emitted=1 bytesRead=26 bytesWritten=9 ok
max 3, 1 records, sink without stream_flush             read=1 matched=1 emitted=1 bytesRead=26 bytesWritten=9 ok
-- a record far larger than a stream chunk, written 4097 or 8191 bytes at a time
max 4097                                                none output intact: true bytesWritten == length: true
max 8191                                                none output intact: true bytesWritten == length: true
max 8193                                                none output intact: true bytesWritten == length: true
-- stream_write returning 0 or false raises StreamException and stops all writing
zero at the first write                             JsonFuse\StreamException prefix=true accepted=0 failing calls=1
false at the first write                            JsonFuse\StreamException prefix=true accepted=0 failing calls=1
zero after 5 bytes (mid-record)                     JsonFuse\StreamException prefix=true accepted=5 failing calls in 1..2: true
false after 5 bytes (mid-record)                    JsonFuse\StreamException prefix=true accepted=5 failing calls in 1..2: true
zero after 9 bytes (record boundary)                JsonFuse\StreamException prefix=true accepted=9 failing calls in 1..2: true
zero after 13 bytes, 1 byte per write               JsonFuse\StreamException prefix=true accepted=13 failing calls in 1..2: true
false after 13 bytes, 2 bytes per write             JsonFuse\StreamException prefix=true accepted=13 failing calls in 1..2: true
zero after 20000 bytes of a long output             JsonFuse\StreamException prefix=true accepted=20000 failing calls in 1..2: true
false after 20000 bytes of a long output            JsonFuse\StreamException prefix=true accepted=20000 failing calls in 1..2: true
zero, small records cap                             JsonFuse\StreamException prefix=true accepted=100 failing calls in 1..2: true
false, small records cap                            JsonFuse\StreamException prefix=true accepted=100 failing calls in 1..2: true
-- a throwing stream_write or stream_flush: the same object, no previous
stream_write throws at once                         JfBoom same=true previous=NULL message=boom-write-0 code=0 writesAfterError=0 prefix=true
stream_write throws after 5 bytes                   JfBoom same=true previous=NULL message=boom-write-5 code=0 writesAfterError=0 prefix=true
stream_write throws after 5 bytes, 1 per write      JfBoom same=true previous=NULL message=boom-write-5-1 code=0 writesAfterError=0 prefix=true
stream_write throws a LengthException (code 42)     LengthException same=true previous=NULL message=boom-length code=42 writesAfterError=0 prefix=true
stream_write throws a StreamException               JsonFuse\StreamException same=true previous=NULL message=mine code=0 writesAfterError=0 prefix=true
stream_write throws a TypeError                     TypeError same=true previous=NULL message=boom-type code=0 writesAfterError=0 prefix=true
stream_flush throws                                 JfBoom same=true previous=NULL message=boom-flush code=0 writesAfterError=0 prefix=true
stream_flush throws a StreamException               JsonFuse\StreamException same=true previous=NULL message=mine-flush code=0 writesAfterError=0 prefix=true
thrown exception keeps its own previous             JfBoom same=true previous=RuntimeException message=outer code=7 writesAfterError=0 prefix=true
its previous is still the inner one: RuntimeException
-- stream_flush throwing: every record was written before it
JfBoom same=true all output written: true flushes=1
-- flush is called once, after the final write
1 record                                none flushes before fclose=1 writes then one flush last: true
3 records                               none flushes before fclose=1 writes then one flush last: true
3000 records                            none flushes before fclose=1 writes then one flush last: true
3000 records, 1 byte per write          none flushes before fclose=1 writes then one flush last: true
3000 records, cap 64                    none flushes before fclose=1 writes then one flush last: true
-- flush result handling
wrapper without stream_flush                        none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27 data ok: true
wrapper whose stream_flush returns false            none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27 data ok: true
plain stream php://memory                           none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27
plain stream php://temp                             none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27
plain stream file                                   none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27
plain file holds the output: true
-- bytesWritten counts only the bytes the stream accepted
none read=5 matched=5 emitted=5 bytesRead=130 bytesWritten=45 stream holds 45 bytes, bytesWritten equals that: true, PHP warned: true
-- a partial acceptance is counted exactly
max 1               bytesWritten=391 sum of accepted=391 stream holds=391 equal: true
max 4               bytesWritten=391 sum of accepted=391 stream holds=391 equal: true
max 10              bytesWritten=391 sum of accepted=391 stream holds=391 equal: true
max 100             bytesWritten=391 sum of accepted=391 stream holds=391 equal: true
-- fclose() of either stream from stream_write or stream_flush is refused, the run succeeds
write:

Warning: %s in %s on line %d
bool(false)

Warning: %s in %s on line %d
bool(false)
none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27 open: true true
closable afterwards: true true
flush:

Warning: %s in %s on line %d
bool(false)

Warning: %s in %s on line %d
bool(false)
none read=3 matched=3 emitted=3 bytesRead=78 bytesWritten=27 open: true true
closable afterwards: true true
-- pclose() of the input from stream_write or stream_flush raises StreamException, with no writes afterwards
write #1                        JsonFuse\StreamException input closed: true output open: true writes after the pclose: 0
write #3 of many (cap 64)       JsonFuse\StreamException input closed: true output open: true writes after the pclose: 0
flush                           JsonFuse\StreamException input closed: true output open: true flushes: 1
-- a read-only output: zero matches succeed, a match raises StreamException
plain file, no match                    none read=2 matched=0 emitted=0 bytesRead=29 bytesWritten=0 file size: 0 out open: true
plain file, match                       JsonFuse\StreamException file size: 0 out open: true
php://memory read-only, no match        none read=2 matched=0 emitted=0 bytesRead=29 bytesWritten=0 file size: 0 out open: true
php://memory read-only, match           JsonFuse\StreamException file size: 0 out open: true
-- no writes after an input failure
JfBoom same=true output so far is a prefix of the full output: true some output had been written before the failing read: true writes after the failing read began: 0, bytes: 0
-- the output is left open and usable after success and after failure
bool(true)
int(4)
string(22) "{"id":1}
{"id":2}
tail"
JsonFuse\RecordException
bool(true)
int(4)
true
JsonFuse\RecordException
bool(true)
int(4)
bool(true)
