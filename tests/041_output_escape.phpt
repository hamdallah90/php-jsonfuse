--TEST--
jsonfuse: string values and field names are written byte-identical to json_encode with UNESCAPED_UNICODE, UNESCAPED_SLASHES and UNESCAPED_LINE_TERMINATORS
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

const F = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;

/** Four spellings of the same string as an input JSON literal; the output must not depend on them. */
function spell(string $s, string $how): string
{
    switch ($how) {
        case 'default':             // json_encode defaults: \/ and \uXXXX with surrogate pairs
            return json_encode($s, JSON_THROW_ON_ERROR);
        case 'raw':                 // the output form itself
            return json_encode($s, F);
        case 'upper':               // every code point as an upper-case \uXXXX escape
            $o = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $cp = cp_of($ch);
                if ($cp >= 0x10000) {
                    $c = $cp - 0x10000;
                    $o .= sprintf('\u%04X\u%04X', 0xD800 + ($c >> 10), 0xDC00 + ($c & 0x3FF));
                } else {
                    $o .= sprintf('\u%04X', $cp);
                }
            }
            return '"' . $o . '"';
        default:                    // short escapes where JSON has them, else lower-case \u00xx, raw above ASCII
            $o = '';
            foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $o .= match (true) {
                    $ch === '/' => '\/',
                    $ch === '"' => '\"',
                    $ch === '\\' => '\\\\',
                    $ch === "\x08" => '\b',
                    $ch === "\x0c" => '\f',
                    $ch === "\n" => '\n',
                    $ch === "\r" => '\r',
                    $ch === "\t" => '\t',
                    strlen($ch) === 1 && ord($ch) < 0x20 => sprintf('\u%04x', ord($ch)),
                    default => $ch,
                };
            }
            return '"' . $o . '"';
    }
}

/** Code point of one UTF-8 encoded character. */
function cp_of(string $ch): int
{
    $b = array_values(unpack('C*', $ch));
    return match (count($b)) {
        1 => $b[0],
        2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
        3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
        default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
    };
}

const HOWS = ['default', 'raw', 'upper', 'short'];

/** Output of one run of {"s": <literal>} with the plan {s: string}. */
function emit(string $literal): string
{
    $in = jf_mem('{"s":' . $literal . "}\n");
    $out = jf_out();
    try {
        Runner::process($in, $out, Plan::compile(['fields' => ['s' => 'string']]));
        return jf_out_bytes($out);
    } catch (Throwable $e) {
        return get_class($e);
    } finally {
        fclose($in);
        fclose($out);
    }
}

/** True when every spelling of $s comes out as '{"s":' . json_encode($s, F) . "}\n". */
function check(string $s, ?string &$why = null): bool
{
    $want = '{"s":' . json_encode($s, F) . "}\n";
    foreach (HOWS as $how) {
        $got = emit(spell($s, $how));
        if ($got !== $want) {
            $why = "$how: got " . bin2hex($got) . ' want ' . bin2hex($want);
            return false;
        }
    }
    return true;
}

function row(string $name, string $s): void
{
    echo str_pad($name, 44), check($s, $why) ? 'ok' : "FAIL $why", "\n";
}

echo "-- every control byte on its own and between letters\n";
$bad = [];
for ($i = 0; $i < 32; $i++) {
    foreach ([chr($i), 'a' . chr($i) . 'b', chr($i) . chr($i)] as $s) {
        if (!check($s, $why)) {
            $bad[] = sprintf('%02x %s', $i, $why);
        }
    }
}
echo 'control bytes 0x00-0x1f: ', $bad ? 'FAIL ' . implode('; ', array_slice($bad, 0, 3)) : 'ok', "\n";
row('all 32 control bytes in a row', implode('', array_map('chr', range(0, 31))));
row('all 32 control bytes, reversed', implode('', array_map('chr', range(31, 0))));

