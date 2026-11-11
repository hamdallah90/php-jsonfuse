<?php
// check_equiv.php: proves the extension and every baseline agree, or names exactly where they do not.
// Exit 0 only if every case behaves as listed. Each entry in `diff` is a documented baseline limitation;
// anything not listed there is a failure. Run through run.sh --equiv.
declare(strict_types=1);
require __DIR__ . '/lib.php';

use JsonFuse\Plan;
use JsonFuse\Runner;

const IMPLS = ['jsonfuse', 'php', 'simdjson', 'fastjson'];
$F = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string'];
$W = ['e' => 'x'];
$BASE = '"i":1,"f":1.5,"b":true,"s":"a","e":"x"';
$cases = [];
$add = function (string $name, string $input, string $expect = 'ok', array $diff = [], ?array $fields = null, ?array $where = null) use (&$cases, $F, $W) {
    $cases[] = compact('name', 'input', 'expect', 'diff') + ['fields' => $fields ?? $F, 'where' => $where ?? $W];
};
$rec = fn(string $body) => "{{$body}}\n";

$add('basic match', $rec($BASE));
$add('no match', $rec('"i":1,"f":1.5,"b":true,"s":"a","e":"y"'));
$add('crlf', "{{$BASE}}\r\n{{$BASE}}\r\n");
$add('final record without newline', "{{$BASE}}\n{{$BASE}}");
$add('zero records', '');
$add('empty line', "{{$BASE}}\n\n", 'reject');
$add('field order follows plan', $rec('"s":"a","e":"x","b":true,"f":1.5,"i":1'));
$add('escapes round trip', $rec('"i":1,"f":1.5,"b":false,"s":"\"\\\\\/\b\f\n\r\t\u0001é😀\u0000","e":"x"'));
foreach ([
    'bad json' => '{"i":1,', 'trailing comma' => '{"i":1,}', 'root array' => '[1]',
    'lone surrogate' => '{"s":"\ud800"}', 'invalid utf8' => "{\"s\":\"\xff\"}", 'NUL in line' => "{{$BASE}}\0",
] as $name => $line) {
    $add($name, $line . "\n", 'reject');
}
$add('int64 rejects real', $rec('"i":1.0,"f":1.5,"b":true,"s":"a","e":"x"'), 'reject');
$add('int64 rejects out of range', $rec('"i":9223372036854775808,"f":1.5,"b":true,"s":"a","e":"x"'), 'reject');
$add('float64 accepts integer', $rec('"i":1,"f":5,"b":true,"s":"a","e":"x"'));
$add('float64 rejects string', $rec('"i":1,"f":"1","b":true,"s":"a","e":"x"'), 'reject');
$add('float64 rejects 1e400', $rec('"i":1,"f":1e400,"b":true,"s":"a","e":"x"'), 'reject');
$add('bool rejects 1', $rec('"i":1,"f":1.5,"b":1,"s":"a","e":"x"'), 'reject');
$add('string rejects null', $rec('"i":1,"f":1.5,"b":true,"s":null,"e":"x"'), 'reject');
$add('missing field on non-matching record', $rec('"f":1.5,"b":true,"s":"a","e":"y"'), 'reject');
$add('bad type on non-matching record', $rec('"i":"no","f":1.5,"b":true,"s":"a","e":"y"'), 'reject');
$add('nullable accepts null', $rec('"i":null,"f":null,"b":null,"s":null,"e":"x"'), 'ok', [], ['i' => 'int64?', 'f' => 'float64?', 'b' => 'bool?', 's' => 'string?']);
$add('nullable still requires presence', $rec('"f":null,"b":null,"s":null,"e":"x"'), 'reject', [], ['i' => 'int64?', 'f' => 'float64?', 'b' => 'bool?', 's' => 'string?']);
$add('filter object rejected', $rec('"i":1,"f":1.5,"b":true,"s":"a","e":{"a":1}'), 'reject');
$add('filter wrong scalar type', $rec('"i":1,"f":1.5,"b":true,"s":"a","e":5'));
$add('filter int vs 5.0', $rec('"i":1,"f":1.5,"b":true,"s":"a","e":5.0'), 'ok', [], null, ['e' => 5]);

