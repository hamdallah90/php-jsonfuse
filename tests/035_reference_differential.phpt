--TEST--
jsonfuse: accept/reject, counters, line/record/offset and the projected output agree with an independent PHP reference on a seeded corpus
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Runner;

/*
 * Reference implementation: tests/jf_reference.inc (JfRef), plain PHP, derived from SPEC.md section 5.
 *
 * Where json_decode() is used and where it cannot be:
 *   - syntax + UTF-8 verdict of every corpus record: json_decode() (depth 512 allows 511 container
 *     levels, the same limit as the spec) is the second opinion for JfRef's own scanner;
 *   - integer vs real literal: json_decode() returns int for an integer literal that fits int64 and
 *     float otherwise, checked below for the literals where that is observable;
 *   - projected output: every output line is decoded with json_decode() and compared to the
 *     reference's rows by key order, strings by bytes, integers exactly and floats by IEEE bits
 *     (pack('E')); where the spec fixes the text of a line, the line is also compared byte for byte;
 *   - NOT observable with json_decode(), so JfRef inspects raw tokens: duplicate keys (the last copy
 *     silently wins), the SINT / UINT / RAW split (2^63 .. 2^64-1, integers beyond uint64 and `1e400`
 *     all come back as float or INF), a real literal that overflows (INF, like a 400-digit integer)
 *     and key identity after \u decoding when two keys collapse into one array slot.
 */

// ---- 1. the reference against hand-written known answers --------------------------------
function known(string $name, string $data, array $spec, string $want): void
{
    $r = JfRef::run($data, $spec);
    $got = $r['ok']
        ? "ok read={$r['read']} matched={$r['matched']} bytesRead={$r['bytesRead']}"
        : "err line={$r['line']} record={$r['record']} offset={$r['offset']}";
    echo str_pad($name, 56), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
}

$kind = ['fields' => ['i' => 'int64'], 'where' => ['kind' => 'a']];
echo "-- reference self-check\n";
known('match', "{\"i\":1,\"kind\":\"a\"}\n", $kind, 'ok read=1 matched=1 bytesRead=19');
known('no match', "{\"i\":1,\"kind\":\"b\"}\n", $kind, 'ok read=1 matched=0 bytesRead=19');
known('no final LF', '{"i":1}', $kind, 'ok read=1 matched=0 bytesRead=7');
known('CRLF, two records', "{\"i\":1,\"kind\":\"a\"}\r\n{\"i\":2,\"kind\":\"a\"}\r\n", $kind, 'ok read=2 matched=2 bytesRead=40');
known('empty input', '', $kind, 'ok read=0 matched=0 bytesRead=0');
known('type error', "{\"i\":\"1\",\"kind\":\"a\"}\n", $kind, 'err line=1 record=1 offset=0');
known('blank line', "{\"i\":1}\n\n{\"i\":1}\n", $kind, 'err line=2 record=2 offset=8');
known('blank line skipped', "{\"i\":1}\n\n{\"i\":1}\n", $kind + ['skipEmptyLines' => true], 'ok read=2 matched=0 bytesRead=17');
known('error after a skipped blank: line vs record', "\n{\"i\":1}\n\n[]\n", $kind + ['skipEmptyLines' => true], 'err line=4 record=2 offset=10');
known('duplicate key', "{\"i\":1,\"i\":2}\n", $kind, 'err line=1 record=1 offset=0');
known('duplicate through an escape', "{\"i\":1,\"\u0069\":2}\n", $kind, 'err line=1 record=1 offset=0');
known('unreferenced duplicate', "{\"i\":1,\"z\":1,\"z\":2}\n", $kind, 'ok read=1 matched=0 bytesRead=20');
known('non-scalar filter value', "{\"i\":1,\"kind\":[]}\n", $kind, 'err line=1 record=1 offset=0');
known('missing field', "{\"kind\":\"a\"}\n", $kind, 'err line=1 record=1 offset=0');
known('root array', "[]\n", $kind, 'err line=1 record=1 offset=0');
known('over the size cap', "{\"i\":1}\n", $kind + ['maxRecordBytes' => 6], 'err line=1 record=1 offset=0');
known('exactly at the size cap', "{\"i\":1}\n", $kind + ['maxRecordBytes' => 7], 'ok read=1 matched=0 bytesRead=8');
foreach ([
    ['9223372036854775807', true], ['-9223372036854775808', true], ['-0', true], ['9223372036854775808', false],
    ['1.0', false], ['1e3', false], ['-0.0', false], ['18446744073709551615', false],
] as [$lit, $ok]) {
    known("int64 field, $lit", "{\"n\":$lit}\n", ['fields' => ['n' => 'int64']],
        $ok ? 'ok read=1 matched=1 bytesRead=' . (strlen($lit) + 7) : 'err line=1 record=1 offset=0');
}
foreach ([
    ['9223372036854775808', true], ['18446744073709551616', true], ['1e308', true], ['1.7976931348623157e308', true],
    ['1.7976931348623159e308', false], ['1e400', false], ['-1e400', false], [str_repeat('9', 400), false],
    ['1e-400', true], ['-1e-400', true], ['-0.0', true], ['"1"', false], ['null', false],
] as [$lit, $ok]) {
    known('float64 field, ' . (strlen($lit) > 30 ? strlen($lit) . ' chars' : $lit), "{\"n\":$lit}\n", ['fields' => ['n' => 'float64']],
        $ok ? 'ok read=1 matched=1 bytesRead=' . (strlen($lit) + 7) : 'err line=1 record=1 offset=0');
}
foreach ([
    [5, '5', 1], [5, '5.0', 0], [5, '-0', 0], [0, '-0', 1], [5, '1e400', 0], [5.0, '5', 0], [5.0, '5e0', 1], [0.0, '-0.0', 1],
    [0.0, '1e-400', 1], [0.0, '0', 0], [1.5, '"1.5"', 0], ['5', '5', 0], ['5', '"5"', 1], [true, 'true', 1], [true, '1', 0],
    [null, 'null', 1], [null, '0', 0], [PHP_INT_MAX, '9223372036854775807', 1], [PHP_INT_MAX, '9223372036854775808', 0],
] as [$want, $lit, $m]) {
    known('where ' . var_export($want, true) . ' vs ' . $lit, "{\"id\":1,\"v\":$lit}\n",
        ['fields' => ['id' => 'int64'], 'where' => ['v' => $want]], "ok read=1 matched=$m bytesRead=" . (strlen($lit) + 14));
}
$levels = fn(int $n): string => '{"u":' . str_repeat('[', $n - 1) . str_repeat(']', $n - 1) . '}';
known('nesting: 511 levels, with i', str_replace('{"u"', '{"i":1,"u"', $levels(511)) . "\n", ['fields' => ['i' => 'int64']], 'ok read=1 matched=1 bytesRead=' . (strlen($levels(511)) + 7));
known('nesting: 512 levels, with i', str_replace('{"u"', '{"i":1,"u"', $levels(512)) . "\n", ['fields' => ['i' => 'int64']], 'err line=1 record=1 offset=0');

