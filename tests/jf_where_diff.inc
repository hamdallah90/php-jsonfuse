<?php
/*
 * JsonFuse where differential: a seeded generator of plans
 * and records for the where operators ('=', 'in', number and string ranges), where paths and their
 * compile rules, and the comparison of the loaded JsonFuse API with JfRef (jf_reference.inc) on
 * output bytes, Stats, exception class, reason, getters and progress, and on PlanException reason,
 * getOption() and getField(). Not a test itself; tests/148_where_differential.phpt runs
 * it, and so does this file as a script.
 *
 * How to run, from the repository root after `phpize && ./configure && make`:
 *
 *   php -d extension=$PWD/modules/jsonfuse.so tests/jf_where_diff.inc        # every form: >= 20,000 runs against the extension
 *   php -d extension=$PWD/modules/jsonfuse.so tests/jf_where_diff.inc scalar # scalar where entries only
 *   php tests/jf_where_diff.inc phpts                                        # the reference alone against tests/140 to 149
 *   php tests/jf_where_diff.inc expect                                       # the summary from the reference alone (148's EXPECT)
 *
 * Exit status 0 means zero disagreements (phpts: every file's output equals its EXPECT). `phpts`
 * and `expect` must run WITHOUT the extension: they load jf_refext.inc, which puts the reference
 * behind the same API. Each disagreement prints the plan, the input as hex, and both outcomes.
 *
 * Fault order. A plan is
 * compared on the one fault reported, so the gate depends on that order wherever a generated
 * plan has several faults. Plans that are not injected can hold several faulty fields entries,
 * bad where paths (w_path makes bad paths for both sections) and path conflicts at once. The
 * gate depends on these rules for them: per-entry checks in declared order, fields before
 * where; a path's shape before its segments, segments in order; cross-entry conflicts only once
 * every entry passed, in declared order, naming the later entry. Fields follow the same
 * rules. Every top-level fault, and every where name, unknown-key, operand,
 * mixed-kind, 257-member, duplicate and prefix fault, is injected alone into a plan that
 * compiles, and the reference must report the injected reason ("injector disagreements"). So
 * the gate does not depend on: where top-level options fall among the entry checks, a path
 * before the operators of its entry, the in count before its members, operators in key order,
 * or a where per-entry fault winning over a fields-fields conflict (0 of the 21,256 failing
 * plans of the seeded run). 149_plan_fault_order.phpt fixes those.
 */

require_once __DIR__ . '/jf10_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\Plan;
use JsonFuse\PlanException;
use JsonFuse\RecordException;
use JsonFuse\Runner;

const W_SEGS = ['a', 'b', 'c', 'id', 'x.y', '0', '', "\u{e9}"];
const W_FIELD_NAMES = ['a', 'b', 'id', 'v', 'w', 'deep', '0', 'x.y', "\u{e9}"];
const W_WHERE_NAMES = ['a', 'b', 'c', 'v', 'w', 'n', 's', 't', '0', "\u{e9}", 'id', 'x.y'];
const W_TYPES = ['int64', 'float64', 'bool', 'string', 'int64?', 'float64?', 'bool?', 'string?'];
const W_DEFAULTS = ['int64' => [0, -7, PHP_INT_MAX], 'float64' => [0, 1.5, -0.0], 'bool' => [true, false], 'string' => ['', 'none', "q\"\\\x01"]];
/** Record number literals: int64, uint64 and RAW edges, 2^53 +- 1, reals at 2^53, 2^63, 2^64, zeros, subnormals, overflow. */
const W_NUM_LITS = ['0', '-0', '1', '-1', '4', '5', '6', '-5', '7', '9007199254740991', '9007199254740992', '9007199254740993',
    '9007199254740994', '-9007199254740993', '9223372036854775806', '9223372036854775807', '9223372036854775808',
    '9223372036854775809', '18446744073709551614', '18446744073709551615', '18446744073709551616', '-9223372036854775807',
    '-9223372036854775808', '-9223372036854775809', '123456789012345678901234567890', '0.0', '-0.0', '0.5', '4.5', '5.0', '5e0',
    '-5.5', '0.1', '9007199254740992.0', '9007199254740993.0', '9007199254740994.0', '9223372036854775807.0',
    '9223372036854775808.0', '9.223372036854775808e18', '-9.223372036854775808e18', '9223372036854774784.0',
    '1.8446744073709552e19', '18446744073709551615.0', '18446744073709549568.0', '1e300', '-1e300', '1E21', '1e-400', '-1e-400',
    '5e-324', '-5e-324', '1.7976931348623157e308', '1e400', '-1e400', '2.5e-3'];
const W_OTHER_LITS = ['true', 'false', 'null', '[]', '{}', '[1]', '{"a":1}'];

