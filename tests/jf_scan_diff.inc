<?php
/*
 * JsonFuse validating-scan differential: a build with the scan and one without give the same results.
 * Not a test itself. It prints one line per run: output SHA-1, peak heap growth, and the Stats
 * or the exception chain (class, reason, getters, progress) with the message. Run it under a
 * build with the scan and under one compiled with -DJF_DISABLE_SCAN, and compare the outputs
 * byte for byte: they must be identical. The build kind goes to stderr, not into the output.
 *
 *   php -d extension=... tests/jf_scan_diff.inc gen <seed> <records>   # generated records and flat plans
 *   php -d extension=... tests/jf_scan_diff.inc corpus <dir>...        # each file, and each of its lines, as input
 *
 * Inputs reach Runner::process through php://memory or through the chunking wrapper of
 * jf_helper.inc (1 to 16 bytes per read), so records end at every buffer position.
 *
 * On a build with -DJF_SCAN_STATS and the scan, gen mode also checks, per run, that the scan
 * accepted exactly the records the generator knows it must accept (gen_record), and exits 3 on
 * any difference; corpus mode only checks that it accepted some record.
 */
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const KEYS = ['a', 'b', 's', 'n', 'amount', 'accent', 'k1', 'k2', '', "\u{e9}", "a\0b", 'zz'];
const TYPES = ['int64', 'float64', 'bool', 'string'];
/* Unreferenced keys: the same lengths and edge bytes as some plan keys, so they share key filter bits. */
const NOISE = ['c', 'm', 'smount', 'acnent', 'k3', 'k', 'zy', 'y', "\u{e8}", 'aXb', 'payload', 'meta'];

$runs = $expected = $mismatches = 0;
$mode = $argv[1] ?? '';
$stats = false;

/* $expect: how many records the scan must accept, checked when $stats is set. */
function run_one(string $id, string $input, array $spec, int $chunk, ?int $expect = null): void
{
    global $runs, $stats, $expected, $mismatches;
    $runs++;
    try {
        $plan = Plan::compile($spec);
    } catch (Throwable $e) {
        echo $id, ' plan ', jf10_exc($e), "\n";
        return;
    }
    $before = $stats && $expect !== null ? jf10_scan_accepted() : null;
    $in = $chunk > 0 ? jf_wrap($input, $chunk) : jf_mem($input);
    $out = jf_out();
    memory_reset_peak_usage();
    $base = memory_get_usage();
    try {
        $r = jf_stats(Runner::process($in, $out, $plan));
    } catch (Throwable $e) {
        $r = jf10_chain($e) . ' | ' . $e->getMessage();
    }
    $peak = memory_get_peak_usage() - $base;
    echo $id, ' ', sha1(jf_out_bytes($out)), ' peak=', $peak, ' ', $r, "\n";
    fclose($in);
    fclose($out);
    if ($before !== null) {
        $expected += $expect;
        if (($got = jf10_scan_accepted() - $before) !== $expect) {
            $mismatches++;
            fwrite(STDERR, "$id: scan accepted $got, expected $expect: " . addcslashes(substr($input, 0, 600), "\0..\37\177..\377") . "\n");
        }
    }
}

function pick(array $a)
{
    return $a[mt_rand(0, count($a) - 1)];
}

function ws(): string
{
    return mt_rand(0, 7) ? '' : pick([' ', "\t", "\r", '  ', " \t"]);
}

/* A JSON string token: plain, escaped, raw UTF-8, long, or (when $bad) one invalid form. */
function jstr(string $s, bool $bad = false): string
{
    if ($bad) {
        return '"' . pick(["\xff", "\xc0\x80", "\xed\xa0\x80", "\xf4\x90\x80\x80", "\xe2\x82", chr(mt_rand(1, 0x1f)), "\x00", '\\x', '\\ud800', '\\ud800A', '\\ud800\\ud800', '\\ud800Xudc00', '\\ud800\\tdc00', '\\udc00\\udc00', '\\u12g4']) . $s . '"';
    }
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: str_split($s) as $ch) {
        $r = mt_rand(0, 9);
        if ($ch === '"' || $ch === '\\' || ord($ch[0]) < 0x20) {
            $out .= substr(json_encode($ch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1);
        } elseif ($r === 0) {
            $out .= substr(json_encode($ch), 1, -1);            // \uXXXX, surrogate pairs above the BMP
        } elseif ($r === 1 && $ch === '/') {
            $out .= '\\/';
        } else {
            $out .= $ch;
        }
    }
    return '"' . $out . '"';
}

