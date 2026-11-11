--TEST--
jsonfuse: stream argument errors, I/O failures, exception identity, refused fclose(), streams left open
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

const M = 'SECRETMARK';

/** Run process(), return "Class" or the rendered stats; exceptions come back as the object. */
function go($in, $out, $plan = null)
{
    try {
        return jf_stats(Runner::process($in, $out, $plan ?? jf_plan()));
    } catch (Throwable $e) {
        return $e;
    }
}

function show(string $name, $r): void
{
    if ($r instanceof Throwable) {
        $leak = str_contains($r->getMessage(), M) ? ' LEAKS-MARKER' : '';
        echo str_pad($name, 44), get_class($r), $leak, "\n";
    } else {
        echo str_pad($name, 44), $r, "\n";
    }
}

/** After a run the caller's streams must be open and closable again. */
function after($in, $out): string
{
    $s = 'in open=' . var_export(is_resource($in), true) . ' out open=' . var_export(is_resource($out), true);
    if (is_resource($in)) {
        $s .= ' fclose(in)=' . var_export(fclose($in), true);
    }
    if (is_resource($out)) {
        $s .= ' fclose(out)=' . var_export(fclose($out), true);
    }
    return $s;
}

$data = "{\"id\":1,\"a\":\"" . M . "\"}\n{\"id\":2,\"b\":2}\n";

echo "-- TypeError for closed and non-stream arguments\n";
$closed = jf_mem($data);
fclose($closed);
$out = jf_out();
show('closed input', go($closed, $out));
echo '  ', after(jf_mem(''), $out), "\n";
$in = jf_mem($data);
$closed = jf_out();
fclose($closed);
show('closed output', go($in, $closed));
echo '  ', after($in, jf_out()), "\n";
foreach (['string' => 'php://memory', 'int' => 5, 'null' => null, 'array' => [], 'bool' => true, 'float' => 1.5, 'object' => new stdClass] as $k => $v) {
    $out = jf_out();
    show("input is $k", go($v, $out));
    $in = jf_mem('');
    show("output is $k", go($in, $v));
    fclose($in);
    fclose($out);
}
$in = jf_mem('');
$out = jf_out();
foreach (['string' => 'plan', 'null' => null, 'array' => ['fields' => ['id' => 'int64']], 'stdClass' => new stdClass] as $k => $v) {
    try {
        Runner::process($in, $out, $v);
        echo "plan is $k: no exception\n";
    } catch (Throwable $e) {
        echo str_pad("plan is $k", 44), get_class($e), "\n";
    }
}
echo '  ', after($in, $out), "\n";

echo "-- same resource for input and output\n";
$both = jf_mem($data);
show('$in === $out', go($both, $both));
echo '  ', after($both, jf_out()), "\n";

echo "-- input opened write-only\n";
$path = jf_tmp('060');
try {
    $wo = fopen($path, 'wb');
    $out = jf_out();
    set_error_handler(static fn() => true);
    try {
        show('write-only input', go($wo, $out));
    } finally {
        restore_error_handler();
    }
    echo '  ', after($wo, $out), "\n";
} finally {
    @unlink($path);
}

echo "-- output opened read-only: no write happens, so no error\n";
$path = jf_tmp('060');
try {
    file_put_contents($path, '');
    $ro = fopen($path, 'rb');
    $in = jf_mem("{\"id\":1}\n{\"id\":2}\n");
    show('read-only output, nothing to write', go($in, $ro, jf_plan(['where' => ['kind' => 'never']])));
    echo '  ', after($in, $ro), "\n";
} finally {
    @unlink($path);
}

echo "-- wrapper read returning false\n";
$in = jf_wrap($data, 7, ['mode' => 'false_read']);
$out = jf_out();
show('false at first read', go($in, $out));
echo '  ', after($in, $out), "\n";
$in = jf_wrap($data, 7, ['mode' => 'false_read', 'after' => 10]);
$out = jf_out();
show('false after 10 bytes (mid-record)', go($in, $out));
echo '  ', after($in, $out), "\n";
$in = jf_wrap($data, 100, ['mode' => 'false_read', 'after' => 1]);
$out = jf_out();
show('false after a whole record was read', go($in, $out));
echo '  ', after($in, $out), "\n";