/** Number operands: int64 edges, 2^53 +- 1, floats at 2^53, 2^63, 2^64 and just below, zeros, subnormals. */
function w_num_ops(): array
{
    static $p = null;
    return $p ??= [0, 1, -1, 5, -5, 7, 9007199254740991, 9007199254740992, 9007199254740993, -9007199254740993, PHP_INT_MAX,
        PHP_INT_MAX - 1, PHP_INT_MIN, PHP_INT_MIN + 1, 0.0, -0.0, 0.5, 4.5, 5.0, -5.5, 0.1, 9007199254740992.0, 9007199254740994.0,
        -9007199254740992.0, 9223372036854775808.0, -9223372036854775808.0, 9223372036854774784.0, 18446744073709551616.0,
        18446744073709549568.0, 1e300, -1e300, 5e-324, -5e-324, 1.7976931348623157e308, 1e21];
}

/** String operands and record strings: shared prefixes, bytes above 0x7F, NUL, the timestamp counterexamples, 1023 to 1025 bytes. */
function w_strings(bool $operand): array
{
    static $p = null;
    $p ??= ['', 'a', 'ab', 'abc', 'abd', 'b', 'B', 'z', "\u{e9}", "\u{e9}a", "a\0", "\x7f", "\u{80}", "\u{E000}", "\u{FFFF}",
        "\u{10000}", "\u{1F600}", 'free', '5', 'x', '2026-11-09', '2026-11-10', '2026-11-09T10:00:00Z', '2026-11-09T10:00:00.5Z',
        '2026-11-09T12:00:00+02:00', '2026-11-09T09:59:59Z', str_repeat('m', 1023), str_repeat('m', 1024), str_repeat('m', 1025)];
    return $operand ? array_slice($p, 0, -1) : $p;
}

function w_scalars(): array
{
    static $p = null;
    return $p ??= [1, 0, -1, 5, 1.0, 0.0, -0.0, 5.0, 0.5, 'a', 'ab', '', '1', '5', "\u{e9}", "a\0", true, false, null, PHP_INT_MAX,
        PHP_INT_MIN, 9007199254740993, 9007199254740992.0, 9223372036854775808.0, 1e300, 'free', 'x', '2026-11-09'];
}

/** A float as a JSON real that reads back to the same double, without ini or locale dependence. */
function w_real_text(float $f): string
{
    return $f == 0.0 ? (fdiv(1, $f) < 0 ? '-0.0' : '0.0') : str_replace(',', '.', sprintf('%.17e', $f));
}

/** A decoded string as a JSON string: plain, with every non-ASCII code point escaped, or with an 'a' escaped. */
function w_str_text(JfRng $r, string $s): string
{
    $t = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $roll = $r->int(100);
    if ($roll < 20) {
        return jf_u_escape($t);
    }
    return $roll < 28 ? preg_replace('/a/', 'a', $t, 1) : $t;
}

/** A scalar operand as a record literal of the same kind and value. */
function w_lit_of(JfRng $r, $x): string
{
    return match (true) {
        is_int($x) => $r->chance(80) ? (string)$x : (string)$x . '.0',
        is_float($x) => w_real_text($x),
        is_string($x) => w_str_text($r, $x),
        $x === null => 'null',
        default => $x ? 'true' : 'false',
    };
}

function w_path(JfRng $r): array
{
    $roll = $r->int(100);
    $depth = $roll < 30 ? 1 : ($roll < 60 ? 2 : ($roll < 75 ? 3 : ($roll < 92 ? 4 + $r->int(4) : 8)));
    $path = [];
    for ($i = 0; $i < $depth; $i++) {
        $path[] = $r->pick(W_SEGS);
    }
    return $path;
}

/** A fields entry in the 136 style: string or array form, paths of 1 to 8 segments, defaults, a few invalid path forms. */
function w_field(JfRng $r)
{
    $type = $r->pick(W_TYPES);
    if ($r->chance(15)) {
        return $type;
    }
    $fs = ['type' => $type, 'path' => w_path($r)];
    if ($r->chance(2)) {
        $fs['path'] = $r->pick([[], array_fill(0, 9, 'a'), ['a', 0], [0], ['a', null], 'a', ['k' => 'a'], ['a', "\xff"], [str_repeat('s', 1025)]]);
    } elseif ($r->chance(10)) {
        unset($fs['path']);
    }
    if ($r->chance(35)) {
        $fs['default'] = $r->chance(15) && str_ends_with($type, '?') ? null : $r->pick(W_DEFAULTS[rtrim($type, '?')]);
    }
    return $fs;
}

