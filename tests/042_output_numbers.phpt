--TEST--
jsonfuse: number output forms (int64 exact, float64 shortest round-trip with a fraction or exponent), IEEE bit round trips, independence from serialize_precision and precision
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/** Output line (without LF) of {"f": LITERAL} under a one-field plan, or the exception class. */
function one(string $literal, string $type = 'float64'): string
{
    $in = jf_mem('{"f":' . $literal . "}\n");
    $out = jf_out();
    try {
        $s = Runner::process($in, $out, Plan::compile(['fields' => ['f' => $type]]));
        $line = jf_out_bytes($out);
        if ($s->recordsEmitted !== 1 || $s->bytesWritten !== strlen($line) || substr($line, -1) !== "\n") {
            return 'BAD counters ' . jf_stats($s);
        }
        return substr($line, 0, -1);
    } catch (Throwable $e) {
        return get_class($e);
    } finally {
        fclose($in);
        fclose($out);
    }
}

/** The text between {"f": and }, or the raw line when the shape is wrong. */
function value_of(string $line): string
{
    return preg_match('/^\{"f":(.*)\}$/s', $line, $m) ? $m[1] : "BADSHAPE($line)";
}

function bits(float $f): string
{
    return bin2hex(pack('E', $f));
}

/** A real literal: has a fraction or an exponent, lower-case e, no +, no leading zeros, no bare integer. */
function is_real_token(string $t): bool
{
    return (bool)preg_match('/^-?(0|[1-9][0-9]*)(\.[0-9]+)?(e-?[1-9][0-9]*)?$/', $t) && (str_contains($t, '.') || str_contains($t, 'e'));
}

echo "-- float64: the emitted forms of the number table\n";
$exact = [
    ['0', '0.0'], ['5', '5.0'], ['-5', '-5.0'], ['-0', '0.0'], ['0.0', '0.0'], ['0e0', '0.0'],
    ['1.0', '1.0'], ['1e3', '1000.0'], ['1E3', '1000.0'], ['1e+3', '1000.0'], ['10E-1', '1.0'],
    ['-0.0', '-0.0'], ['-0e5', '-0.0'], ['-0E+9', '-0.0'],
    ['1e-400', '0.0'], ['-1e-400', '-0.0'], ['1e-324', '0.0'], ['-1e-324', '-0.0'],
    ['5e-324', '5e-324'], ['4.9e-324', '5e-324'], ['3e-324', '5e-324'], ['-5e-324', '-5e-324'],
    ['100000000000000000000', '100000000000000000000.0'], ['1e20', '100000000000000000000.0'], ['-1e20', '-100000000000000000000.0'],
    ['1e21', '1e21'], ['1000000000000000000000', '1e21'], ['-1e21', '-1e21'], ['1e22', '1e22'], ['1.5e21', '1.5e21'],
    ['1e308', '1e308'], ['1.7976931348623157e308', '1.7976931348623157e308'], ['-1.7976931348623157e308', '-1.7976931348623157e308'],
    ['0.1', '0.1'], ['0.5', '0.5'], ['1.5', '1.5'], ['2.5', '2.5'], ['-1.5', '-1.5'], ['123456.789', '123456.789'],
    ['0.30000000000000004', '0.30000000000000004'], ['0.1000000000000000055511151231257827', '0.1'],
    ['9007199254740992', '9007199254740992.0'], ['9007199254740993', '9007199254740992.0'], ['9007199254740995', '9007199254740996.0'],
    ['4503599627370497.5', '4503599627370498.0'],
    ['1.00000000000000011102230246251565404236316680908203125', '1.0'],
    ['1.00000000000000011102230246251565404236316680908203126', '1.0000000000000002'],
];
foreach ($exact as [$lit, $want]) {
    $got = value_of(one($lit));
    echo str_pad(strlen($lit) > 36 ? substr($lit, 0, 33) . '...' : $lit, 38), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
}