// ---- 2. json_decode as a second opinion where it can see the distinction ----------------
echo "-- json_decode agrees on int vs real for the literals it can see\n";
foreach ([
    '0', '5', '-5', '9223372036854775807', '-9223372036854775808', '9223372036854775808', '18446744073709551615',
    '18446744073709551616', '-9223372036854775809', '1.0', '1e3', '1E3', '-0.0', '0.5', '1e-400', '123456789012345678901234567890',
] as $lit) {
    $viaDecode = is_int(json_decode($lit));
    [$isInt, $class] = JfRef::number($lit);
    $viaRef = $isInt && $class === 'I64';
    echo str_pad($lit, 40), $viaDecode === $viaRef ? 'ok' : 'FAIL json_decode int=' . var_export($viaDecode, true) . ' reference int64=' . var_export($viaRef, true), "\n";
}
echo "-- what json_decode cannot tell apart (the reference can)\n";
$d400 = str_repeat('9', 400);
foreach ([
    ['1e400 and a 400-digit integer both decode to INF', '1e400', $d400],
    ['uint64 max and uint64 max + 1 both decode to float', '18446744073709551615', '18446744073709551616'],
] as [$name, $a, $b]) {
    $same = json_decode($a) === json_decode($b) || (is_float(json_decode($a)) && is_float(json_decode($b)));
    $ra = JfRef::number($a);
    $rb = JfRef::number($b);
    echo str_pad($name, 56), 'json_decode alike=', var_export($same, true), ' reference class ', $ra[1], '/', $rb[1], ' integer=', var_export($ra[0], true), '/', var_export($rb[0], true), "\n";
}
echo str_pad('duplicate keys: json_decode keeps one', 56), count(json_decode('{"i":1,"i":2}', true)), ' key, reference sees ',
    count(JfRef::rootKeys('{"i":1,"i":2}')), "\n";
