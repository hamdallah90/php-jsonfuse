--TEST--
jsonfuse 1.0: 20,000 seeded plans with nested field paths agree with the PHP reference on compile errors, output bytes, Stats, reason, getters and progress
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
 * Each case is a fresh random plan whose fields take paths of 1 to 8 segments from a small
 * segment table, so equal paths, shared prefixes and strict prefixes are frequent, with
 * defaults on some, invalid path forms on a few, and scalar where entries named by a first
 * segment now and then. Records are built along the plan's paths, then mutated: leaves of
 * every kind, intermediates replaced by non-objects or dropped, keys repeated at any level,
 * keys spelled with escapes, unreferenced keys and objects at every level. JfRef
 * (tests/jf_reference.inc) decides every case without the extension.
 */
const P_SEGS = ['a', 'b', 'c', 'id', 'x.y', '0', '', "\u{e9}"];
const P_NAMES = ['a', 'b', 'id', 'v', 'w', 'deep', '0', 'x.y', "\u{e9}"];
const P_TYPES = ['int64', 'float64', 'bool', 'string', 'int64?', 'float64?', 'bool?', 'string?'];
const P_DEFAULTS = ['int64' => [0, -7, PHP_INT_MAX], 'float64' => [0, 1.5, -0.0], 'bool' => [true, false], 'string' => ['', 'none', "q\"\\\x01"]];

function p_path(JfRng $r): array
{
    $roll = $r->int(100);
    $depth = $roll < 25 ? 1 : ($roll < 50 ? 2 : ($roll < 65 ? 3 : ($roll < 88 ? 4 + $r->int(4) : 8)));
    $path = [];
    for ($i = 0; $i < $depth; $i++) {
        $path[] = $r->pick(P_SEGS);
    }
    return $path;
}

function p_field(JfRng $r, string $name)
{
    $type = $r->pick(P_TYPES);
    if ($r->chance(12)) {
        return $type;
    }
    $fs = ['type' => $type, 'path' => p_path($r)];
    if ($r->chance(4)) {
        $fs['path'] = $r->pick([[], array_fill(0, 9, 'a'), ['a', 0], [0], ['a', null], ['a', 1.5], 'a', null, ['k' => 'a'], [1 => 'a'], ['a', "\xff"], [str_repeat('s', 1025)], [['a']]]);
    } elseif ($r->chance(10)) {
        unset($fs['path']);
    }
    if ($r->chance(40)) {
        $fs['default'] = $r->chance(15) && str_ends_with($type, '?') ? null : $r->pick(P_DEFAULTS[rtrim($type, '?')]);
    }
    return $fs;
}

function p_plan(JfRng $r): array
{
    $fields = [];
    $names = P_NAMES;
    for ($n = 1 + $r->int(5); $n > 0; $n--) {
        $i = $r->int(count($names));
        $fields[$names[$i]] = p_field($r, $names[$i]);
        array_splice($names, $i, 1);
    }
    $spec = ['fields' => $fields];
    for ($n = $r->int(100) < 70 ? 0 : 1 + $r->int(2); $n > 0; $n--) {
        $spec['where'][$r->pick(P_SEGS)] = $r->pick([1, 'x', true, null, 0]);
    }
    if ($r->chance(10)) {
        $spec['skipEmptyLines'] = true;
    }
    return $spec;
}

/** A value literal for a leaf of the given type, sometimes of another kind. */
function p_leaf(JfRng $r, ?string $type): string
{
    $ok = ['int64' => ['0', '7', '-1', '9223372036854775807'], 'float64' => ['1.5', '2', '-0.0', '1e3'], 'bool' => ['true', 'false'],
        'string' => ['"s"', '""', '"café"', '"q\"\\\\"']];
    $other = ['null', '[]', '{}', '[1]', '"5"', '5', 'true', '{"a":1}'];
    return $type === null || $r->chance(15) ? $r->pick($other) : $r->pick($ok[rtrim($type, '?')]);
}