function gen_string(): string
{
    return pick(['', 'x', 'abc', 'bb', 'c', "\u{e9}", "a\0b", "line\nbreak", 'q"uote', 'back\\slash', 'sl/ash',
        "\u{20ac}\u{1f600}", "\u{10ffff}", "\u{7f}\u{80}\u{7ff}\u{800}\u{ffff}\u{10000}", str_repeat('w', mt_rand(7, 40)), 'tab' . "\t" . str_repeat('9', mt_rand(1, 20))]);
}

function gen_number(): string
{
    return pick(['0', '-0', '1', '-1', '7', '42', '9007199254740993', '9223372036854775807', '-9223372036854775808',
        '9223372036854775808', '18446744073709551615', '18446744073709551616', '-9223372036854775809',
        '123456789012345678901234567890', '1.5', '-0.0', '0.5e-3', '1E+2', '1e400', '-1e400', '5e-324',
        '1e-400', '2.5E-300', '1.8446744073709552e19', '15', '1.5e1', '10', '20']);
}

function gen_value(int $depth = 0): string
{
    $k = mt_rand(0, 19);
    if ($k < 6) {
        return jstr(gen_string());
    }
    if ($k < 12) {
        return gen_number();
    }
    if ($k < 15) {
        return pick(['true', 'false', 'null']);
    }
    if ($k === 15 && $depth === 0 && mt_rand(0, 3) === 0) {
        $d = pick([62, 63, 64, 65, 66, 509, 510]);
        return mt_rand(0, 1) ? str_repeat('[', $d) . str_repeat(']', $d) : str_repeat('{"k":', $d) . '0' . str_repeat('}', $d);
    }
    if ($depth > 3) {
        return '0';
    }
    $n = mt_rand(0, 3);
    $parts = [];
    if ($k < 18) {
        for ($i = 0; $i < $n; $i++) {
            $parts[] = ws() . gen_value($depth + 1) . ws();
        }
        return '[' . implode(',', $parts) . ']';
    }
    for ($i = 0; $i < $n; $i++) {
        $parts[] = ws() . jstr(pick(KEYS)) . ws() . ':' . ws() . gen_value($depth + 1);
    }
    return '{' . implode(',', $parts) . '}';
}

/* A value of the field's type; $bad gives null, any value or a number int64 cannot hold. */
function gen_typed(string $type, bool $bad = false): string
{
    if ($bad) {
        return pick(['null', gen_value(), '9223372036854775808', '18446744073709551616', '1.0', '1e400']);
    }
    return match ($type) {
        'int64' => pick(['0', '-1', '7', '15', '20', '9223372036854775807', '-9223372036854775808', '10', '-0']),
        'float64' => pick(['1.5', '-0.0', '5e-324', '15', '1E+2', '18446744073709551616', '0.1', '2.5E-300']),
        'bool' => pick(['true', 'false']),
        default => jstr(gen_string()),
    };
}

/* Whether yyjson reads a number token as RAW: an integer outside int64 and uint64, or a real that is not finite. */
function raw_number(string $t): bool
{
    if (!preg_match('/^-?\d+$/', $t)) {
        return is_infinite((float)$t);
    }
    $d = ltrim($t, '-');
    $max = $t[0] === '-' ? '9223372036854775808' : '18446744073709551615';
    return strlen($d) > strlen($max) || (strlen($d) === strlen($max) && strcmp($d, $max) > 0);
}

/* The deepest container nesting in a value token, strings skipped. */
function nesting(string $v): int
{
    if (strpbrk($v, '[{') === false) {
        return 0;
    }
    $d = $max = 0;
    foreach (str_split(preg_replace('/"(?:[^"\\\\]|\\\\.)*"/s', '', $v)) as $ch) {
        $d += $ch === '[' || $ch === '{' ? 1 : ($ch === ']' || $ch === '}' ? -1 : 0);
        $max = max($max, $d);
    }
    return $max;
}

/*
 * One record for the plan's keys plus unreferenced noise, with fault $m (-1: none). $take is set
 * to whether the validating scan must accept it (jf_parse.c): well-formed, no escaped root key,
 * no duplicate referenced key, no referenced number yyjson reads as RAW, nothing nested deeper
 * than 64 below the root.
 */