echo str_pad('escaped duplicate: json_decode keeps one', 56), count(json_decode('{"i":1,"\u0069":2}', true)), ' key, reference sees ',
    count(JfRef::rootKeys('{"i":1,"\u0069":2}')), "\n";
echo str_pad('nesting limit: json_decode(..., 512) vs reference', 56);
foreach ([510, 511, 512] as $n) {
    $t = '{"u":' . str_repeat('[', $n) . str_repeat(']', $n) . '}';
    $dj = json_decode($t, true, 512) !== null;
    echo $n, ':', var_export($dj === JfRef::syntaxOk($t), true), ' ';
}
echo "\n";

// ---- 3. the seeded corpus ---------------------------------------------------------------
const G_OTHER = ['null', '[]', '{}', '[1]', '{"a":1}', '"5"', '5', '5.0', '1.5', 'true', 'false', '"true"', '"x"', '""', '-0', '1e400'];

function g_spell(JfRng $r, string $key): string
{
    $out = '';
    foreach (preg_split('//u', $key, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if (strlen($ch) > 1) {
            $out .= $r->chance(40) ? jf_u_escape($ch) : $ch;
        } elseif ($r->chance(12)) {
            $out .= sprintf($r->chance(50) ? '\u%04x' : '\u%04X', ord($ch));
        } else {
            $out .= $ch;
        }
    }
    return '"' . $out . '"';
}

function g_str(JfRng $r, string $s): string
{
    return '"' . ($r->chance(40) ? jf_u_escape($s) : $s) . '"';
}

function g_typed(JfRng $r, string $type): string
{
    $nullable = str_ends_with($type, '?');
    $t = rtrim($type, '?');
    $ok = [
        'int64' => ['0', '1', '-1', '7', '3', '5', '42', '-0', '9223372036854775807', '-9223372036854775808'],
        'float64' => ['0', '1.5', '-0.0', '0.0', '1e3', '1E3', '5', '3.0', '2.5e-3', '1e-400', '-1e-400', '4.9e-324',
            '1.7976931348623157e308', '9223372036854775808', '123456789012345678901234567890', '-9223372036854775809'],
        'bool' => ['true', 'false'],
        'string' => ['"a"', '""', '"x"', '"customer"', '"caf\u00e9"', "\"caf\u{e9}\"", '"\ud83d\ude00"', '"a\u0000b"', '"A"', '"\"q\""'],
    ];
    $edge = [
        'int64' => ['9223372036854775808', '18446744073709551615', '18446744073709551616', '-9223372036854775809', '1.0', '1e3',
            '-0.0', '5e0', '1e400', '-1e400', str_repeat('9', 40), '1e-400', '"7"'],
        'float64' => ['1e400', '-1e400', '1.7976931348623159e308', str_repeat('9', 400), '"1.5"', 'true', 'null'],
        'bool' => ['1', '0', '"true"', '"false"', 'null'],
        'string' => ['1', '1.5', 'true', 'null', '"\ud800"', '"\udc00x"'],
    ];
    $roll = $r->int(100);
    if ($nullable && $roll < 10) {
        return 'null';
    }
    if ($roll < 76) {
        return $r->pick($ok[$t]);
    }
    if ($roll < 92) {
        return $r->pick($edge[$t]);
    }
    return $r->pick(G_OTHER);
}

function g_target(JfRng $r, $w): string
{
    if (is_string($w)) {
        return $r->pick([g_str($r, $w), g_str($r, $w), g_str($r, $w), '"' . $w . 'x"', '"' . strtoupper($w) . '"', '""', 'null', '5']);
    }
    if (is_int($w)) {
        return $r->pick([(string)$w, (string)$w, $w . '.0', $w . 'e0', '"' . $w . '"', (string)($w + 1), '-0', 'true']);
    }
    if (is_float($w)) {
        $b = var_export($w, true);
        return $r->pick([$b, $b, $b . 'e0', $w == floor($w) ? (string)(int)$w : $b . '1', '"' . $b . '"', '0', '-0', '0.0', '1e400', '1e-400']);
    }
    if (is_bool($w)) {
        return $r->pick(['true', 'false', $w ? 'true' : 'false', '1', '0', '"true"', 'null']);
    }
    return $r->pick(['null', 'null', '0', '""', 'false', '[]']);
}

function g_junk(JfRng $r): string
{
    $deep = static fn(int $d): string => str_repeat('[', $d) . '1' . str_repeat(']', $d);
    $c = [
        '1', '"SECRETMARK"', 'null', '[1,2,{"i":"x"}]', '{"i":"x","i":2,"kind":[1],"kind":"b"}', '1e400', '-1e400', str_repeat('9', 400),
        '{"a":{"a":{"a":[{"a":1,"a":2}]}}}', '"\u00e9"', '[]', '{}', "\"caf\u{e9}\"", '-0.0', '18446744073709551616',
    ];
    if ($r->chance(4)) {
        return $deep($r->pick([505, 509, 510, 511, 512, 520]));
    }
    return $r->pick($c);
}

/** Build one record text for a plan; may contain duplicates and omissions. */
function g_record(JfRng $r, array $spec): string
{
    $refs = [];
    foreach ($spec['fields'] as $name => $type) {
        $refs[(string)$name]['type'] = $type;
    }
    foreach ($spec['where'] ?? [] as $name => $w) {
        $refs[(string)$name]['where'] = [$w];
    }
    $value = function (array $info) use ($r): string {
        if ($r->chance(4)) {
            return $r->pick(G_OTHER);
        }
        if (isset($info['where']) && $r->chance(7)) {
            return $r->pick(['[]', '{}', '[1]', '{"a":1}']);
        }
        if (isset($info['where']) && (!isset($info['type']) || $r->chance(50))) {
            return g_target($r, $info['where'][0]);
        }
        return isset($info['type']) ? g_typed($r, $info['type']) : $r->pick(G_OTHER);
    };
    $members = [];
    foreach ($refs as $name => $info) {
        if (!$r->chance(5)) {
            $members[] = [g_spell($r, (string)$name), $value($info)];
        }
    }
    $names = array_map('strval', array_keys($refs));
    if ($r->chance(10)) {
        $n = $r->pick($names);
        $copy = [g_spell($r, $n), $value($refs[$n])];
        array_splice($members, $r->int(count($members) + 1), 0, [$copy]);
    }
    for ($n = $r->int(4); $n > 0; $n--) {
        $members[] = [g_spell($r, $r->pick(['junk', 'u', 'extra', 'Kind', 'I', 'i2', '', 'n2', "caf\u{e9}", 'z'])), g_junk($r)];
    }
    if ($r->chance(10)) {
        $k = g_spell($r, $r->pick(['dup', 'z', 'junk']));
        $members[] = [$k, g_junk($r)];
        $members[] = [$k, g_junk($r)];
    }
    if (isset($spec['maxRecordBytes']) && $r->chance(30)) {
        $members[] = ['"pad"', '"' . str_repeat('p', 40 + $r->int(80)) . '"'];
    }
    if ($r->chance(30)) {
        for ($i = count($members) - 1; $i > 0; $i--) {
            $j = $r->int($i + 1);
            [$members[$i], $members[$j]] = [$members[$j], $members[$i]];
        }
    }
    $sep = $r->pick([',', ',', ',', ', ', " ,\t", ",\r"]);
    $colon = $r->pick([':', ':', ':', ' : ']);
    $parts = [];
    foreach ($members as [$k, $v]) {
        $parts[] = $k . $colon . $v;
    }
    return $r->pick(['{', '{', '{ ', "{\t"]) . implode($sep, $parts) . $r->pick(['}', '}', ' }', "\t}"]);
}

function g_corrupt(JfRng $r, string $t): string
{
    switch ($r->int(14)) {
        case 0:
            return substr($t, 0, $r->int(strlen($t) + 1));
        case 1:
            return preg_replace('/\}(\s*)$/', ',}$1', $t);
        case 2:
            return '[' . $t . ']';
        case 3:
            return $t . ' x';
        case 4:
            return "\xEF\xBB\xBF" . $t;
        case 5:
            $p = $r->int(strlen($t) + 1);
            return substr($t, 0, $p) . "\xFF" . substr($t, $p);
        case 6:
            return preg_replace('/\}(\s*)$/', ',"u":"\ud800"}$1', $t);
        case 7:
            $p = $r->int(strlen($t) + 1);
            return substr($t, 0, $p) . $r->pick(["\x01", "\t", "\0"]) . substr($t, $p);
        case 8:
            return $r->pick(['null', '5', '"s"', 'true', '[]', '[{"i":1}]', '-1.5e3']);
        case 9:
            return str_replace('"', "'", $t);
        case 10:
            return '';
        case 11:
            return preg_replace('/\}(\s*)$/', ',"u":"\xC0\x80"}$1', $t);
        case 12:
            return substr($t, 0, -1) . ',"k":01}';
        default:
            return $t . $t;
    }
}

function g_data(JfRng $r, array $spec, int $n): string
{
    $data = '';
    for ($k = 0; $k < $n; $k++) {
        if ($r->chance(8)) {
            $data .= $r->chance(50) ? "\n" : "\r\n";
        }
        $t = g_record($r, $spec);
        if ($r->chance(7)) {
            $t = g_corrupt($r, $t);
        }
        $data .= $t . (($k === $n - 1 && $r->chance(30)) ? '' : ($r->chance(25) ? "\r\n" : "\n"));
    }
    return $data;
}

$plans = [
    ['fields' => ['i' => 'int64']],
    ['fields' => ['i' => 'int64', 's' => 'string?'], 'where' => ['kind' => 'a']],
    ['fields' => ['f' => 'float64', 'b' => 'bool?'], 'where' => ['n' => 5]],
    ['fields' => ['i' => 'int64?'], 'where' => ['z' => null, 'flag' => true]],
    ['fields' => ['s' => 'string'], 'where' => ['x' => 1.5, 'y' => false]],
    ['fields' => ['a' => 'int64'], 'where' => ['a' => 3]],
    ['fields' => ['i' => 'int64', 'f' => 'float64?'], 'where' => ['k' => "caf\u{e9}", 'n' => -0.0]],
    ['fields' => ['i' => 'int64'], 'where' => ['kind' => false, 'z' => null, 'n' => 0]],
    ['fields' => ['b' => 'bool', 's' => 'string?'], 'where' => ['s' => '']],
    ['fields' => [123 => 'int64?', "caf\u{e9}" => 'string'], 'where' => ['' => 'x']],
    ['fields' => ['i' => 'int64'], 'where' => ['a' => 1, 'b' => 2, 'c' => 3, 'd' => 4]],
    ['fields' => ['i' => 'int64'], 'where' => ['kind' => 'a'], 'maxRecordBytes' => 90, 'skipEmptyLines' => true],
];

$rng = new JfRng(20261109);
$stat = ['cases' => 0, 'accepted' => 0, 'rejected' => 0, 'matched' => 0, 'syntax' => 0, 'syntaxBad' => 0, 'leaks' => 0];
$outStat = ['lines' => 0, 'exact' => 0, 'bad' => 0];
$outBad = [];
$steps = array_fill(1, 6, 0);
$bad = [];
$syntaxBad = [];

function ext_run(string $data, array $spec): array
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $st = Runner::process($in, $out, jf_plan($spec));
        return [jf_stats($st), null, jf_out_bytes($out), $st];
    } catch (Throwable $e) {
        $p = $e instanceof JsonFuse\RecordException ? $e->getProgress() : null;
        $got = $p === null ? get_class($e) . ' ' . jf_ctx($e->getMessage()) : sprintf('%s %s field=%s line=%d record=%d offset=%d [%s]',
            get_class($e), $e->getReason(), $e->getField() === null ? 'null' : json_encode($e->getField()),
            $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset(), jf_stats($p));
        return [$got, $e->getMessage(), jf_out_bytes($out), null];
    } finally {
        fclose($in);
        fclose($out);
    }
}

