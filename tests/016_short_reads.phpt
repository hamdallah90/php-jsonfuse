--TEST--
jsonfuse: short reads (wrapper returning at most 7 bytes, pipe) give the same results as a plain file
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
if (!function_exists('popen')) die('skip popen is not available');
?>
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

function via_file(string $data, array $planOver): string
{
    $path = jf_tmp('016');
    try {
        file_put_contents($path, $data);
        $in = fopen($path, 'rb');
        $out = jf_out();
        try {
            return jf_stats(Runner::process($in, $out, jf_plan($planOver)));
        } catch (Throwable $e) {
            return get_class($e) . ' ' . jf_ctx($e->getMessage());
        } finally {
            fclose($in);
            fclose($out);
        }
    } finally {
        unlink($path);
    }
}

function via_wrapper(string $data, int $max, array $planOver, ?JfWrap &$w = null): string
{
    $in = jf_wrap($data, $max);
    $out = jf_out();
    try {
        return jf_stats(Runner::process($in, $out, jf_plan($planOver)));
    } catch (Throwable $e) {
        return get_class($e) . ' ' . jf_ctx($e->getMessage());
    } finally {
        $w = JfWrap::$last;
        fclose($in);
        fclose($out);
    }
}

$cases = [
    'LF records' => ["{\"id\":1,\"a\":1}\n{\"id\":2,\"b\":\"x\"}\n{\"id\":3}\n", []],
    'CRLF records' => ["{\"id\":1,\"a\":1}\r\n{\"id\":2,\"b\":\"x\"}\r\n", []],
    'no final LF' => ["{\"id\":1,\"a\":1}\n{\"id\":2,\"b\":2}", []],
    'multi-byte chars' => ["{\"id\":1,\"s\":\"caf\u{e9} \u{20ac} \u{1F600}\"}\n{\"id\":2,\"t\":\"\u{10FFFF}\u{e9}\"}\n", []],
    'blank lines skipped' => ["\n{\"id\":1}\n\r\n{\"id\":2}\n\n", ['skipEmptyLines' => true]],
    'empty input' => ['', []],
    'many records' => [str_repeat("{\"id\":1,\"n\":12345,\"s\":\"abcdefghij\"}\n", 400), []],
    'bad JSON on line 3' => ["{\"id\":1}\n{\"id\":2,\"a\":1}\n{\"a\":\n{}\n", []],
    'blank line error' => ["{\"id\":1}\n\n{\"id\":2}\n", []],
    'over cap on line 2' => ["{\"id\":1}\n{\"k\":\"" . str_repeat('a', 100) . "\"}\n", ['maxRecordBytes' => 64]],
    'invalid UTF-8 straddling reads' => ["{\"id\":1,\"a\":\"\xE2\x82\xAC\"}\n{\"a\":\"\xE2\x82\"}\n", []],
    'root array' => ["{\"id\":1}\n[1]\n", []],
];

foreach ($cases as $name => [$data, $over]) {
    $plain = via_file($data, $over);
    echo $name, ': ', $plain, "\n";
    foreach ([1, 2, 3, 7] as $max) {
        $r = via_wrapper($data, $max, $over, $w);
        $same = $r === $plain;
        // the wrapper really did serve short reads
        $short = $w->maxChunk <= $max
            && $w->reads >= (str_starts_with($plain, 'read=') ? max(1, intdiv(strlen($data), $max)) : 1);
        echo '  max=', $max, ' same=', var_export($same, true), ' short=', var_export($short, true);
        if (!$same) {
            echo ' got: ', $r;
        }
        echo "\n";
    }
}

// pipe input: short reads and EOF on pclose-able stream
$cmd = 'printf ' . escapeshellarg('%s\n%s\n') . ' ' . escapeshellarg('{"id":1,"a":1}') . ' ' . escapeshellarg('{"id":2,"b":[1,2,3]}');
$p = popen($cmd, 'r');
$out = jf_out();
echo 'pipe: ', jf_stats(Runner::process($p, $out, jf_plan())), "\n";
pclose($p);
fclose($out);
?>
--CLEAN--
<?php
require __DIR__ . '/jf_helper.inc';
jf_clean('016');
?>
--EXPECT--
LF records: read=3 matched=3 emitted=3 bytesRead=41 bytesWritten=27
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
CRLF records: read=2 matched=2 emitted=2 bytesRead=34 bytesWritten=18
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
no final LF: read=2 matched=2 emitted=2 bytesRead=29 bytesWritten=18
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
multi-byte chars: read=2 matched=2 emitted=2 bytesRead=52 bytesWritten=18
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
blank lines skipped: read=2 matched=2 emitted=2 bytesRead=22 bytesWritten=18
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
empty input: read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
many records: read=400 matched=400 emitted=400 bytesRead=14400 bytesWritten=3600
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
bad JSON on line 3: JsonFuse\RecordException line=3 record=3 offset=24
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
blank line error: JsonFuse\RecordException line=2 record=2 offset=9
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
over cap on line 2: JsonFuse\RecordException line=2 record=2 offset=9
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
invalid UTF-8 straddling reads: JsonFuse\RecordException line=2 record=2 offset=19
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
root array: JsonFuse\RecordException line=2 record=2 offset=9
  max=1 same=true short=true
  max=2 same=true short=true
  max=3 same=true short=true
  max=7 same=true short=true
pipe: read=2 matched=2 emitted=2 bytesRead=36 bytesWritten=18