// Known gaps: a decoded PHP array cannot see these, or a baseline's depth/number rules differ.
$add('duplicate projected key', $rec('"i":1,"i":2,"f":1.5,"b":true,"s":"a","e":"x"'), 'reject', ['php' => 'ok', 'simdjson' => 'ok', 'fastjson' => 'ok']);
$add('duplicate filter key', $rec('"i":1,"f":1.5,"b":true,"s":"a","e":"x","e":"y"'), 'reject', ['php' => 'ok', 'simdjson' => 'ok', 'fastjson' => 'ok']);
$add('nesting 600 deep, unrelated', $rec($BASE . ',"z":' . str_repeat('[', 600) . str_repeat(']', 600)), 'reject', ['php' => 'reject', 'simdjson' => 'reject', 'fastjson' => 'reject']);
$add('1e400 in unrelated field', $rec($BASE . ',"z":1e400'), 'ok', ['php' => 'ok', 'simdjson' => '?', 'fastjson' => '?']);

function run_impl(string $impl, array $c): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'jf');
    $outp = $tmp . '.out';
    file_put_contents($tmp, $c['input']);
    $in = fopen($tmp, 'rb');
    $out = fopen($outp, 'wb');
    try {
        if ($impl === 'jsonfuse') {
            $s = Runner::process($in, $out, Plan::compile(['fields' => $c['fields'], 'where' => $c['where']]));
            $counts = ['records_read' => $s->recordsRead, 'records_matched' => $s->recordsMatched, 'records_emitted' => $s->recordsEmitted, 'bytes_read' => $s->bytesRead, 'bytes_written' => $s->bytesWritten];
        } else {
            [$fn] = jf_baseline($c['fields'], $c['where'], $impl);
            $counts = $fn($in, $out);
        }
        fclose($out);
        return ['status' => 'ok', 'counts' => $counts, 'out' => file_get_contents($outp)];
    } catch (RuntimeException $e) {
        return ['status' => 'reject', 'msg' => $e->getMessage()];
    } finally {
        @unlink($tmp);
        @unlink($outp);
    }
}

$fails = 0;
printf("%-40s %-9s %-9s %-9s %-9s %s\n", 'case', 'jsonfuse', 'php', 'simdjson', 'fastjson', 'verdict');
foreach ($cases as $c) {
    $res = [];
    foreach (IMPLS as $impl) { $res[$impl] = run_impl($impl, $c); }
    $problems = [];
    foreach (IMPLS as $impl) {
        $want = $c['diff'][$impl] ?? $c['expect'];
        if ($want !== '?' && $res[$impl]['status'] !== $want) {
            $problems[] = "$impl expected $want, got {$res[$impl]['status']}" . (isset($res[$impl]['msg']) ? " ({$res[$impl]['msg']})" : '');
        }
        if ($res[$impl]['status'] === 'ok' && $res['jsonfuse']['status'] === 'ok' && $res[$impl]['out'] !== $res['jsonfuse']['out']) {
            $problems[] = "$impl output differs: " . trim($res[$impl]['out']) . ' vs ' . trim($res['jsonfuse']['out']);
        }
    }
    if ($problems !== []) { $fails++; }
    printf("%-40s %-9s %-9s %-9s %-9s %s\n", substr($c['name'], 0, 40), $res['jsonfuse']['status'], $res['php']['status'], $res['simdjson']['status'], $res['fastjson']['status'], $problems === [] ? 'ok' : 'FAIL: ' . implode('; ', $problems));
}

// Generated fixtures, full output comparison, same plans the benchmark measures.
foreach (['narrow', 'wide'] as $shape) {
    foreach ([90, 10, 1] as $pct) {
        $tmp = tempnam(sys_get_temp_dir(), 'jfg');
        passthru(escapeshellarg(PHP_BINARY) . ' -d memory_limit=-1 ' . escapeshellarg(__DIR__ . '/generate_ndjson.php') . " $shape 2000 $pct 4242 " . escapeshellarg($tmp));
        $spec = jf_plan_spec($shape);
        $c = ['input' => file_get_contents($tmp), 'fields' => $spec['fields'], 'where' => $spec['where']];
        unlink($tmp);
        $res = [];
        foreach (IMPLS as $impl) { $res[$impl] = run_impl($impl, $c); }
        $same = true;
        foreach (IMPLS as $impl) {
            $same = $same && $res[$impl]['status'] === 'ok' && $res[$impl]['counts']['records_emitted'] === $res['jsonfuse']['counts']['records_emitted'] && $res[$impl]['out'] === $res['jsonfuse']['out'];
        }
        printf("generated %-6s %2d%%: emitted=%d, byte-identical across impls: %s\n", $shape, $pct, $res['jsonfuse']['counts']['records_emitted'] ?? -1, $same ? 'yes' : 'NO');
        if (!$same) { $fails++; }
    }
}

echo $fails === 0 ? "\nEQUIV: PASS\n" : "\nEQUIV: FAIL ($fails)\n";
exit($fails === 0 ? 0 : 1);