echo "-- float64: values whose text the spec leaves open are checked by IEEE bits and token shape\n";
$open = [
    '9223372036854775807', '-9223372036854775808', '9223372036854775808', '18446744073709551615', '18446744073709551616',
    '-9223372036854775809', '123456789012345678901234567890', '-123456789012345678901234567890',
    '1.7976931348623157e308', '2.2250738585072014e-308', '2.2250738585072011e-308', '1e-7', '1.5e-5', '0.001', '0.0001234', '1e-300',
    '0.01', '0.09999999999999999', '99999999999999991611392', '999999999999999868929', '1e-6', '1.2345e-10',
];
foreach ($open as $lit) {
    $line = one($lit);
    $tok = value_of($line);
    $want = (float)$lit;
    $back = is_real_token($tok) ? json_decode($tok) : null;
    $okBits = is_float($back) && bits($back) === bits($want);
    $text = JfRef::floatText($want);
    $okText = $text === null || $text === $tok;
    echo str_pad(strlen($lit) > 36 ? substr($lit, 0, 33) . '...' : $lit, 38),
        'real token: ', var_export(is_real_token($tok), true), ', bits: ', var_export($okBits, true), ', text where determined: ', var_export($okText, true), "\n";
}

echo "-- float64: rejected values write nothing\n";
foreach (['1e400', '-1e400', str_repeat('9', 400), '-' . str_repeat('9', 400), '1.7976931348623159e308', '"1.5"', 'null', 'true', '[]'] as $lit) {
    $in = jf_mem('{"f":' . $lit . "}\n");
    $out = jf_out();
    echo str_pad(strlen($lit) > 36 ? strlen($lit) . ' chars' : $lit, 38);
    try {
        Runner::process($in, $out, Plan::compile(['fields' => ['f' => 'float64']]));
        echo "FAIL no exception\n";
    } catch (Throwable $e) {
        echo get_class($e), ' output=', strlen(jf_out_bytes($out)), "\n";
    }
    fclose($in);
    fclose($out);
}

echo "-- int64: exact decimal text, never a float form\n";
$ints = [
    ['0', '0'], ['-0', '0'], ['5', '5'], ['-5', '-5'], ['10', '10'], ['100', '100'], ['-1', '-1'],
    ['9223372036854775807', '9223372036854775807'], ['-9223372036854775808', '-9223372036854775808'], ['-9223372036854775807', '-9223372036854775807'],
    ['9007199254740993', '9007199254740993'], ['-9007199254740993', '-9007199254740993'], ['9007199254740991', '9007199254740991'],
    ['1234567890123456789', '1234567890123456789'], ['4611686018427387904', '4611686018427387904'], ['4611686018427387903', '4611686018427387903'],
];
foreach ($ints as [$lit, $want]) {
    $got = value_of(one($lit, 'int64'));
    echo str_pad($lit, 38), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
}
echo "-- int64: rejected values write nothing\n";
foreach (['1.0', '1e3', '-0.0', '9223372036854775808', '-9223372036854775809', '18446744073709551615', '"5"'] as $lit) {
    $in = jf_mem('{"f":' . $lit . "}\n");
    $out = jf_out();
    echo str_pad($lit, 38);
    try {
        Runner::process($in, $out, Plan::compile(['fields' => ['f' => 'int64']]));
        echo "FAIL no exception\n";
    } catch (Throwable $e) {
        echo get_class($e), ' output=', strlen(jf_out_bytes($out)), "\n";
    }
    fclose($in);
    fclose($out);
}

echo "-- bool and null in the same record keep their words\n";
$in = jf_mem('{"b":true,"n":null,"c":false,"f":5,"i":-0}' . "\n");
$out = jf_out();
Runner::process($in, $out, Plan::compile(['fields' => ['i' => 'int64', 'f' => 'float64', 'c' => 'bool', 'n' => 'float64?', 'b' => 'bool']]));
echo jf_out_bytes($out);
fclose($in);
fclose($out);

/** Run a whole NDJSON text through {f: float64} (or int64) and return the output lines. */
function batch(array $lits, string $type): array
{
    $in = jf_mem(implode('', array_map(fn($l) => '{"f":' . $l . "}\n", $lits)));
    $out = jf_out();
    $s = Runner::process($in, $out, Plan::compile(['fields' => ['f' => $type]]));
    $bytes = jf_out_bytes($out);
    fclose($in);
    fclose($out);
    if ($s->recordsEmitted !== count($lits) || $s->bytesWritten !== strlen($bytes)) {
        return ['BAD counters ' . jf_stats($s)];
    }
    return explode("\n", substr($bytes, 0, -1));
}