/** A valid where entry; $scalarOnly limits it to the scalar form. Returns [entry, form tags]. */
function w_entry(JfRng $r, array $fieldPaths, bool $scalarOnly): array
{
    if ($scalarOnly || $r->chance(12)) {
        return [$r->pick(w_scalars()), ['scalar']];
    }
    $roll = $r->int(100);
    $e = [];
    $tags = [];
    $range = fn (bool $str) => $str ? $r->pick(w_strings(true)) : $r->pick(w_num_ops());
    if ($roll < 16) {
        $e['='] = $r->pick(w_scalars());
        $tags[] = 'eq';
    } elseif ($roll < 32) {
        $n = $r->chance(4) ? 256 : 1 + $r->int(6);
        $pool = $r->chance(50) ? w_scalars() : ($r->chance(50) ? w_num_ops() : w_strings(true));
        for ($i = 0; $i < $n; $i++) {
            $e['in'][] = $n === 256 && $i < 200 ? $i * 7 - 600 : $r->pick($pool);
        }
        $tags[] = $n === 256 ? 'in256' : 'in';
    } else {
        $str = $roll >= 72;
        // a lower and an upper bound, ordered (approximately, which is enough to make matches likely)
        [$lo, $hi] = [$range($str), $range($str)];
        if ($str ? strcmp($lo, $hi) > 0 : (float)$lo > (float)$hi) {
            [$lo, $hi] = [$hi, $lo];
        }
        $shape = $r->int(100);
        if ($shape < 90) {
            if ($shape < 65) {
                $e[$r->pick(['>', '>='])] = $lo;
            }
            if ($shape >= 30) {
                $e[$r->pick(['<', '<='])] = $hi;
            }
        } else {
            foreach (['>', '>=', '<', '<='] as $op) {
                $e[$op] = $range($str);
            }
        }
        $tags[] = $str ? 'str' : 'num';
        if ($r->chance(20)) {
            // '=' or 'in' beside ranges, of either kind: ANDed, contradictory entries compile
            $mixed = $r->chance(50) ? $r->pick(w_scalars()) : $range(!$str);
            if ($r->chance(50)) {
                $e['='] = $mixed;
            } else {
                $e['in'] = [$mixed, $r->pick(w_scalars())];
            }
            $tags[] = 'mixed';
        }
    }
    $roll = $r->int(100);
    if ($roll < 45) {
        $e['path'] = $fieldPaths !== [] && $r->chance(25) ? $r->pick($fieldPaths) : w_path($r);
        $tags[] = 'path';
    }
    if ($r->chance(30)) {
        // key order inside an entry does not matter
        $keys = array_keys($e);
        for ($i = count($keys) - 1; $i > 0; $i--) {
            $j = $r->int($i + 1);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }
        $e = array_combine($keys, array_map(fn ($k) => $e[$k], $keys));
    }
    return [$e, $tags];
}

function w_plan(JfRng $r, bool $scalarOnly): array
{
    $fields = [];
    $names = W_FIELD_NAMES;
    for ($n = 1 + $r->int(4); $n > 0; $n--) {
        $i = $r->int(count($names));
        $fields[$names[$i]] = w_field($r);
        array_splice($names, $i, 1);
    }
    $spec = ['fields' => $fields];
    $paths = [];
    foreach ($fields as $name => $fs) {
        if (is_array($fs) && is_array($fs['path'] ?? null)) {
            $paths[] = $fs['path'];
        }
    }
    $names = W_WHERE_NAMES;
    for ($n = $scalarOnly && $r->chance(40) ? 0 : 1 + $r->int(3); $n > 0; $n--) {
        $i = $r->int(count($names));
        [$spec['where'][$names[$i]]] = w_entry($r, $paths, $scalarOnly);
        array_splice($names, $i, 1);
    }
    if ($r->chance(10)) {
        $spec['skipEmptyLines'] = true;
    }
    if ($r->chance(5)) {
        $spec['maxRecordBytes'] = $r->pick([40, 2000]);
    }
    return $spec;
}

/** The form tags of a compiled plan's where entries. */
function w_tags(array $spec): array
{
    $tags = [];
    $fieldPaths = array_map(fn ($f) => $f[5], JfRef::plan($spec)['fields']);
    foreach (JfRef::plan($spec)['where'] as [$name, $ops, $path]) {
        $v = $spec['where'][$name];
        if (!is_array($v)) {
            $tags['scalar'] = 1;
            continue;
        }
        $kinds = [];
        foreach ($ops as [$op, $operand]) {
            $kinds[] = $op === '=' ? 'eq' : ($op === 'in' ? (count($operand) === 256 ? 'in256' : 'in') : (is_string($operand) ? 'str' : 'num'));
        }
        foreach (array_unique($kinds) as $k) {
            $tags[$k] = 1;
        }
        if (count(array_unique($kinds)) > 1) {
            $tags['mixed'] = 1;
        }
        if (array_key_exists('path', $v)) {
            $tags[count($path) > 1 ? 'nested path' : 'one-segment path'] = 1;
        }
        if (in_array($path, $fieldPaths, true)) {
            $tags['shared with a field'] = 1;
        }
    }
    return $tags;
}