/**
 * Compare the output of one run with the reference's rows. Accepted run: one line per matched record,
 * counters consistent with the output. Rejected run: the same lines, written before the throw.
 * Counts compared lines in $st (accepted runs only, so the totals do not depend on buffering).
 */
function check_out(array $ref, string $out, ?JsonFuse\Stats $s, array &$st): bool
{
    if ($out !== '' && substr($out, -1) !== "\n") {
        return false;
    }
    $lines = $out === '' ? [] : explode("\n", substr($out, 0, -1));
    $rows = $ref['rows'];
    if (count($lines) !== count($rows)) {
        return false;
    }
    foreach ($lines as $i => $line) {
        $d = json_decode($line, true);
        if (!is_array($d)) {
            return false;
        }
        $row = $rows[$i];
        if (array_map('strval', array_keys($d)) !== array_map(fn($r) => (string)$r[0], $row)) {
            return false;
        }
        foreach ($row as [$name, $kind, $v]) {
            $got = $d[$name];
            $ok = match ($kind) {
                'null' => $got === null,
                'bool' => $got === $v,
                'int' => is_int($got) && $got === $v,
                'string' => is_string($got) && $got === $v,
                'float' => is_float($got) && pack('E', $got) === pack('E', $v),
            };
            if (!$ok) {
                return false;
            }
        }
        if ($ref['ok']) {
            $st['lines']++;
            if ($ref['lines'][$i] !== null) {
                $st['exact']++;
                if ($ref['lines'][$i] !== $line . "\n") {
                    return false;
                }
            }
        }
    }
    if ($ref['ok']) {
        return $s !== null && $s->recordsEmitted === count($rows) && $s->recordsEmitted === $s->recordsMatched && $s->bytesWritten === strlen($out);
    }
    return $s === null;
}