echo "-- the exact forms, written out\n";
$exact = [
    ['NUL', "\0", '"\u0000"'],
    ['SOH', "\x01", '"\u0001"'],
    ['backspace', "\x08", '"\b"'],
    ['tab', "\t", '"\t"'],
    ['LF', "\n", '"\n"'],
    ['VT', "\x0b", '"\u000b"'],
    ['form feed', "\x0c", '"\f"'],
    ['CR', "\r", '"\r"'],
    ['0x0e', "\x0e", '"\u000e"'],
    ['0x1f, lower-case hex', "\x1f", '"\u001f"'],
    ['quote', '"', '"\""'],
    ['backslash', '\\', '"\\\\"'],
    ['slash is raw', '/', '"/"'],
    ['DEL is raw', "\x7f", "\"\x7f\""],
    ['U+2028 is raw', "\u{2028}", "\"\u{2028}\""],
    ['U+2029 is raw', "\u{2029}", "\"\u{2029}\""],
    ['U+00E9 is raw', "\u{e9}", "\"\u{e9}\""],
    ['U+1F600 is raw', "\u{1F600}", "\"\u{1F600}\""],
    ['empty string', '', '""'],
    ['backslash followed by u0041 text', '\\u0041', '"\\\\u0041"'],
    ['a\\u0000b', "a\0b", '"a\u0000b"'],
];
foreach ($exact as [$name, $s, $literal]) {
    $got = emit(spell($s, 'default'));
    $want = '{"s":' . $literal . "}\n";
    echo str_pad($name, 44), $got === $want ? 'ok' : 'FAIL got ' . bin2hex($got) . ' want ' . bin2hex($want), "\n";
}

echo "-- quote, backslash, slash, DEL, line separators and their neighbours\n";
foreach ([
    ['quote alone', '"'], ['two quotes', '""'], ['backslash alone', '\\'], ['backslash quote', '\\"'], ['quote backslash', '"\\'],
    ['two backslashes', '\\\\'], ['three backslashes', '\\\\\\'], ['slash alone', '/'], ['slashes', '//a//'], ['backslash slash', '\\/'],
    ['DEL alone', "\x7f"], ['DEL between letters', "a\x7fb"], ['0x7e 0x7f', "~\x7f"],
    ['U+2028', "\u{2028}"], ['U+2029', "\u{2029}"], ['U+2028 U+2029 mixed with LF', "\u{2028}\n\u{2029}\r\n"], ['U+2027 U+202A', "\u{2027}\u{202A}"],
    ['html-ish characters stay raw', '<a href="x">&amp;\'</a>'], ['a path', 'C:\\dir\\file/sub/x.txt'],
    ['json text inside a string', '{"a":[1,"b\\n"]}'],
] as [$name, $s]) {
    row($name, $s);
}

echo "-- multi-byte boundaries\n";
$bad = [];
foreach ([0x7F, 0x80, 0x9F, 0xA0, 0xBF, 0xC0, 0xFF, 0x100, 0x7FF, 0x800, 0x0FFF, 0x1000, 0xD7FF, 0xE000, 0xF8FF, 0xFEFF, 0xFFFD, 0xFFFE, 0xFFFF,
    0x10000, 0x1F600, 0x1FFFF, 0x20000, 0xFFFFF, 0x100000, 0x10FFFF, 0x85, 0x2028, 0x2029, 0x200B, 0x202E] as $cp) {
    $ch = jf_chr($cp);
    foreach (["$ch", "a$ch", "{$ch}b", "a{$ch}b", "$ch$ch"] as $s) {
        if (!check($s, $why)) {
            $bad[] = sprintf('U+%04X %s', $cp, $why);
        }
    }
}
echo 'code points at every UTF-8 length boundary: ', $bad ? 'FAIL ' . implode('; ', array_slice($bad, 0, 3)) : 'ok', "\n";
row('2-byte run', str_repeat("\u{e9}\u{7FF}", 50));
row('3-byte run', str_repeat("\u{800}\u{FFFF}", 50));
row('4-byte run', str_repeat("\u{10000}\u{10FFFF}", 50));
row('mixed lengths with ASCII', "a\u{e9}b\u{20AC}c\u{1F600}d\u{7FF}e\u{FFFF}f\u{10000}");
row('long run crossing 8192 and 65536 bytes', str_repeat("a\u{e9}\u{20AC}\u{1F600}\"\\/\n\x01", 9000));