/** Adds $path to the tree: an object is ['o', [[key, node], ...]], a scalar is ['s', text]. */
function p_insert(array &$node, array $path, string $leaf): void
{
    $seg = array_shift($path);
    foreach ($node[1] as $i => [$k, $child]) {
        if ($k === $seg) {
            if ($path !== [] && $child[0] === 'o') {
                p_insert($node[1][$i][1], $path, $leaf);
            }
            return;
        }
    }
    if ($path === []) {
        $node[1][] = [$seg, ['s', $leaf]];
        return;
    }
    $child = ['o', []];
    p_insert($child, $path, $leaf);
    $node[1][] = [$seg, $child];
}

/** Mutates every object of the tree: repeats, drops, non-object intermediates, junk keys, shuffles. */
function p_mutate(JfRng $r, array &$node, int $level): void
{
    foreach ($node[1] as $i => [$k, $child]) {
        if ($child[0] === 'o') {
            if ($r->chance(4)) {
                $node[1][$i][1] = ['s', $r->pick(['null', '[]', '[{"b":1}]', '"s"', '0', 'false'])];
            } else {
                p_mutate($r, $node[1][$i][1], $level + 1);
            }
        }
    }
    if ($node[1] !== [] && $r->chance(5)) {
        array_splice($node[1], $r->int(count($node[1])), 1);
    }
    if ($node[1] !== [] && $r->chance(3)) {
        $node[1][] = $r->pick($node[1]);
    }
    if ($r->chance(15)) {
        $node[1][] = [$r->pick(['z', 'a', 'b', 'k']), $r->chance(50) ? ['s', '1'] : ['o', [['a', ['s', '1']], ['a', ['s', '2']]]]];
    }
    if ($r->chance(25)) {
        for ($i = count($node[1]) - 1; $i > 0; $i--) {
            $j = $r->int($i + 1);
            [$node[1][$i], $node[1][$j]] = [$node[1][$j], $node[1][$i]];
        }
    }
}

function p_text(JfRng $r, array $node): string
{
    if ($node[0] === 's') {
        return $node[1];
    }
    $parts = [];
    foreach ($node[1] as [$k, $child]) {
        $key = json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($k !== '' && $r->chance(8)) {
            $key = '"' . jf_u_escape($k) . '"';
        }
        $parts[] = $key . ':' . p_text($r, $child);
    }
    return '{' . implode(',', $parts) . '}';
}

function p_record(JfRng $r, array $spec): string
{
    $root = ['o', []];
    foreach ($spec['fields'] as $name => $fs) {
        $path = is_array($fs) && isset($fs['path']) ? $fs['path'] : [(string)$name];
        $type = is_array($fs) ? $fs['type'] : $fs;
        if ($r->chance(is_array($fs) && array_key_exists('default', $fs) ? 70 : 92)) {
            p_insert($root, $path, p_leaf($r, $type));
        }
    }
    foreach ($spec['where'] ?? [] as $name => $w) {
        p_insert($root, [(string)$name], $r->chance(50) ? json_encode($w) : p_leaf($r, null));
    }
    p_mutate($r, $root, 1);
    return p_text($r, $root);
}

function p_ext(string $data, Plan $plan): array
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $got = jf_stats(Runner::process($in, $out, $plan));
    } catch (Throwable $e) {
        $got = !$e instanceof RecordException ? 'BAD ' . jf10_chain($e) : sprintf('%s %s field=%s line=%d record=%d offset=%d [%s]%s',
            get_class($e), $e->getReason(), $e->getField() === null ? 'null' : json_encode($e->getField()), $e->getInputLine(),
            $e->getRecordNumber(), $e->getByteOffset(), jf_stats($e->getProgress()), $e->getPrevious() === null ? '' : ' BAD previous');
    }
    $bytes = jf_out_bytes($out);
    fclose($in);
    fclose($out);
    return [$got, $bytes];
}