/**
 * One fault injected alone into $spec, which compiles: [spec, [reason, option, field], label].
 * $scalarOnly keeps to faults without array-form where entries.
 */
function w_inject(JfRng $r, array $spec, bool $scalarOnly): array
{
    $where = $spec['where'] ?? [];
    $wnames = array_map('strval', array_keys($where));
    $paths = [];
    foreach (JfRef::plan($spec)['fields'] as $f) {
        $paths[] = $f[5];
    }
    foreach (JfRef::plan($spec)['where'] as $w) {
        $paths[] = $w[2];
    }
    $kinds = ['top', 'top', 'scalar', 'name'];
    if (!$scalarOnly) {
        array_push($kinds, 'unknown', 'novalue', 'operand', 'operand', 'operand', 'mixed', 'in257', 'path', 'segment', 'dup', 'prefix', 'prefix');
    } elseif (array_filter($paths, fn ($p) => count($p) > 1) !== []) {
        $kinds[] = 'prefix';
    }
    if (count($where) < 16) {
        $kinds[] = 'where17';
    }
    $kind = $r->pick($kinds);
    // the entry a where-entry fault goes into: an existing one (keeping its path) or a new one
    $target = $wnames !== [] && $r->chance(60) ? $r->pick($wnames) : 'zq';
    $keep = $target !== 'zq' && is_array($where[$target]) && array_key_exists('path', $where[$target]) ? ['path' => $where[$target]['path']] : [];
    $put = function ($entry) use (&$spec, $target) {
        $spec['where'][$target] = $entry;
        return $spec;
    };
    switch ($kind) {
        case 'top':
            $t = $r->pick([
                ['Fields', 1, 'unknown_option', null], ['maxrecordbytes', 10, 'unknown_option', null], [0, 1, 'unknown_option', null],
                ['fields', 'a', 'invalid_option', 'fields'], ['fields', [], 'invalid_option', 'fields'], ['fields', 5, 'invalid_option', 'fields'],
                ['where', 'a', 'invalid_option', 'where'], ['where', null, 'invalid_option', 'where'], ['where', 5, 'invalid_option', 'where'],
                ['output', 'csv', 'invalid_option', 'output'], ['output', 'NDJSON', 'invalid_option', 'output'],
                ['maxRecordBytes', 0, 'invalid_option', 'maxRecordBytes'], ['maxRecordBytes', 67108865, 'invalid_option', 'maxRecordBytes'],
                ['maxRecordBytes', '1024', 'invalid_option', 'maxRecordBytes'], ['maxRecordBytes', 1024.0, 'invalid_option', 'maxRecordBytes'],
                ['skipEmptyLines', 1, 'invalid_option', 'skipEmptyLines'], ['skipEmptyLines', 'true', 'invalid_option', 'skipEmptyLines'],
                ['fields', null, 'missing_option', 'fields'], ['fields', 129, 'too_many', 'fields'],
            ]);
            [$key, $val, $reason, $option] = $t;
            if ($reason === 'missing_option') {
                unset($spec['fields']);
            } elseif ($reason === 'too_many') {
                $spec['fields'] = [];
                for ($i = 0; $i < 129; $i++) {
                    $spec['fields']["f$i"] = 'int64';
                }
            } else {
                $spec[$key] = $val;
            }
            return [$spec, [$reason, $option, null], "$reason " . ($option ?? 'top-level key')];
        case 'where17':
            for ($i = 0; count($spec['where'] ?? []) < 17; $i++) {
                $spec['where']["zz$i"] = 1;
            }
            return [$spec, ['too_many', 'where', null], 'too_many where (17 entries)'];
        case 'name':
            $spec['where'][$r->pick(["\xff", "ok\xc0\xaf", "\xed\xa0\x80", str_repeat('n', 1025)])] = 1;
            return [$spec, ['invalid_name', 'where', null], 'invalid_name where name'];
        case 'scalar':
            return [$put($r->pick([NAN, INF, -INF, "ok\xff", "\xed\xa0\x80", str_repeat('v', 1025), new stdClass()])), ['invalid_value', 'where', $target], 'invalid_value scalar'];
        case 'unknown':
            $bad = $r->pick(['!=', '==', ' =', 'IN', 'Path', 'type', 'default', 0]);
            $e = is_array($where[$target] ?? null) ? $where[$target] : ['=' => 1];
            $e[$bad] = $r->chance(50) ? 1 : NAN;
            return [$put($r->chance(10) ? [1, 2] : $e), ['unknown_option', 'where', $target], 'unknown_option entry key'];
        case 'novalue':
            return [$put($keep), ['invalid_value', 'where', $target], 'invalid_value no operator'];
        case 'operand':
            $op = $r->pick(['=', 'in', '>', '>=', '<', '<=']);
            $bad = match ($op) {
                '=' => $r->pick([NAN, INF, [1], [], new stdClass(), "\xff", str_repeat('s', 1025)]),
                'in' => $r->pick([[], ['a' => 1], [1 => 1], 5, null, 'a', [[1]], [1, NAN], [INF], ['ok', "\xff"], [str_repeat('m', 1025)], [new stdClass()]]),
                default => $r->pick([NAN, INF, -INF, true, false, null, [1], new stdClass(), "ok\xff", str_repeat('o', 1025)]),
            };
            return [$put($keep + [$op => $bad]), ['invalid_value', 'where', $target], "invalid_value '$op' operand"];
        case 'mixed':
            $e = $keep + [$r->pick(['>', '>=']) => $r->pick(w_num_ops()), $r->pick(['<', '<=']) => $r->pick(w_strings(true))];
            return [$put($e), ['invalid_value', 'where', $target], 'invalid_value number and string ranges'];
        case 'in257':
            $m = range(0, 255);
            $m[] = $r->pick(w_scalars());
            return [$put($keep + ['in' => $m]), ['too_many', 'where', null], 'too_many 257 in members'];
        case 'path':
            $bad = $r->pick([[], array_fill(0, 9, 's'), ['a', 0], [0], ['a', null], ['a', 1.5], 'a', null, ['k' => 'a'], [1 => 'a'], [['a']], 5, true]);
            return [$put(['path' => $bad, '>' => 0]), ['invalid_path', 'where', $target], 'invalid_path where path form'];
        case 'segment':
            return [$put(['path' => $r->pick([['a', "\xff"], [str_repeat('s', 1025)], ["\xed\xa0\x80"]]), '=' => 1]), ['invalid_name', 'where', null], 'invalid_name where segment'];
        case 'dup':
            $wpaths = array_map(fn ($w) => $w[2], JfRef::plan($spec)['where']);
            if ($wpaths === []) {
                return w_inject($r, $spec, $scalarOnly);
            }
            $spec['where']['zq'] = ['path' => $r->pick($wpaths), '<=' => 1];
            return [$spec, ['duplicate_name', 'where', 'zq'], 'duplicate_name equal where paths'];
        case 'prefix':
            // scalar mode: a scalar where name is a one-segment path, so it can only be the prefix of a longer path
            $p = $r->pick($scalarOnly ? array_values(array_filter($paths, fn ($p) => count($p) > 1)) : $paths);
            $q = count($p) > 1 && ($scalarOnly || $r->chance(50)) ? array_slice($p, 0, $scalarOnly ? 1 : 1 + $r->int(count($p) - 1)) : (count($p) < 8 ? [...$p, 'zz'] : null);
            $wpaths = array_map(fn ($w) => $w[2], JfRef::plan($spec)['where']);
            if ($q === null || in_array($q, $wpaths, true) || ($scalarOnly && isset($spec['where'][$q[0]]))) {
                return w_inject($r, $spec, $scalarOnly);
            }
            if ($scalarOnly) {
                $spec['where'][$q[0]] = 1;
                return [$spec, ['invalid_path', 'where', $q[0]], 'invalid_path where name over a field path'];
            }
            $spec['where']['zq'] = ['path' => $q, '=' => 1];
            return [$spec, ['invalid_path', 'where', 'zq'], 'invalid_path where path prefix'];
    }
    throw new LogicException($kind);
}

