--TEST--
jsonfuse 1.0: 20,000 seeded plans with array field specs and defaults of every type agree with the PHP reference on compile errors, output bytes, Stats, reason, getters and progress
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\Plan;
use JsonFuse\PlanException;
use JsonFuse\RecordException;
use JsonFuse\Runner;

/*
 * Each case is a fresh random plan, mixing the string and array field forms, defaults that
 * match their type and defaults that do not (int float64 defaults around 2^53 and 2^63
 * included), unknown keys and bad types ('path' => 1 is an invalid path), and records in which each referenced key is
 * missing, null, of its type or of another type. JfRef (tests/jf_reference.inc) decides
 * every case without the extension.
 */
const D_NAMES = ['a', 'b', 'id', 's', 'n', 'x', 'tier', 'amount', "caf\u{e9}", '', '0', '123', 'q"', "t\tb"];
const D_TYPES = ['int64', 'float64', 'bool', 'string', 'int64?', 'float64?', 'bool?', 'string?'];

function d_defaults(): array
{
    static $d = null;
    return $d ??= [
        'int64' => [0, 1, -1, -5, 42, PHP_INT_MAX, PHP_INT_MIN, 9007199254740993],
        'float64' => [0.0, -0.0, 1.5, 2.0, 0.1, 1e300, 5e-324, 123.456, 0, 1, -7, 9007199254740992, -9007199254740992,
            1152921504606846976, 9223372036854774784, PHP_INT_MIN, 9007199254740994],
        'bool' => [true, false],
        'string' => ['', 'free', "caf\u{e9}", "q\"\\\n\r\t\x08\x0c\x01\x1f/\x7f", "\u{2028}\u{1F600}", "a\0b", str_repeat("\x01", 200),
            str_repeat('z', 1024)],
        'bad' => [9007199254740993, -9007199254740993, 1152921504606846977, 9223372036854774785, PHP_INT_MAX, PHP_INT_MIN + 1,
            NAN, INF, -INF, str_repeat('z', 1025), "ok\xff", "\xed\xa0\x80", [], ['x'], '5', 5.0, 1, 'true', null],
    ];
}

function d_default(JfRng $r, string $type)
{
    $t = rtrim($type, '?');
    $roll = $r->int(100);
    if (str_ends_with($type, '?') && $roll < 30) {
        return null;
    }
    if ($roll < 88) {
        return $r->pick(d_defaults()[$t]);
    }
    if ($roll < 94) {
        return $r->pick(d_defaults()['bad']);
    }
    return $r->pick(d_defaults()[$r->pick(['int64', 'float64', 'bool', 'string'])]);
}

function d_field(JfRng $r)
{
    $type = $r->pick(D_TYPES);
    $roll = $r->int(1000);
    if ($roll < 250) {
        return $type;
    }
    if ($roll < 280) {
        return $r->pick(['INT64', 'int64??', 5, null, ['int64'], ['type' => 'Int64'], ['type' => 5], ['type' => null], [], ['default' => 1]]);
    }
    $fs = ['type' => $type];
    if ($r->chance(65)) {
        $fs['default'] = d_default($r, $type);
        if ($r->chance(30)) {
            $fs = ['default' => $fs['default'], 'type' => $type];
        }
    }
    if ($roll < 300) {
        $fs[$r->pick(['Default', 'path', 'nullable', 'TYPE'])] = 1;
    } elseif ($roll < 310) {
        $fs[] = 'x';
    }
    return $fs;
}

function d_plan(JfRng $r): array
{
    $fields = [];
    $names = D_NAMES;
    for ($n = 1 + $r->int(6); $n > 0; $n--) {
        $i = $r->int(count($names));
        $fields[$names[$i]] = d_field($r);
        array_splice($names, $i, 1);
    }
    $spec = ['fields' => $fields];
    for ($n = $r->int(3); $n > 0; $n--) {
        $name = $r->chance(60) ? (string)$r->pick(array_keys($fields)) : $r->pick(D_NAMES);
        $spec['where'][$name] = $r->pick(['a', 'free', '', 5, 0, -5, 1.5, 0.0, true, false, null, PHP_INT_MAX, 7]);
    }
    if ($r->chance(12)) {
        $spec['maxRecordBytes'] = $r->pick([8, 60, 200]);
    }
    if ($r->chance(20)) {
        $spec['skipEmptyLines'] = true;
    }
    return $spec;
}

/** A key as JSON text; sometimes its first letter, or its non-ASCII letters, as \u escapes. */
function d_spell(JfRng $r, string $key): string
{
    $plain = json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($r->chance(85) || !preg_match('/^[a-z0-9]/i', $key)) {
        return $plain;
    }
    return $key === "caf\u{e9}" ? '"' . jf_u_escape($key) . '"' : '"' . sprintf('\u%04x', ord($key[0])) . substr($plain, 2);
}