echo "-- float64 round trips by IEEE bits: 4000 doubles from random bit patterns, three input spellings\n";
$rng = new JfRng(42042);
$vals = [];
while (count($vals) < 4000) {
    $v = unpack('E', pack('NN', $rng->next(), $rng->next()))[1];
    if (is_finite($v)) {
        $vals[] = $v;
    }
}
// boundaries around powers of two and ten, denormals, neighbours of the largest values
foreach ([1.0, 2.0, 0.5, 1e15, 1e16, 1e20, 1e21, 1e22, 9.999999999999999e20, 1.0000000000000002e21, 4.9e-324, 2.2250738585072014e-308, 2.225073858507201e-308,
    1.7976931348623157e308, 9007199254740992.0, 9007199254740994.0, 4503599627370497.0, 0.1, 0.2, 0.3, 1 / 3, 2 / 3, 123456789.125, 5e-324 * 3] as $v) {
    $vals[] = $v;
    $vals[] = -$v;
}
ini_set('serialize_precision', '-1');
$spell = [
    'shortest' => fn(float $v): string => json_encode($v, JSON_PRESERVE_ZERO_FRACTION),
    'long' => fn(float $v): string => sprintf('%.20e', $v),
    'plain' => fn(float $v): string => is_finite($v) && abs($v) < 1e15 && floor($v) === $v ? sprintf('%d', $v) : sprintf('%.17e', $v),
];
foreach ($spell as $name => $fn) {
    $lits = array_map($fn, $vals);
    $lines = batch($lits, 'float64');
    $badBits = 0;
    $badShape = 0;
    $badText = 0;
    foreach ($vals as $i => $v) {
        $tok = isset($lines[$i]) ? value_of($lines[$i]) : 'MISSING';
        if (!is_real_token($tok)) {
            $badShape++;
            continue;
        }
        $back = json_decode($tok);
        if (!is_float($back) || bits($back) !== bits((float)$lits[$i])) {
            $badBits++;
        }
        if ((float)$lits[$i] !== $v && $name === 'shortest') {
            $badBits++;      // the spelling itself must name the double
        }
        $t = JfRef::floatText((float)$lits[$i]);
        if ($t !== null && $t !== $tok) {
            $badText++;
        }
    }
    echo str_pad($name, 10), count($vals), ' values, lines: ', count($lines), ', not a real token: ', $badShape, ', bit mismatches: ', $badBits, ', text mismatches: ', $badText, "\n";
}

echo "-- int64 round trips: random 64-bit integers, exact text\n";
$ints = [PHP_INT_MAX, PHP_INT_MIN, 0, 1, -1, 2 ** 53, -(2 ** 53), 2 ** 53 + 1, -(2 ** 53) - 1, 2 ** 62, -(2 ** 62), PHP_INT_MAX - 1, PHP_INT_MIN + 1];
while (count($ints) < 3000) {
    $v = ($rng->next() << 32) | $rng->next();
    $ints[] = $v >> $rng->int(63);
}
$lines = batch(array_map('strval', $ints), 'int64');
$bad = 0;
foreach ($ints as $i => $v) {
    if (($lines[$i] ?? null) !== '{"f":' . $v . '}') {
        $bad++;
    }
}
echo count($ints), ' integers, lines: ', count($lines), ', mismatches: ', $bad, "\n";