/** Adds $path to a record tree: an object is ['o', [[key, node], ...]], a scalar ['s', text]. */
function w_insert(array &$node, array $path, string $leaf): void
{
    $seg = array_shift($path);
    foreach ($node[1] as $i => [$k, $child]) {
        if ($k === $seg) {
            if ($path !== [] && $child[0] === 'o') {
                w_insert($node[1][$i][1], $path, $leaf);
            }
            return;
        }
    }
    if ($path === []) {
        $node[1][] = [$seg, ['s', $leaf]];
        return;
    }
    $child = ['o', []];
    w_insert($child, $path, $leaf);
    $node[1][] = [$seg, $child];
}

/** Repeats, drops, non-object intermediates, junk keys and shuffles, at every level. */
function w_mutate(JfRng $r, array &$node): void
{
    foreach ($node[1] as $i => [$k, $child]) {
        if ($child[0] === 'o') {
            if ($r->chance(3)) {
                $node[1][$i][1] = ['s', $r->pick(['null', '[]', '[{"b":1}]', '"s"', '0', 'false'])];
            } else {
                w_mutate($r, $node[1][$i][1]);
            }
        }
    }
    if ($node[1] !== [] && $r->chance(4)) {
        array_splice($node[1], $r->int(count($node[1])), 1);
    }
    if ($node[1] !== [] && $r->chance(2)) {
        $node[1][] = $r->pick($node[1]);
    }
    if ($r->chance(12)) {
        $node[1][] = [$r->pick(['z', 'a', 'b', 'k']), $r->chance(50) ? ['s', '1'] : ['o', [['a', ['s', '1']], ['a', ['s', '2']]]]];
    }
    if ($r->chance(25)) {
        for ($i = count($node[1]) - 1; $i > 0; $i--) {
            $j = $r->int($i + 1);
            [$node[1][$i], $node[1][$j]] = [$node[1][$j], $node[1][$i]];
        }
    }
}