function d_value(JfRng $r, ?string $type): string
{
    $ok = [
        'int64' => ['0', '1', '-1', '7', '-0', '42', '9223372036854775807', '-9223372036854775808', '9007199254740993'],
        'float64' => ['0', '-0', '1.5', '-0.0', '1e3', '5', '2.5e-3', '1e-400', '9007199254740993', '18446744073709551616', '0.1'],
        'bool' => ['true', 'false'],
        'string' => ['"a"', '""', '"free"', '"café"', "\"caf\u{e9}\"", '"\u0001"', '"q\"\\\\"', '"😀"', '"a\u0000b"'],
    ];
    $other = ['null', '[]', '{}', '[1]', '"5"', '5', '5.0', '1.5', 'true', '"x"', '1e400', '18446744073709551616', '"free"'];
    if ($type === null || $r->chance(4)) {
        return $r->pick($other);
    }
    return $r->pick($ok[rtrim($type, '?')]);
}

function d_record(JfRng $r, array $spec): string
{
    $refs = [];
    foreach ($spec['fields'] as $name => $fs) {
        $refs[(string)$name] = is_array($fs) ? [$fs['type'], array_key_exists('default', $fs)] : [$fs, false];
    }
    foreach ($spec['where'] ?? [] as $name => $w) {
        $refs[(string)$name] ??= [null, false];
    }
    $members = [];
    foreach ($refs as $name => [$type, $hasDefault]) {
        $roll = $r->int(100);
        if ($roll < ($hasDefault ? 45 : 6)) {
            continue;
        }
        $v = $roll < ($hasDefault ? 50 : 10) ? 'null' : d_value($r, $type);
        if (array_key_exists($name, $spec['where'] ?? []) && $r->chance(40)) {
            $w = $spec['where'][$name];
            $v = is_string($w) ? json_encode($w) : ($w === null ? 'null' : var_export($w, true));
        }
        $members[] = [d_spell($r, (string)$name), $v];
    }
    if ($members !== [] && $r->chance(3)) {
        $members[] = $r->pick($members);
    }
    for ($n = $r->int(3); $n > 0; $n--) {
        $members[] = [$r->pick(['"junk"', '"z"', '"Tier"', '"pad"']), $r->pick(['1', '"' . str_repeat('p', $r->int(60)) . '"', '[1,{"a":2}]', 'null'])];
    }
    if ($r->chance(30)) {
        for ($i = count($members) - 1; $i > 0; $i--) {
            $j = $r->int($i + 1);
            [$members[$i], $members[$j]] = [$members[$j], $members[$i]];
        }
    }
    $parts = [];
    foreach ($members as [$k, $v]) {
        $parts[] = $k . $r->pick([':', ':', ' : ']) . $v;
    }
    $t = '{' . implode($r->pick([',', ',', ', ']), $parts) . '}';
    if ($r->chance(3)) {
        $t = $r->pick([substr($t, 0, -1), '[' . $t . ']', $t . 'x', '5', "{\"a\":\"\xff\"}"]);
    }
    return $t;
}

function d_data(JfRng $r, array $spec): string
{
    $data = '';
    for ($n = 1 + $r->int(4); $n > 0; $n--) {
        if ($r->chance(3)) {
            $data .= "\n";
        }
        $data .= d_record($r, $spec) . ($n === 1 && $r->chance(20) ? '' : ($r->chance(15) ? "\r\n" : "\n"));
    }
    return $data;
}

/** The extension's outcome in JfRef::render()'s form, and its output bytes. */
function d_ext(string $data, Plan $plan): array
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $got = jf_stats(Runner::process($in, $out, $plan));
    } catch (Throwable $e) {
        if (!$e instanceof RecordException) {
            $got = 'BAD ' . jf10_chain($e);
        } else {
            $got = sprintf('%s %s field=%s line=%d record=%d offset=%d [%s]', get_class($e), $e->getReason(),
                $e->getField() === null ? 'null' : json_encode($e->getField()), $e->getInputLine(), $e->getRecordNumber(),
                $e->getByteOffset(), jf_stats($e->getProgress()));
            if ($e->getPrevious() !== null) {
                $got .= ' BAD previous';
            }
        }
    }
    $bytes = jf_out_bytes($out);
    fclose($in);
    fclose($out);
    return [$got, $bytes];
}

/** Output lines against the reference rows: exact bytes where the spec fixes them, else decoded, floats by bits. */
function d_same_output(array $ref, string $out): bool
{
    if ($ref['out'] !== null) {
        return $out === $ref['out'];
    }
    $lines = $out === '' ? [] : explode("\n", substr($out, 0, -1));
    if (!str_ends_with($out, "\n") || count($lines) !== count($ref['rows'])) {
        return false;
    }
    foreach ($lines as $i => $line) {
        if ($ref['lines'][$i] !== null && $ref['lines'][$i] !== $line . "\n") {
            return false;
        }
        $d = json_decode($line, true);
        if (!is_array($d) || array_map('strval', array_keys($d)) !== array_map(fn($c) => (string)$c[0], $ref['rows'][$i])) {
            return false;
        }
        foreach ($ref['rows'][$i] as [$name, $kind, $v]) {
            $g = $d[$name];
            if (!($kind === 'float' ? is_float($g) && pack('E', $g) === pack('E', $v) : $g === $v)) {
                return false;
            }
        }
    }
    return true;
}