echo "-- wrapper read returning '' while not at EOF\n";
$in = jf_wrap($data, 7, ['mode' => 'empty_read']);
$out = jf_out();
show("'' at first read, eof false", go($in, $out));
echo '  ', after($in, $out), "\n";
$in = jf_wrap($data, 7, ['mode' => 'empty_read', 'after' => 10]);
$out = jf_out();
show("'' after 10 bytes, eof false", go($in, $out));
echo '  ', after($in, $out), "\n";
$in = jf_wrap('', 7, ['mode' => 'empty_read']);
$out = jf_out();
show("'' on otherwise empty input, eof false", go($in, $out));
echo '  ', after($in, $out), "\n";

echo "-- wrapper callbacks that throw: same object, same class, same message, no previous\n";
foreach (['throw_read' => 'stream_read', 'throw_eof' => 'stream_eof'] as $mode => $label) {
    foreach ([0 => 'first call', 10 => 'after 10 bytes'] as $after => $when) {
        $boom = new JfBoom("boom-$label-$after");
        $in = jf_wrap($data, 7, ['mode' => $mode, 'after' => $after, 'exc' => $boom]);
        $out = jf_out();
        $r = go($in, $out);
        $same = $r === $boom;
        echo str_pad("$label throws ($when)", 44),
            $r instanceof Throwable ? get_class($r) : 'no exception',
            ' same=', var_export($same, true),
            ' message=', $r instanceof Throwable ? $r->getMessage() : '-',
            ' previous=', var_export($r instanceof Throwable ? $r->getPrevious() : 'n/a', true),
            "\n";
        echo '  ', after($in, $out), "\n";
    }
}
// a standard SPL exception class travels the same way
$boom = new LengthException('spl-boom', 42);
$in = jf_wrap($data, 7, ['mode' => 'throw_read', 'exc' => $boom]);
$out = jf_out();
$r = go($in, $out);
echo str_pad('LengthException from stream_read', 44), get_class($r), ' same=', var_export($r === $boom, true),
    ' code=', $r->getCode(), ' previous=', var_export($r->getPrevious(), true), "\n";

echo "-- fclose() of either stream from a callback is refused with a warning\n";
$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out) {
    if ($n === 1) {
        echo "callback: fclose(input)\n";
        $r = fclose($in);
        echo 'callback: returned ', var_export($r, true), "\n";
    }
}]);
$out = jf_out();
show('fclose(input) in stream_read', go($in, $out));
echo '  ', after($in, $out), "\n";

$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out) {
    if ($n === 1) {
        echo "callback: fclose(output)\n";
        $r = fclose($out);
        echo 'callback: returned ', var_export($r, true), "\n";
    }
}]);
$out = jf_out();
show('fclose(output) in stream_read', go($in, $out));
echo '  ', after($in, $out), "\n";

$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out) {
    if ($n === 1) {
        echo "callback: fclose both\n";
        $a = fclose($out);
        $b = fclose($in);
        echo 'callback: returned ', var_export($a, true), ' ', var_export($b, true), "\n";
    }
}]);
$out = jf_out();
show('fclose(both) in stream_read', go($in, $out));
echo '  ', after($in, $out), "\n";

echo "-- pclose() of the other stream from a callback raises StreamException\n";
$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) use (&$in, &$out) {
    if ($n === 1) {
        echo "callback: pclose(output)\n";
        pclose($out);
    }
}]);
$out = jf_out();
show('pclose(output) in stream_read', go($in, $out));
echo '  ', after($in, $out), "\n";

echo "-- non-blocking socket pair with no data\n";
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_blocking($pair[0], false);
$out = jf_out();
show('non-blocking socket, no data', go($pair[0], $out));
echo '  out ', 'open=', var_export(is_resource($out), true), "\n";
fwrite($pair[1], "{\"id\":1}\n{\"id\":2}\n");
fclose($pair[1]);
$out2 = jf_out();
show('same socket, data then EOF (blocking)', (function () use ($pair, $out2) {
    stream_set_blocking($pair[0], true);
    return go($pair[0], $out2);
})());
fclose($pair[0]);
fclose($out);
fclose($out2);