$cases = [];
for ($k = 0; $k < 1500; $k++) {
    $spec = $plans[$k % count($plans)];
    $cases[] = [$spec, g_data($rng, $spec, 1)];
}
for ($k = 0; $k < 400; $k++) {
    $spec = $plans[$k % count($plans)];
    $cases[] = [$spec, g_data($rng, $spec, 2 + $rng->int(5))];
}

foreach ($cases as [$spec, $data]) {
    $stat['cases']++;
    $ref = JfRef::run($data, $spec);
    [$got, $msg, $outText, $stats] = ext_run($data, $spec);
    $want = JfRef::render($ref);
    if (str_contains($want, 'bytesWritten=?')) {
        $got = preg_replace('/bytesWritten=\d+(\]?)$/', 'bytesWritten=?$1', $got);      // checked against the output by check_out()
    }
    if (!check_out($ref, $outText, $stats, $outStat)) {
        $outStat['bad']++;
        $outBad[] = [$data, $spec];
    }
    if ($got !== $want) {
        $bad[] = [$data, $spec, $want, $got];
    }
    if ($ref['ok']) {
        $stat['accepted']++;
        $stat['matched'] += $ref['matched'];
    } else {
        $stat['rejected']++;
        $steps[$ref['step']]++;
    }
    if ($msg !== null && (str_contains($msg, 'SECRET') || (strlen($data) > 30 && str_contains($msg, substr(ltrim($data), 0, 24))))) {
        $stat['leaks']++;
    }
    // second opinion on JSON syntax, record by record
    foreach (explode("\n", $data) as $line) {
        $line = preg_replace('/\r$/', '', $line);
        if ($line === '') {
            continue;
        }
        $stat['syntax']++;
        $dj = json_decode($line, true, 512);
        $dj = json_last_error() === JSON_ERROR_NONE;
        if ($dj !== JfRef::syntaxOk($line)) {
            $stat['syntaxBad']++;
            $syntaxBad[] = $line;
        }
    }
}