/** Output against the reference rows: exact bytes where the spec fixes them, else decoded, floats by bits. */
function p_same_output(array $ref, string $out): bool
{
    if ($ref['out'] !== null) {
        return $out === $ref['out'];
    }
    $lines = $out === '' ? [] : explode("\n", substr($out, 0, -1));
    if (!str_ends_with($out, "\n") || count($lines) !== count($ref['rows'])) {
        return false;
    }
    foreach ($lines as $i => $line) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            return false;
        }
        foreach ($ref['rows'][$i] as [$name, $kind, $v]) {
            $g = array_key_exists($name, $d) ? $d[$name] : 'absent';
            if (!($kind === 'float' ? is_float($g) && pack('E', $g) === pack('E', $v) : $g === $v)) {
                return false;
            }
        }
    }
    return true;
}

$rng = new JfRng(20261118);
$st = ['cases' => 0, 'planErrors' => 0, 'runs' => 0, 'accepted' => 0, 'rejected' => 0, 'lines' => 0];
$planReasons = $recordReasons = $depths = [];
$bad = [];
$badCount = 0;
// 20,000 runs of plans with a path of two or more segments (the path walk); flat plans (the
// flat walk) and plans that do not compile come on top.
$pathRuns = 0;
while ($pathRuns < 20000) {
    $st['cases']++;
    $spec = p_plan($rng);
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
    $deepest = max(array_map(fn($f) => count($f[5]), JfRef::plan($spec)['fields']));
    $pathRuns += $deepest >= 2;
    $depths[$deepest] = ($depths[$deepest] ?? 0) + 1;
    $data = '';
    for ($n = 1 + $rng->int(3); $n > 0; $n--) {
        $data .= ($rng->chance(3) ? "\n" : '') . p_record($rng, $spec) . "\n";
    }
    $ref = JfRef::run($data, $spec);
    [$got, $out] = p_ext($data, $plan);
    $wantText = JfRef::render($ref);
    if (str_contains($wantText, 'bytesWritten=?')) {
        $got = preg_replace('/bytesWritten=\d+(\]?)$/', 'bytesWritten=?$1', $got);
    }
    $st['runs']++;
    $st[$ref['ok'] ? 'accepted' : 'rejected']++;
    $st['lines'] += count($ref['rows']);
    if (!$ref['ok']) {
        $recordReasons[$ref['reason']] = ($recordReasons[$ref['reason']] ?? 0) + 1;
    }
    if (($got !== $wantText || !p_same_output($ref, $out)) && $badCount++ < 3) {
        $bad[] = ['data ' . bin2hex($data) . ' plan ' . var_export($spec, true), $wantText . ' ' . bin2hex($ref['out'] ?? '?'), $got . ' ' . bin2hex($out)];
    }
}
ksort($planReasons);
ksort($recordReasons);
ksort($depths);
$show = fn(array $a) => implode(' ', array_map(fn($k, $v) => "$k=$v", array_keys($a), $a));
echo 'plans: ', $st['cases'], ', compile errors: ', $st['planErrors'], ', runs: ', $st['runs'], ' (accepted ', $st['accepted'], ', rejected ', $st['rejected'], '), output lines: ', $st['lines'], "\n";
echo 'plan errors by reason: ', $show($planReasons), "\n";
echo 'record errors by reason: ', $show($recordReasons), "\n";
echo 'runs by deepest path: ', $show($depths), "\n";
echo 'disagreements with the reference: ', $badCount, "\n";
foreach ($bad as [$case, $w, $g]) {
    echo "  $case\n    want: $w\n    got:  $g\n";
}
?>
--EXPECT--
plans: 37251, compile errors: 12768, runs: 24483 (accepted 6001, rejected 18482), output lines: 12953
plan errors by reason: duplicate_name=1302 invalid_name=589 invalid_path=10877
record errors by reason: blank_line=863 duplicate_key=4523 missing_field=5239 null_field=345 path_not_object=2448 type_mismatch=3973 where_not_scalar=1091
runs by deepest path: 1=4483 2=3778 3=3237 4=1524 5=1770 6=1987 7=2223 8=5481
disagreements with the reference: 0