function w_text(JfRng $r, array $node): string
{
    if ($node[0] === 's') {
        return $node[1];
    }
    $parts = [];
    foreach ($node[1] as [$k, $child]) {
        $key = json_encode($k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $parts[] = ($k !== '' && $r->chance(6) ? '"' . jf_u_escape(substr($key, 1, -1)) . '"' : $key) . ':' . w_text($r, $child);
    }
    return '{' . implode(',', $parts) . '}';
}

function w_field_leaf(JfRng $r, string $type, bool $clean): string
{
    $ok = ['int64' => ['0', '7', '-1', '9223372036854775807', '-0'], 'float64' => ['1.5', '2', '-0.0', '1e3', '9007199254740993'],
        'bool' => ['true', 'false'], 'string' => ['"s"', '""', '"café"', '"q\"\\\\"', '"é"']];
    return !$clean && $r->chance(7) ? $r->pick([...W_OTHER_LITS, '"5"', '5']) : $r->pick($ok[rtrim($type, '?')]);
}

/** A leaf for a where entry: often an operand or its neighbour in the same kind, else any kind. */
function w_where_leaf(JfRng $r, array $ops, bool $clean): string
{
    $roll = $r->int(100);
    if ($roll < ($clean ? 75 : 45) && $ops !== []) {
        [$op, $x] = $r->pick($ops);
        if ($op === 'in') {
            $x = $r->pick($x);
        }
        return w_lit_of($r, $x);
    }
    if ($roll < 70) {
        return $r->pick(W_NUM_LITS);
    }
    if ($roll < 90) {
        return w_str_text($r, $r->pick(w_strings(false)));
    }
    return $r->pick(W_OTHER_LITS);
}

/** A record along the plan's paths; a clean one has every leaf of its field's type and no mutation. */
function w_record(JfRng $r, array $spec, bool $clean): string
{
    $plan = JfRef::plan($spec);
    $root = ['o', []];
    $parts = [];
    foreach ($plan['fields'] as [, $type, , $has, , $path]) {
        $parts[] = [$path, $r->chance($has ? 70 : ($clean ? 100 : 93)) ? w_field_leaf($r, $type, $clean) : null];
    }
    foreach ($plan['where'] as [, $ops, $path]) {
        $parts[] = [$path, $r->chance($clean ? 98 : 93) ? w_where_leaf($r, $ops, $clean) : null];
    }
    // where leaves first now and then, so that a shared path holds the where literal
    if ($r->chance(30)) {
        $parts = array_reverse($parts);
    }
    foreach ($parts as [$path, $leaf]) {
        if ($leaf !== null) {
            w_insert($root, $path, $leaf);
        }
    }
    if ($clean) {
        return w_text($r, $root);
    }
    w_mutate($r, $root);
    $t = w_text($r, $root);
    return $r->chance(2) ? $r->pick([substr($t, 0, -1), '[' . $t . ']', "{\"a\":\"\xff\"}"]) : $t;
}

/** The loaded API's outcome for a compiled plan, in JfRef::render()'s form, and its output bytes. */
function w_ext_run(string $data, Plan $plan): array
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

/** Output against the reference rows: exact bytes where the spec fixes them, else decoded with floats by bits. */
function w_same_output(array $ref, string $out): bool
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

/** A PlanException as [reason, option, field], null when the plan compiles, or a 'BAD ...' string. */
function w_compile(array $spec, ?Plan &$plan)
{
    $plan = null;
    try {
        $plan = Plan::compile($spec);
        return null;
    } catch (PlanException $e) {
        return $e->getPrevious() === null ? [$e->getReason(), $e->getOption(), $e->getField()] : 'BAD previous ' . jf10_chain($e);
    } catch (Throwable $e) {
        return 'BAD ' . get_class($e);
    }
}

/**
 * Run the differential: $mode 'all' (every form) or 'scalar' (scalar where only), until $target runs of
 * compiled plans (with an array-form where entry in all mode; any in scalar mode). Returns [summary, disagreements].
 */
function jf_where_diff(string $mode, int $seed, int $target): array
{
    $scalarOnly = $mode === 'scalar';
    $r = new JfRng($seed);
    $st = ['plans' => 0, 'injected' => 0, 'compile errors' => 0, 'runs' => 0, 'counted runs' => 0, 'accepted' => 0, 'rejected' => 0,
        'records' => 0, 'matched records' => 0, 'output lines' => 0];
    $planReasons = $injected = $recordReasons = $forms = $evals = [];
    $bad = [];
    $badCount = $injectorBad = 0;
    $bump = function (array &$a, string $k, int $n = 1) {
        $a[$k] = ($a[$k] ?? 0) + $n;
    };
    // armed only around the oracle's run: the reference-alone shim calls JfRef::run as well
    $count = function (string $op, $operand, array $v) use (&$evals, $bump) {
        if ($op === '=' || $op === 'in') {
            $bump($evals, "'$op'");
            return;
        }
        if (is_string($operand)) {
            $bump($evals, $v['k'] === 'string' ? 'string range' : 'string range, other kind');
            return;
        }
        $kind = 'number range, other kind';
        if ($v['k'] === 'number') {
            [$isInt, $class] = JfRef::number($v['raw']);
            $kind = $class === 'RAW' ? 'number range, RAW' : (is_int($operand) ? 'int' : 'float') . ' vs ' . ($isInt ? ($class === 'U64' ? 'uint64' : 'int') : 'real');
        }
        $bump($evals, $kind);
    };
    try {
        while ($st['counted runs'] < $target) {
            $st['plans']++;
            $spec = w_plan($r, $scalarOnly);
            $label = null;
            if (JfRef::compileError($spec) === null && $r->chance(22)) {
                [$spec, $expect, $label] = w_inject($r, $spec, $scalarOnly);
                $st['injected']++;
                $bump($injected, $label);
                if (JfRef::compileError($spec) !== $expect) {
                    $injectorBad++;
                    if (count($bad) < 3) {
                        $bad[] = ["injector $label: plan " . var_export($spec, true), json_encode($expect), 'reference ' . json_encode(JfRef::compileError($spec))];
                    }
                }
            }
            $want = JfRef::compileError($spec);
            $got = w_compile($spec, $plan);
            if ($want === null && $got !== null) {
                // the target counts plans the reference compiles, so a build that rejects them still ends
                $st['counted runs'] += $scalarOnly || array_diff(array_keys(w_tags($spec)), ['scalar']) !== [];
            }
            if ($want !== null || $got !== null) {
                $st['compile errors']++;
                $bump($planReasons, $want[0] ?? 'none');
                if ($want !== $got && $badCount++ < 3) {
                    $bad[] = ['plan ' . var_export($spec, true), json_encode($want), is_string($got) ? $got : json_encode($got)];
                }
                continue;
            }
            $tags = w_tags($spec);
            $counted = $scalarOnly || array_diff(array_keys($tags), ['scalar']) !== [];
            $st['counted runs'] += $counted;
            foreach ($tags as $k => $one) {
                $bump($forms, $k);
            }
            $data = '';
            $clean = $r->chance(45);
            for ($n = 1 + $r->int(3); $n > 0; $n--) {
                $data .= ($r->chance(3) ? "\n" : '') . w_record($r, $spec, $clean) . ($r->chance(10) ? "\r\n" : "\n");
                $st['records']++;
            }
            JfRef::$onCompare = $count;
            $ref = JfRef::run($data, $spec);
            JfRef::$onCompare = null;
            [$got, $out] = w_ext_run($data, $plan);
            $wantText = JfRef::render($ref);
            if (str_contains($wantText, 'bytesWritten=?')) {
                $got = preg_replace('/bytesWritten=\d+(\]?)$/', 'bytesWritten=?$1', $got);
            }
            $st['runs']++;
            $st[$ref['ok'] ? 'accepted' : 'rejected']++;
            $st['matched records'] += $ref['matched'];
            $st['output lines'] += count($ref['rows']);
            if (!$ref['ok']) {
                $bump($recordReasons, $ref['reason']);
            }
            if (($got !== $wantText || !w_same_output($ref, $out)) && $badCount++ < 3) {
                $bad[] = ['data ' . bin2hex($data) . ' plan ' . var_export($spec, true), $wantText . ' ' . bin2hex($ref['out'] ?? '?'), $got . ' ' . bin2hex($out)];
            }
        }
    } finally {
        JfRef::$onCompare = null;
    }
    foreach ([&$planReasons, &$injected, &$recordReasons, &$forms, &$evals] as &$a) {
        ksort($a);
    }
    unset($a);
    $show = fn (array $a) => implode(', ', array_map(fn ($k, $v) => "$k=$v", array_keys($a), $a));
    $text = "mode $mode, seed $seed\n"
        . sprintf("plans: %d (%d with an injected fault), compile errors: %d, runs: %d (%s %d; accepted %d, rejected %d)\n",
            $st['plans'], $st['injected'], $st['compile errors'], $st['runs'], $scalarOnly ? 'counted' : 'with an array-form where entry',
            $st['counted runs'], $st['accepted'], $st['rejected'])
        . sprintf("records: %d, matched: %d, output lines: %d\n", $st['records'], $st['matched records'], $st['output lines'])
        . 'plan errors by reason: ' . $show($planReasons) . "\n"
        . "injected faults:\n" . implode('', array_map(fn ($k, $v) => "  $k: $v\n", array_keys($injected), $injected))
        . 'where forms in compiled runs: ' . $show($forms) . "\n"
        . 'operator evaluations: ' . $show($evals) . "\n"
        . 'record errors by reason: ' . $show($recordReasons) . "\n"
        . "injector disagreements with the reference: $injectorBad\n"
        . "disagreements with the reference: $badCount\n";
    foreach ($bad as [$case, $w, $g]) {
        $text .= "  $case\n    want: $w\n    got:  $g\n";
    }
    return [$text, $badCount + $injectorBad];
}

/** Run each PHPT's FILE section on the reference alone (jf_refext.inc) and compare with its EXPECT. Returns the failure count. */
function jf_where_phpts(array $files): int
{
    $tests = __DIR__;
    $tmpDir = sys_get_temp_dir() . '/jf_where_phpts_' . getmypid();
    @mkdir($tmpDir);
    $failed = 0;
    foreach ($files as $f) {
        $t = str_replace("\r\n", "\n", file_get_contents($f));
        preg_match_all('/^--([A-Z_]+)--\n/m', $t, $m, PREG_OFFSET_CAPTURE);
        $sec = [];
        foreach ($m[1] as $i => [$name, $off]) {
            $start = $off + strlen($name) + 3;
            $sec[$name] = substr($t, $start, ($m[0][$i + 1][1] ?? strlen($t)) - $start);
        }
        if (!isset($sec['FILE'], $sec['EXPECT'])) {
            echo basename($f), " SKIP (needs FILE and EXPECT sections)\n";
            $failed++;
            continue;
        }
        $ini = '';
        foreach (preg_split('/\n/', trim($sec['INI'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $l) {
            $ini .= ' -d ' . escapeshellarg(trim($l));
        }
        $tmp = "$tmpDir/" . basename($f, '.phpt') . '.php';
        file_put_contents($tmp, str_replace('__DIR__', var_export($tests, true), $sec['FILE']));
        $cmd = escapeshellarg(PHP_BINARY) . ' -d auto_prepend_file=' . escapeshellarg("$tests/jf_refext.inc") . $ini . ' ' . escapeshellarg($tmp) . ' 2>&1';
        $out = rtrim(str_replace("\r\n", "\n", (string)shell_exec($cmd)));
        $want = rtrim($sec['EXPECT']);
        if ($out === $want) {
            echo basename($f), " PASS\n";
        } else {
            $failed++;
            echo basename($f), " FAIL\n";
            $wl = explode("\n", $want);
            $gl = explode("\n", $out);
            for ($i = 0, $shown = 0; $i < max(count($wl), count($gl)) && $shown < 20; $i++) {
                if (($wl[$i] ?? null) !== ($gl[$i] ?? null)) {
                    echo "  line ", $i + 1, "\n    want: ", $wl[$i] ?? '(none)', "\n    got:  ", $gl[$i] ?? '(none)', "\n";
                    $shown++;
                }
            }
        }
        unlink($tmp);
    }
    @rmdir($tmpDir);
    return $failed;
}

const JF_WHERE_SEED = 20261119;
const JF_WHERE_RUNS = 20000;

if (PHP_SAPI === 'cli' && realpath($_SERVER['argv'][0] ?? '') === __FILE__) {
    $mode = $_SERVER['argv'][1] ?? 'all';
    if (!in_array($mode, ['all', 'scalar', 'phpts', 'expect'], true)) {
        fwrite(STDERR, "usage: php tests/jf_where_diff.inc [all|scalar|phpts|expect]\n");
        exit(2);
    }
    $ext = extension_loaded('jsonfuse');
    if ($ext === in_array($mode, ['phpts', 'expect'], true)) {
        fwrite(STDERR, $ext ? "mode $mode runs the reference alone: do not load the extension\n" : "mode $mode needs the jsonfuse extension loaded\n");
        exit(2);
    }
    if ($mode === 'phpts') {
        $files = array_slice($_SERVER['argv'], 2) ?: glob(__DIR__ . '/14[0-9]_*.phpt');
        exit(jf_where_phpts($files) === 0 ? 0 : 1);
    }
    if ($mode === 'expect') {
        require_once __DIR__ . '/jf_refext.inc';
    }
    $t0 = microtime(true);
    [$text, $bad] = jf_where_diff($mode === 'scalar' ? 'scalar' : 'all', JF_WHERE_SEED, JF_WHERE_RUNS);
    echo $text;
    fprintf(STDERR, "%s in %.1f s, %s\n", $mode, microtime(true) - $t0, $ext ? 'jsonfuse ' . phpversion('jsonfuse') : 'reference stand-in (jf_refext.inc)');
    exit($bad === 0 ? 0 : 1);
}