echo "-- every ASCII character\n";
$all = implode('', array_map('chr', range(0, 127)));
row('0x00-0x7f in order', $all);
row('0x00-0x7f reversed', strrev($all));
$bad = [];
for ($i = 0; $i < 128; $i++) {
    if (!check(chr($i), $why)) {
        $bad[] = sprintf('%02x %s', $i, $why);
    }
}
echo 'each of the 128 ASCII characters alone: ', $bad ? 'FAIL ' . implode('; ', array_slice($bad, 0, 3)) : 'ok', "\n";

echo "-- seeded strings from the whole table, each character spelled in a random way\n";
$rng = new JfRng(41041);
$pool = [];
for ($i = 0; $i < 128; $i++) {
    $pool[] = chr($i);
}
foreach ([0x80, 0xE9, 0x7FF, 0x800, 0x20AC, 0x2028, 0x2029, 0xD7FF, 0xE000, 0xFFFD, 0xFFFF, 0x10000, 0x1F600, 0x10FFFF] as $cp) {
    $pool[] = jf_chr($cp);
    $pool[] = jf_chr($cp);
}
$bad = 0;
$total = 0;
for ($n = 0; $n < 400; $n++) {
    $s = '';
    $lit = '';
    for ($k = $rng->int(60); $k > 0; $k--) {
        $ch = $rng->pick($pool);
        $s .= $ch;
        $lit .= substr(spell($ch, $rng->pick(HOWS)), 1, -1);
    }
    $got = emit('"' . $lit . '"');
    $total += strlen($s);
    if ($got !== '{"s":' . json_encode($s, F) . "}\n") {
        $bad++;
    }
}
echo '400 strings, ', $total > 5000 ? 'many' : 'few', ' bytes, mismatches: ', $bad, "\n";

echo "-- field names go through the same escape table\n";
$names = [];
for ($i = 0; $i < 32; $i++) {
    $names[] = 'k' . chr($i);
    $names[] = chr($i);
}
array_push($names, 'q"uote', '"', 'back\\slash', '\\', 'sl/ash', '/', "del\x7f", "\x7f", "ls\u{2028}", "\u{2028}", "ps\u{2029}", "\u{2029}",
    "caf\u{e9}", "\u{1F600}", "\u{10FFFF}", "\u{FFFF}", "\u{800}", "\u{7FF}", 'a\\u0041', "tab\tx", "nul\0in\0side", '__proto__', ' ', '');
$names = array_values(array_unique($names));
$fields = array_fill_keys($names, 'int64');
echo 'plan has ', count($names), " distinct names\n";
$plan = Plan::compile(['fields' => $fields]);
$members = [];
$exp = [];
foreach ($names as $i => $name) {
    $exp[] = json_encode($name, F) . ':' . $i;
}
$rev = array_reverse(array_keys($names));
foreach ($rev as $i) {
    $members[] = json_encode($names[$i]) . ':' . $i;      // input spelling: json_encode defaults
}
$in = jf_mem('{' . implode(',', $members) . "}\n");
$out = jf_out();
$s = Runner::process($in, $out, $plan);
$got = jf_out_bytes($out);
$want = '{' . implode(',', $exp) . "}\n";
echo jf_stats($s) === sprintf('read=1 matched=1 emitted=1 bytesRead=%d bytesWritten=%d', strlen('{' . implode(',', $members) . "}\n"), strlen($want)) ? "counters ok\n" : "counters FAIL\n";
echo 'names written byte-identical, schema order: ', var_export($got === $want, true), "\n";
fclose($in);
fclose($out);