echo "-- seeded corpus (xorshift32, seed 20261109)\n";
echo 'cases: ', $stat['cases'], ' (1500 single-record, 400 multi-record)', "\n";
echo 'accepted runs: ', $stat['accepted'], ', rejected runs: ', $stat['rejected'], ', records matched in accepted runs: ', $stat['matched'], "\n";
echo 'first failure by step: ', implode(' ', array_map(fn($s, $n) => "$s=$n", array_keys($steps), $steps)), "\n";
echo 'disagreements with the reference: ', count($bad), "\n";
foreach (array_slice($bad, 0, 5) as [$data, $spec, $want, $got]) {
    echo '  data=', bin2hex(substr($data, 0, 120)), ' plan=', json_encode($spec), "\n    want: $want\n    got:  $got\n";
}
echo 'exception messages that leaked record bytes: ', $stat['leaks'], "\n";
echo 'record lines checked against json_decode: ', $stat['syntax'], ', syntax disagreements: ', $stat['syntaxBad'], "\n";
foreach (array_slice($syntaxBad, 0, 3) as $l) {
    echo '  line=', bin2hex(substr($l, 0, 80)), "\n";
}

// ---- 4. projected output on a corpus built for output: mostly valid records, more matches ------
/** A valid literal for a field of the given type (nullable types may give null). */
function o_typed(JfRng $r, string $type): string
{
    if (str_ends_with($type, '?') && $r->chance(12)) {
        return 'null';
    }
    $ok = [
        'int64' => ['0', '-0', '1', '-1', '7', '42', '-123456789', '2147483648', '-2147483649', '4503599627370497', '9007199254740993', '1234567890123456789',
            '9223372036854775807', '-9223372036854775808'],
        'float64' => ['0', '-0', '5', '-5', '0.0', '-0.0', '1.0', '1e3', '1E3', '1e-400', '-1e-400', '0.1', '0.5', '1.5', '-1.5', '2.5e-3', '123.456',
            '0.30000000000000004', '1e20', '1e21', '1e22', '1.5e21', '100000000000000000000', '5e-324', '4.9e-324', '1.7976931348623157e308',
            '2.2250738585072014e-308', '9007199254740993', '9223372036854775807', '-9223372036854775808', '9223372036854775808', '18446744073709551615',
            '18446744073709551616', '123456789012345678901234567890', '-9223372036854775809', '12345678901234567890', '4503599627370497.5'],
        'bool' => ['true', 'false'],
        'string' => ['"a"', '""', '"x"', '"customer"', '"caf\u00e9"', "\"caf\u{e9}\"", '"\ud83d\ude00"', '"a\u0000b"', '"\"q\""', '"tab\tin"', '"\u001f\u007f"',
            '"line\u2028sep"', '"\/slash"', '"back\\\\slash"', '"\u00e9\u20ac\ud83d\ude00"', "\"\u{2028}\u{2029}\"", '"\b\f\n\r\t"', '"\u0001\u0002"'],
    ];
    return $r->pick($ok[rtrim($type, '?')]);
}