function gen_record(array $typed, int $m, ?bool &$take = null): string
{
    $members = [];
    foreach ($typed as $key => $type) {
        if ($m !== 8 || mt_rand(0, 1)) {
            $members[] = [$key, gen_typed($type, $m === 9 && mt_rand(0, 1) === 0)];
        }
    }
    for ($i = mt_rand(0, 5); $i > 0; $i--) {
        $members[] = [pick(NOISE), gen_value()];
    }
    if ($m === 11) {
        $members[] = [pick(NOISE), pick(['[1}', '{"a":1]', '[[1]}', '{"k":[1}}', '[{"a":1]]'])];   // mismatched closing bracket
    }
    shuffle($members);
    if ($m === 0 && $members) {
        $members[] = pick($members);                             // duplicate key
    }
    $take = !in_array($m, [1, 3, 5, 10, 11], true);              // faults that always break the record
    $seen = [];
    $parts = [];
    foreach ($members as $i => [$key, $val]) {
        $kj = jstr($key);
        if ($m === 1 && $i === 0) {
            $kj = jstr($key, true);
        } elseif ($m === 2 && $i === 0 && $val[0] === '"') {
            $val = jstr('v', true);
            $take = false;
        }
        $ref = isset($typed[$key]);
        if (str_contains($kj, '\\') || ($ref && (isset($seen[$key]) || (preg_match('/^-?\d/', $val) && raw_number($val)))) || nesting($val) > 64) {
            $take = false;
        }
        $seen[$key] = true;
        $parts[] = ws() . $kj . ws() . ':' . ws() . $val . ws();
    }
    $rec = ws() . '{' . implode(',', $parts) . '}' . ws();
    switch ($m) {
        case 3:
            return $rec . pick(['x', ' {}', ',', '}', "\x00", "\x00 "]);
        case 4:
            $cut = mt_rand(0, max(0, strlen($rec) - 1));
            $take = $take && $cut >= strlen(rtrim($rec, " \t\r"));  // only trailing whitespace cut off
            return substr($rec, 0, $cut);
        case 5:
            return str_replace('}', ',}', $rec);
        case 6:
            $bad = preg_replace('/:\s*-?\d+/', ':' . pick(['01', '1.', '-', '+1', '1e', '.5', 'NaN', 'truE', 'nulL', 'falsE']), $rec, 1);
            $take = $take && $bad === $rec;                      // no number to break
            return $bad;
        case 7:
            $bad = pick(['[1]', '"s"', '1', 'null', '{}', '']);
            $take = $bad === '{}';
            return $bad;
        case 10:                                                 // a byte that is not JSON whitespace where whitespace may stand
            return substr_replace($rec, pick(["\x0c", "\x0b", "\xa0"]), pick([0, strpos($rec, '{') + 1, strlen($rec)]), 0);
        default:
            return $rec;
    }
}

function gen_plan(array &$typed): array
{
    $fields = [];
    $typed = [];
    for ($i = mt_rand(1, 4); $i > 0; $i--) {
        $key = pick(KEYS);
        $type = pick(TYPES);
        $typed[$key] = $type;
        $spec = $type . (mt_rand(0, 3) === 0 ? '?' : '');
        $fields[$key] = mt_rand(0, 5) === 0
            ? ['type' => $spec, 'default' => match ($type) { 'int64' => 3, 'float64' => 2.5, 'bool' => false, default => 'd' }]
            : $spec;
    }
    $where = [];
    for ($i = mt_rand(0, 2); $i > 0; $i--) {
        $key = pick(KEYS);
        $num = [0, 7, 10, 15, 20, -1, 1.5, 9007199254740993, PHP_INT_MAX, 1.8446744073709552e19];
        $str = ['', 'x', 'abc', 'bb', 'c', "\u{e9}", "a\0b"];
        $where[$key] = match (mt_rand(0, 4)) {
            0 => pick(array_merge($num, $str, [true, false, null])),
            1 => ['in' => [pick($num), pick($str), pick([true, false, null]), pick($num)]],
            2 => ['>=' => pick($num), '<' => pick($num)],
            3 => ['>' => pick($str), '<=' => pick($str)],
            default => ['=' => pick($num)],
        };
        $typed += [$key => pick(TYPES)];
    }
    $spec = ['fields' => $fields];
    if ($where) {
        $spec['where'] = $where;
    }
    if (mt_rand(0, 39) === 0) {
        $spec['maxRecordBytes'] = pick([16, 64, 256]);
    }
    if (mt_rand(0, 9) === 0) {
        $spec['skipEmptyLines'] = true;
    }
    return $spec;
}

ob_start();
(new ReflectionExtension('jsonfuse'))->info();
preg_match('/^validating scan => \w+$/m', ob_get_clean(), $kind);
fwrite(STDERR, ($kind[0] ?? 'validating scan => unknown') . "\n");
$enabled = ($kind[0] ?? '') === 'validating scan => enabled';