echo "-- success leaves both streams open\n";
$in = jf_mem($data);
$out = jf_out();
show('success', go($in, $out));
echo '  ', after($in, $out), "\n";
$in = jf_wrap($data, 7);
$out = jf_out();
show('success through wrapper', go($in, $out));
echo '  ', after($in, $out), "\n";
?>
--CLEAN--
<?php
require __DIR__ . '/jf_helper.inc';
jf_clean('060');
?>
--EXPECTF--
-- TypeError for closed and non-stream arguments
closed input                                TypeError
  in open=true out open=true fclose(in)=true fclose(out)=true
closed output                               TypeError
  in open=true out open=true fclose(in)=true fclose(out)=true
input is string                             TypeError
output is string                            TypeError
input is int                                TypeError
output is int                               TypeError
input is null                               TypeError
output is null                              TypeError
input is array                              TypeError
output is array                             TypeError
input is bool                               TypeError
output is bool                              TypeError
input is float                              TypeError
output is float                             TypeError
input is object                             TypeError
output is object                            TypeError
plan is string                              TypeError
plan is null                                TypeError
plan is array                               TypeError
plan is stdClass                            TypeError
  in open=true out open=true fclose(in)=true fclose(out)=true
-- same resource for input and output
$in === $out                                JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
-- input opened write-only
write-only input                            JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
-- output opened read-only: no write happens, so no error
read-only output, nothing to write          read=2 matched=0 emitted=0 bytesRead=18 bytesWritten=0
  in open=true out open=true fclose(in)=true fclose(out)=true
-- wrapper read returning false
false at first read                         JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
false after 10 bytes (mid-record)           JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
false after a whole record was read         JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
-- wrapper read returning '' while not at EOF
'' at first read, eof false                 JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
'' after 10 bytes, eof false                JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
'' on otherwise empty input, eof false      JsonFuse\StreamException
  in open=true out open=true fclose(in)=true fclose(out)=true
-- wrapper callbacks that throw: same object, same class, same message, no previous
stream_read throws (first call)             JfBoom same=true message=boom-stream_read-0 previous=NULL
  in open=true out open=true fclose(in)=true fclose(out)=true
stream_read throws (after 10 bytes)         JfBoom same=true message=boom-stream_read-10 previous=NULL
  in open=true out open=true fclose(in)=true fclose(out)=true
stream_eof throws (first call)              JfBoom same=true message=boom-stream_eof-0 previous=NULL
  in open=true out open=true fclose(in)=true fclose(out)=true
stream_eof throws (after 10 bytes)          JfBoom same=true message=boom-stream_eof-10 previous=NULL
  in open=true out open=true fclose(in)=true fclose(out)=true
LengthException from stream_read            LengthException same=true code=42 previous=NULL
-- fclose() of either stream from a callback is refused with a warning
callback: fclose(input)

Warning: %s in %s on line %d
callback: returned false
fclose(input) in stream_read                read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  in open=true out open=true fclose(in)=true fclose(out)=true
callback: fclose(output)

Warning: %s in %s on line %d
callback: returned false
fclose(output) in stream_read               read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  in open=true out open=true fclose(in)=true fclose(out)=true
callback: fclose both

Warning: %s in %s on line %d

Warning: %s in %s on line %d
callback: returned false false
fclose(both) in stream_read                 read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
  in open=true out open=true fclose(in)=true fclose(out)=true
-- pclose() of the other stream from a callback raises StreamException
callback: pclose(output)
pclose(output) in stream_read               JsonFuse\StreamException
  in open=true out open=false fclose(in)=true
-- non-blocking socket pair with no data
non-blocking socket, no data                JsonFuse\StreamException
  out open=true
same socket, data then EOF (blocking)       read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
-- success leaves both streams open
success                                     read=2 matched=2 emitted=2 bytesRead=41 bytesWritten=18
  in open=true out open=true fclose(in)=true fclose(out)=true
success through wrapper                     read=2 matched=2 emitted=2 bytesRead=41 bytesWritten=18
  in open=true out open=true fclose(in)=true fclose(out)=true