/** Defaults the reference emitted in this run, by kind: matched records' missing keys of defaulted fields. */
function d_count_defaults(string $data, array $spec, array $ref, array &$tally): void
{
    $plan = JfRef::plan($spec);
    $left = count($ref['rows']);
    foreach (explode("\n", $data) as $line) {
        $line = preg_replace('/\r$/', '', $line);
        if ($left === 0 || $line === '' || strlen($line) > $plan['cap']) {
            continue;
        }
        $v = JfRef::record($line, $plan);
        if (!$v['ok'] || !$v['match']) {
            continue;
        }
        $left--;
        $keys = array_flip(JfRef::rootKeys($line));
        foreach ($plan['fields'] as [$name, $type, , $has, $default]) {
            if ($has && !isset($keys[$name])) {
                $tally[$default === null ? 'null' : $type]++;
            }
        }
    }
}

$rng = new JfRng(20261117);
$st = ['cases' => 0, 'planErrors' => 0, 'runs' => 0, 'accepted' => 0, 'rejected' => 0, 'lines' => 0, 'exact' => 0];
$planReasons = [];
$recordReasons = [];
$tally = ['int64' => 0, 'float64' => 0, 'bool' => 0, 'string' => 0, 'null' => 0];
$bad = [];
$badCount = 0;
// 20,000 plans that compile, each run over its own records; plans that do not compile come on top.
while ($st['runs'] < 20000) {
    $st['cases']++;
    $spec = d_plan($rng);
    $want = JfRef::compileError($spec);
    try {
        $plan = Plan::compile($spec);
        $got = null;
    } catch (PlanException $e) {
        $got = [$e->getReason(), $e->getOption(), $e->getField()];
    }
    if ($want !== null || $got !== null) {
        $st['planErrors']++;
        $planReasons[$want[0] ?? 'none'] = ($planReasons[$want[0] ?? 'none'] ?? 0) + 1;
        if ($want !== $got && $badCount++ < 3) {
            $bad[] = ['plan ' . var_export($spec, true), json_encode($want), json_encode($got)];
        }
        continue;
    }
    $data = d_data($rng, $spec);
    $ref = JfRef::run($data, $spec);
    [$got, $out] = d_ext($data, $plan);
    $wantText = JfRef::render($ref);
    if (str_contains($wantText, 'bytesWritten=?')) {
        $got = preg_replace('/bytesWritten=\d+(\]?)$/', 'bytesWritten=?$1', $got);
    }
    $st['runs']++;
    $st[$ref['ok'] ? 'accepted' : 'rejected']++;
    $st['lines'] += count($ref['rows']);
    $st['exact'] += count(array_filter($ref['lines'], 'is_string'));
    if (!$ref['ok']) {
        $recordReasons[$ref['reason']] = ($recordReasons[$ref['reason']] ?? 0) + 1;
    }
    if (($got !== $wantText || !d_same_output($ref, $out)) && $badCount++ < 3) {
        $bad[] = ['data ' . bin2hex($data) . ' plan ' . var_export($spec, true), $wantText . ' ' . bin2hex($ref['out'] ?? '?'), $got . ' ' . bin2hex($out)];
    }
    d_count_defaults($data, $spec, $ref, $tally);
}
ksort($planReasons);
ksort($recordReasons);
echo 'plans: ', $st['cases'], ', compile errors: ', $st['planErrors'], ', runs: ', $st['runs'], ' (accepted ', $st['accepted'], ', rejected ', $st['rejected'], '), output lines: ', $st['lines'], ', of them byte-compared: ', $st['exact'], "\n";
echo 'plan errors by reason: ', implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($planReasons), $planReasons)), "\n";
echo 'record errors by reason: ', implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($recordReasons), $recordReasons)), "\n";
echo 'defaults emitted by kind: ', implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($tally), $tally)), "\n";
echo 'disagreements with the reference: ', $badCount, "\n";
foreach ($bad as [$case, $w, $g]) {
    echo "  $case\n    want: $w\n    got:  $g\n";
}
echo 'covers every default kind 300 times and every field step: ',
    var_export(min($tally) >= 300 && min($recordReasons['missing_field'] ?? 0, $recordReasons['null_field'] ?? 0, $recordReasons['type_mismatch'] ?? 0) >= 200, true), "\n";
?>
--EXPECT--
plans: 28317, compile errors: 8317, runs: 20000 (accepted 6910, rejected 13090), output lines: 11011, of them byte-compared: 9736
plan errors by reason: invalid_path=429 invalid_type=2239 invalid_value=3328 unknown_option=2321
record errors by reason: blank_line=847 duplicate_key=871 invalid_json=598 missing_field=2507 not_object=373 null_field=1838 record_too_large=1107 type_mismatch=4145 where_not_scalar=804
defaults emitted by kind: int64=1487 float64=1339 bool=1371 string=1412 null=1052
disagreements with the reference: 0
covers every default kind 300 times and every field step: true