echo "-- exact forms of a few names\n";
foreach ([
    ["a\x01b", '{"a\u0001b":1}'],
    ["q\"\\/", '{"q\"\\\\/":1}'],
    ["\x7f\u{2028}", "{\"\x7f\u{2028}\":1}"],
    ["\0", '{"\u0000":1}'],
    ["\x1f", '{"\u001f":1}'],
    ["\x08\x0c\n\r\t", '{"\b\f\n\r\t":1}'],
] as [$name, $want]) {
    $in = jf_mem(json_encode([$name => 1]) . "\n");
    $out = jf_out();
    Runner::process($in, $out, Plan::compile(['fields' => [$name => 'int64']]));
    $got = jf_out_bytes($out);
    echo str_pad(bin2hex($name), 24), $got === $want . "\n" ? 'ok' : 'FAIL got ' . bin2hex($got), "\n";
    fclose($in);
    fclose($out);
}

echo "-- a value and a name with the same bytes are escaped the same way\n";
$name = "x\"\\/\x01\u{2028}";
$in = jf_mem(json_encode([$name => $name]) . "\n");
$out = jf_out();
Runner::process($in, $out, Plan::compile(['fields' => [$name => 'string']]));
$got = jf_out_bytes($out);
echo var_export($got === '{' . json_encode($name, F) . ':' . json_encode($name, F) . "}\n", true), "\n";
fclose($in);
fclose($out);

echo "-- the output of every string is valid JSON that decodes to the original\n";
$bad = 0;
foreach ([$all, "\u{2028}\u{2029}", "\x01\x1f\"\\/", "\u{10FFFF}\0", str_repeat("\u{e9}", 100)] as $s) {
    $got = emit(spell($s, 'default'));
    $back = json_decode($got, true);
    if (!is_array($back) || $back['s'] !== $s) {
        $bad++;
    }
}
echo 'round trip failures: ', $bad, "\n";
?>
--EXPECT--
-- every control byte on its own and between letters
control bytes 0x00-0x1f: ok
all 32 control bytes in a row               ok
all 32 control bytes, reversed              ok
-- the exact forms, written out
NUL                                         ok
SOH                                         ok
backspace                                   ok
tab                                         ok
LF                                          ok
VT                                          ok
form feed                                   ok
CR                                          ok
0x0e                                        ok
0x1f, lower-case hex                        ok
quote                                       ok
backslash                                   ok
slash is raw                                ok
DEL is raw                                  ok
U+2028 is raw                               ok
U+2029 is raw                               ok
U+00E9 is raw                               ok
U+1F600 is raw                              ok
empty string                                ok
backslash followed by u0041 text            ok
a\u0000b                                    ok
-- quote, backslash, slash, DEL, line separators and their neighbours
quote alone                                 ok
two quotes                                  ok
backslash alone                             ok
backslash quote                             ok
quote backslash                             ok
two backslashes                             ok
three backslashes                           ok
slash alone                                 ok
slashes                                     ok
backslash slash                             ok
DEL alone                                   ok
DEL between letters                         ok
0x7e 0x7f                                   ok
U+2028                                      ok
U+2029                                      ok
U+2028 U+2029 mixed with LF                 ok
U+2027 U+202A                               ok
html-ish characters stay raw                ok
a path                                      ok
json text inside a string                   ok
-- multi-byte boundaries
code points at every UTF-8 length boundary: ok
2-byte run                                  ok
3-byte run                                  ok
4-byte run                                  ok
mixed lengths with ASCII                    ok
long run crossing 8192 and 65536 bytes      ok
-- every ASCII character
0x00-0x7f in order                          ok
0x00-0x7f reversed                          ok
each of the 128 ASCII characters alone: ok
-- seeded strings from the whole table, each character spelled in a random way
400 strings, many bytes, mismatches: 0
-- field names go through the same escape table
plan has 88 distinct names
counters ok
names written byte-identical, schema order: true
-- exact forms of a few names
610162                  ok
71225c2f                ok
7fe280a8                ok
00                      ok
1f                      ok
080c0a0d09              ok
-- a value and a name with the same bytes are escaped the same way
true
-- the output of every string is valid JSON that decodes to the original
round trip failures: 0