function o_record(JfRng $r, array $spec): string
{
    $members = [];
    foreach ($spec['fields'] as $name => $type) {
        $members[] = [g_spell($r, (string)$name), o_typed($r, $type)];
    }
    foreach ($spec['where'] ?? [] as $name => $w) {
        $members[] = [g_spell($r, (string)$name), $r->chance(70) ? (is_string($w) ? g_str($r, $w) : json_encode($w)) : $r->pick(['null', '0', '"zz"', 'false'])];
    }
    for ($n = $r->int(4); $n > 0; $n--) {
        $members[] = [g_spell($r, $r->pick(['junk', 'u', 'extra', 'z', 'Kind'])), g_junk($r)];
    }
    for ($i = count($members) - 1; $i > 0; $i--) {
        $j = $r->int($i + 1);
        [$members[$i], $members[$j]] = [$members[$j], $members[$i]];
    }
    $sep = $r->pick([',', ',', ', ', " ,\t"]);
    $parts = [];
    foreach ($members as [$k, $v]) {
        $parts[] = $k . $r->pick([':', ':', ' : ']) . $v;
    }
    return $r->pick(['{', '{ ']) . implode($sep, $parts) . $r->pick(['}', ' }']);
}

$outPlans = [
    ['fields' => ['f' => 'float64', 's' => 'string', 'i' => 'int64', 'b' => 'bool']],
    ['fields' => ['s' => 'string?', 'f' => 'float64?', 'i' => 'int64?', 'b' => 'bool?'], 'where' => ['kind' => 'a']],
    ['fields' => [123 => 'int64', "caf\u{e9}" => 'string?', '' => 'float64?', '__proto__' => 'bool?']],
    ['fields' => ['b' => 'bool', 'i' => 'int64', 'z' => 'string?', 'f' => 'float64'], 'where' => ['n' => 5], 'skipEmptyLines' => true],
    ['fields' => ['f' => 'float64']],
];
$rng2 = new JfRng(20261110);
$o4 = ['cases' => 0, 'accepted' => 0, 'lines' => 0, 'exact' => 0, 'bad' => 0];
for ($k = 0; $k < 1200; $k++) {
    $spec = $outPlans[$k % count($outPlans)];
    $data = '';
    for ($n = 1 + $rng2->int(6); $n > 0; $n--) {
        $t = o_record($rng2, $spec);
        if ($rng2->chance(4)) {
            $t = g_corrupt($rng2, $t);
        }
        $data .= $t . ($rng2->chance(20) ? "\r\n" : "\n");
    }
    $ref = JfRef::run($data, $spec);
    [$got, $msg, $outText, $stats] = ext_run($data, $spec);
    $o4['cases']++;
    $o4['accepted'] += $ref['ok'] ? 1 : 0;
    $cnt = ['lines' => 0, 'exact' => 0];
    if (!check_out($ref, $outText, $stats, $cnt)) {
        $o4['bad']++;
    }
    $o4['lines'] += $cnt['lines'];
    $o4['exact'] += $cnt['exact'];
}
echo "-- projected output (decoded with json_decode, compared with the reference)\n";
echo 'seeded corpus 1 (above): runs whose output differs from the reference: ', $outStat['bad'], "\n";
echo 'output corpus (xorshift32, seed 20261110): ', $o4['cases'], ' cases, accepted runs: ', $o4['accepted'], ', lines checked: ', $o4['lines'], ', of them byte-compared: ', $o4['exact'], ', runs whose output differs: ', $o4['bad'], "\n";
echo 'output covers many accepted runs and byte-compared lines: ', var_export($o4['accepted'] >= 700 && $o4['lines'] >= 1500 && $o4['exact'] >= 800, true), "\n";
$covered = $stat['accepted'] >= 400 && $stat['matched'] >= 150 && min($steps) >= 20;
echo 'corpus covers accepted, matched and every failure step: ', var_export($covered, true), "\n";
?>
--EXPECT--
-- reference self-check
match                                                   ok
no match                                                ok
no final LF                                             ok
CRLF, two records                                       ok
empty input                                             ok
type error                                              ok
blank line                                              ok
blank line skipped                                      ok
error after a skipped blank: line vs record             ok
duplicate key                                           ok
duplicate through an escape                             ok
unreferenced duplicate                                  ok
non-scalar filter value                                 ok
missing field                                           ok
root array                                              ok
over the size cap                                       ok
exactly at the size cap                                 ok
int64 field, 9223372036854775807                        ok
int64 field, -9223372036854775808                       ok
int64 field, -0                                         ok
int64 field, 9223372036854775808                        ok
int64 field, 1.0                                        ok
int64 field, 1e3                                        ok
int64 field, -0.0                                       ok
int64 field, 18446744073709551615                       ok
float64 field, 9223372036854775808                      ok
float64 field, 18446744073709551616                     ok
float64 field, 1e308                                    ok
float64 field, 1.7976931348623157e308                   ok
float64 field, 1.7976931348623159e308                   ok
float64 field, 1e400                                    ok
float64 field, -1e400                                   ok
float64 field, 400 chars                                ok
float64 field, 1e-400                                   ok
float64 field, -1e-400                                  ok
float64 field, -0.0                                     ok
float64 field, "1"                                      ok
float64 field, null                                     ok
where 5 vs 5                                            ok
where 5 vs 5.0                                          ok
where 5 vs -0                                           ok
where 0 vs -0                                           ok
where 5 vs 1e400                                        ok
where 5.0 vs 5                                          ok
where 5.0 vs 5e0                                        ok
where 0.0 vs -0.0                                       ok
where 0.0 vs 1e-400                                     ok
where 0.0 vs 0                                          ok
where 1.5 vs "1.5"                                      ok
where '5' vs 5                                          ok
where '5' vs "5"                                        ok
where true vs true                                      ok
where true vs 1                                         ok
where NULL vs null                                      ok
where NULL vs 0                                         ok
where 9223372036854775807 vs 9223372036854775807        ok
where 9223372036854775807 vs 9223372036854775808        ok
nesting: 511 levels, with i                             ok
nesting: 512 levels, with i                             ok
-- json_decode agrees on int vs real for the literals it can see
0                                       ok
5                                       ok
-5                                      ok
9223372036854775807                     ok
-9223372036854775808                    ok
9223372036854775808                     ok
18446744073709551615                    ok
18446744073709551616                    ok
-9223372036854775809                    ok
1.0                                     ok
1e3                                     ok
1E3                                     ok
-0.0                                    ok
0.5                                     ok
1e-400                                  ok
123456789012345678901234567890          ok
-- what json_decode cannot tell apart (the reference can)
1e400 and a 400-digit integer both decode to INF        json_decode alike=true reference class RAW/RAW integer=false/true
uint64 max and uint64 max + 1 both decode to float      json_decode alike=true reference class U64/RAW integer=true/true
duplicate keys: json_decode keeps one                   1 key, reference sees 2
escaped duplicate: json_decode keeps one                1 key, reference sees 2
nesting limit: json_decode(..., 512) vs reference       510:true 511:true 512:true 
-- seeded corpus (xorshift32, seed 20261109)
cases: 1900 (1500 single-record, 400 multi-record)
accepted runs: 592, rejected runs: 1308, records matched in accepted runs: 189
first failure by step: 1=244 2=174 3=22 4=229 5=535 6=104
disagreements with the reference: 0
exception messages that leaked record bytes: 0
record lines checked against json_decode: 3121, syntax disagreements: 0
-- projected output (decoded with json_decode, compared with the reference)
seeded corpus 1 (above): runs whose output differs from the reference: 0
output corpus (xorshift32, seed 20261110): 1200 cases, accepted runs: 850, lines checked: 2522, of them byte-compared: 1927, runs whose output differs: 0
output covers many accepted runs and byte-compared lines: true
corpus covers accepted, matched and every failure step: true