$stats = $enabled && jf10_scan_accepted() !== null;
if ($mode === 'gen') {
    mt_srand((int)($argv[2] ?? 1));
    $want = (int)($argv[3] ?? 1000);
    $records = 0;
    for ($case = 0; $records < $want; $case++) {
        $typed = [];
        $spec = gen_plan($typed);
        $n = mt_rand(1, 8);
        $lines = $takes = [];
        for ($i = 0; $i < $n; $i++) {
            // Only the last record may carry a fault, so the records before it are read.
            $lines[] = gen_record($typed, $i === $n - 1 && mt_rand(0, 2) === 0 ? mt_rand(0, 11) : -1, $take);
            $takes[] = $take;
        }
        $records += $n;
        $sep = isset($spec['skipEmptyLines']) ? pick(["\n", "\n\n", "\r\n"]) : pick(["\n", "\r\n"]);
        $input = implode($sep, $lines) . pick(['', "\n"]);
        /* The scan sees the records read before the first blank line refused or record over maxRecordBytes;
           the reader strips one CR before the LF. A record without a fault never fails after the scan. */
        $expect = 0;
        foreach ($lines as $i => $rec) {
            $length = strlen($rec) - ($sep === "\r\n" && $i < $n - 1 ? 0 : (int)str_ends_with($rec, "\r"));
            if ($length === 0 && isset($spec['skipEmptyLines'])) {
                continue;
            }
            if ($length === 0 || $length > ($spec['maxRecordBytes'] ?? PHP_INT_MAX)) {
                break;
            }
            $expect += (int)$takes[$i];
        }
        run_one("g$case", $input, $spec, mt_rand(0, 2) ? 0 : mt_rand(1, 16), $expect);
    }
    fwrite(STDERR, "runs=$runs records=$records\n");
} elseif ($mode === 'corpus') {
    $plans = [
        ['fields' => ['a' => 'string']],
        ['fields' => ['id' => 'int64', 'v' => 'float64?']],
        ['fields' => ['s' => 'string?', 'n' => 'int64?'], 'where' => ['b' => ['in' => [true, 1, 'x']]]],
        ['fields' => ['x' => ['type' => 'int64', 'default' => 0]], 'where' => ['k' => ['>=' => 0, '<' => 100]]],
    ];
    $files = [];
    foreach (array_slice($argv, 2) as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
            $files[] = $f->getPathname();
        }
    }
    sort($files);
    $lines = 0;
    foreach ($files as $fi => $path) {
        $data = file_get_contents($path);
        // Plan keys from the file's own root keys, so the scan finds referenced values.
        preg_match_all('/"((?:[^"\\\\]|\\\\.){0,40})"\s*:/', $data, $mk);
        $own = array_values(array_unique(array_filter(array_map(fn ($k) => json_decode("\"$k\""), $mk[1]), 'is_string')));
        $local = $plans;
        if ($own) {
            $local[] = ['fields' => [$own[0] => 'string?']];
            $local[] = ['fields' => array_fill_keys(array_slice($own, 0, 3), 'int64?')];
            $local[] = ['fields' => [$own[0] => 'float64?'], 'where' => [$own[count($own) - 1] => ['>' => '', '<' => "\u{10ffff}"]]];
        }
        foreach ($local as $pi => $spec) {
            run_one("c$fi.$pi", $data, $spec, 0);
        }
        foreach (explode("\n", $data) as $li => $line) {
            if ($line === '' || $li >= 64) {
                continue;
            }
            $lines++;
            foreach ($local as $pi => $spec) {
                run_one("c$fi.$pi.$li", $line, $spec, ($li & 3) === 3 ? 5 : 0);
            }
        }
    }
    fwrite(STDERR, 'files=' . count($files) . " lines=$lines runs=$runs\n");
} else {
    fwrite(STDERR, "usage: jf_scan_diff.inc gen <seed> <records> | corpus <dir>...\n");
    exit(2);
}
/* A build with -DJF_SCAN_STATS shows how many records the scan took; with the scan on, none, or in gen mode any run off the expected count, is a failure. */
$accepted = jf10_scan_accepted();
if ($accepted !== null) {
    fwrite(STDERR, "scan accepted=$accepted" . ($stats && $mode === 'gen' ? " expected=$expected mismatched runs=$mismatches" : '') . "\n");
    if ($stats && ($accepted === 0 || $mismatches > 0)) {
        fwrite(STDERR, $accepted === 0 ? "the validating scan accepted no record\n" : "the validating scan accepted other records than expected\n");
        exit(3);
    }
}