echo "-- output does not depend on serialize_precision or precision\n";
$lits = array_merge(array_column($exact, 0), $open);
$base = null;
$all = [];
foreach (['-1', '0', '1', '3', '5', '17', '40'] as $sp) {
    foreach (['1', '14', '17', '-1'] as $p) {
        ini_set('serialize_precision', $sp);
        ini_set('precision', $p);
        $lines = batch($lits, 'float64');
        $all[$sp . '/' . $p] = sha1(implode("\n", $lines));
    }
}
ini_set('serialize_precision', '-1');
ini_set('precision', '14');
echo 'distinct outputs over 28 settings: ', count(array_unique($all)), "\n";
echo 'same as the default settings: ', var_export(sha1(implode("\n", batch($lits, 'float64'))) === $all['-1/14'], true), "\n";
?>
--EXPECT--
-- float64: the emitted forms of the number table
0                                     ok
5                                     ok
-5                                    ok
-0                                    ok
0.0                                   ok
0e0                                   ok
1.0                                   ok
1e3                                   ok
1E3                                   ok
1e+3                                  ok
10E-1                                 ok
-0.0                                  ok
-0e5                                  ok
-0E+9                                 ok
1e-400                                ok
-1e-400                               ok
1e-324                                ok
-1e-324                               ok
5e-324                                ok
4.9e-324                              ok
3e-324                                ok
-5e-324                               ok
100000000000000000000                 ok
1e20                                  ok
-1e20                                 ok
1e21                                  ok
1000000000000000000000                ok
-1e21                                 ok
1e22                                  ok
1.5e21                                ok
1e308                                 ok
1.7976931348623157e308                ok
-1.7976931348623157e308               ok
0.1                                   ok
0.5                                   ok
1.5                                   ok
2.5                                   ok
-1.5                                  ok
123456.789                            ok
0.30000000000000004                   ok
0.1000000000000000055511151231257827  ok
9007199254740992                      ok
9007199254740993                      ok
9007199254740995                      ok
4503599627370497.5                    ok
1.0000000000000001110223024625156...  ok
1.0000000000000001110223024625156...  ok
-- float64: values whose text the spec leaves open are checked by IEEE bits and token shape
9223372036854775807                   real token: true, bits: true, text where determined: true
-9223372036854775808                  real token: true, bits: true, text where determined: true
9223372036854775808                   real token: true, bits: true, text where determined: true
18446744073709551615                  real token: true, bits: true, text where determined: true
18446744073709551616                  real token: true, bits: true, text where determined: true
-9223372036854775809                  real token: true, bits: true, text where determined: true
123456789012345678901234567890        real token: true, bits: true, text where determined: true
-123456789012345678901234567890       real token: true, bits: true, text where determined: true
1.7976931348623157e308                real token: true, bits: true, text where determined: true
2.2250738585072014e-308               real token: true, bits: true, text where determined: true
2.2250738585072011e-308               real token: true, bits: true, text where determined: true
1e-7                                  real token: true, bits: true, text where determined: true
1.5e-5                                real token: true, bits: true, text where determined: true
0.001                                 real token: true, bits: true, text where determined: true
0.0001234                             real token: true, bits: true, text where determined: true
1e-300                                real token: true, bits: true, text where determined: true
0.01                                  real token: true, bits: true, text where determined: true
0.09999999999999999                   real token: true, bits: true, text where determined: true
99999999999999991611392               real token: true, bits: true, text where determined: true
999999999999999868929                 real token: true, bits: true, text where determined: true
1e-6                                  real token: true, bits: true, text where determined: true
1.2345e-10                            real token: true, bits: true, text where determined: true
-- float64: rejected values write nothing
1e400                                 JsonFuse\RecordException output=0
-1e400                                JsonFuse\RecordException output=0
400 chars                             JsonFuse\RecordException output=0
401 chars                             JsonFuse\RecordException output=0
1.7976931348623159e308                JsonFuse\RecordException output=0
"1.5"                                 JsonFuse\RecordException output=0
null                                  JsonFuse\RecordException output=0
true                                  JsonFuse\RecordException output=0
[]                                    JsonFuse\RecordException output=0
-- int64: exact decimal text, never a float form
0                                     ok
-0                                    ok
5                                     ok
-5                                    ok
10                                    ok
100                                   ok
-1                                    ok
9223372036854775807                   ok
-9223372036854775808                  ok
-9223372036854775807                  ok
9007199254740993                      ok
-9007199254740993                     ok
9007199254740991                      ok
1234567890123456789                   ok
4611686018427387904                   ok
4611686018427387903                   ok
-- int64: rejected values write nothing
1.0                                   JsonFuse\RecordException output=0
1e3                                   JsonFuse\RecordException output=0
-0.0                                  JsonFuse\RecordException output=0
9223372036854775808                   JsonFuse\RecordException output=0
-9223372036854775809                  JsonFuse\RecordException output=0
18446744073709551615                  JsonFuse\RecordException output=0
"5"                                   JsonFuse\RecordException output=0
-- bool and null in the same record keep their words
{"i":0,"f":5.0,"c":false,"n":null,"b":true}
-- float64 round trips by IEEE bits: 4000 doubles from random bit patterns, three input spellings
shortest  4048 values, lines: 4048, not a real token: 0, bit mismatches: 0, text mismatches: 0
long      4048 values, lines: 4048, not a real token: 0, bit mismatches: 0, text mismatches: 0
plain     4048 values, lines: 4048, not a real token: 0, bit mismatches: 0, text mismatches: 0
-- int64 round trips: random 64-bit integers, exact text
3000 integers, lines: 3000, mismatches: 0
-- output does not depend on serialize_precision or precision
distinct outputs over 28 settings: 1
same as the default settings: true
