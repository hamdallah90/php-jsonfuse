--TEST--
jsonfuse: field types int64, float64, bool, string and nullable variants - number rules table, missing vs null, wrong JSON types
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;

// No where: an accepted record always matches (recordsMatched 1), a rejected one raises
// RecordException. jf_v() also checks recordsEmitted = recordsMatched, bytesWritten = output length, and that a
// non-match or a rejected record leaves the output empty.

// SPEC.md section 5 number rules: [literal, int64 accepted, float64 accepted]
$d400 = str_repeat('9', 400);
$one_e308 = '1' . str_repeat('0', 308);   // 309 digits, 1e308: finite
$one_e309 = '1' . str_repeat('0', 309);   // 310 digits, 1e309: rounds to infinity
$numbers = [
    ['0', true, true],
    ['5', true, true],
    ['-5', true, true],
    ['-0', true, true],
    ['123456789012345678', true, true],
    ['9007199254740993', true, true],
    ['1.0', false, true],
    ['1e3', false, true],
    ['1E3', false, true],
    ['1e+3', false, true],
    ['1e-3', false, true],
    ['10E-1', false, true],
    ['0.5', false, true],
    ['0.0', false, true],
    ['0e0', false, true],
    ['-0.0', false, true],
    ['-0e5', false, true],
    ['3.141592653589793', false, true],
    ['9223372036854775806', true, true],
    ['9223372036854775807', true, true],
    ['-9223372036854775807', true, true],
    ['-9223372036854775808', true, true],
    ['9223372036854775808', false, true],
    ['9223372036854775809', false, true],
    ['10000000000000000000', false, true],
    ['18446744073709551614', false, true],
    ['18446744073709551615', false, true],
    ['18446744073709551616', false, true],
    ['-9223372036854775809', false, true],
    ['-18446744073709551616', false, true],
    ['99999999999999999999999999999', false, true],
    [$one_e308, false, true],
    [$one_e309, false, false],
    [$d400, false, false],
    ['-' . $d400, false, false],
    ['1e308', false, true],
    ['1.7976931348623157e308', false, true],
    ['1.7976931348623158e308', false, true],
    ['1.7976931348623159e308', false, false],
    ['-1.7976931348623157e308', false, true],
    ['-1.7976931348623159e308', false, false],
    ['1e309', false, false],
    ['1e400', false, false],
    ['-1e400', false, false],
    ['1E400', false, false],
    ['1e+400', false, false],
    ['1e999999999', false, false],
    ['-1e999999999', false, false],
    ['1e-400', false, true],
    ['-1e-400', false, true],
    ['1e-324', false, true],
    ['4.9e-324', false, true],
    ['01', false, false],
    ['-01', false, false],
    ['+1', false, false],
    ['.5', false, false],
    ['1.', false, false],
    ['1e', false, false],
    ['1e+', false, false],
    ['0x10', false, false],
    ['- 1', false, false],
    ['--1', false, false],
    ['NaN', false, false],
    ['Infinity', false, false],
    ['-Infinity', false, false],
];
foreach (['int64' => 1, 'float64' => 2] as $type => $col) {
    foreach ([$type, $type . '?'] as $t) {
        $rows = [];
        foreach ($numbers as $n) {
            $label = strlen($n[0]) > 40 ? substr($n[0], 0, 12) . '...(' . strlen($n[0]) . ' chars)' : $n[0];
            $rows[] = [$label, '{"n":' . $n[0] . '}', $n[$col] ? 'match' : 'error'];
        }
        jf_rows("number literals as $t", ['fields' => ['n' => $t]], $rows);
    }
}

echo "-- a number in an unreferenced key is never converted\n";
$f = ['n' => 'int64'];
jf_rows('unreferenced magnitude is irrelevant', ['fields' => $f], [
    ['1e400 elsewhere', '{"n":1,"u":1e400}', 'match'],
    ['400-digit integer elsewhere', '{"n":1,"u":' . $d400 . '}', 'match'],
    ['1e-400 elsewhere', '{"n":1,"u":-1e-400}', 'match'],
    ['1e400 before the field', '{"u":1e400,"n":1}', 'match'],
    ['RAW inside a nested array', '{"n":1,"u":[1e400,' . $d400 . ',{"v":-1e400}]}', 'match'],
]);

// What each declared type accepts among every JSON value kind. Spelled out, not computed.
$kinds = [
    'string x' => '"x"',
    'empty string' => '""',
    'numeric string' => '"5"',
    'string 1.5' => '"1.5"',
    'string true' => '"true"',
    'integer 5' => '5',
    'integer 0' => '0',
    'real 1.5' => '1.5',
    'real 1.0' => '1.0',
    'true' => 'true',
    'false' => 'false',
    'null' => 'null',
    'empty array' => '[]',
    'array [1]' => '[1]',
    'array of strings' => '["x"]',
    'empty object' => '{}',
    'object' => '{"a":1}',
];
$accepts = [
    'int64' => ['integer 5', 'integer 0'],
    'float64' => ['integer 5', 'integer 0', 'real 1.5', 'real 1.0'],
    'bool' => ['true', 'false'],
    'string' => ['string x', 'empty string', 'numeric string', 'string 1.5', 'string true'],
];
foreach ($accepts as $type => $ok) {
    foreach ([false, true] as $nullable) {
        $rows = [];
        foreach ($kinds as $label => $json) {
            $accepted = in_array($label, $ok, true) || ($nullable && $label === 'null');
            $rows[] = [$label, '{"v":' . $json . '}', $accepted ? 'match' : 'error'];
        }
        jf_rows("JSON value kinds against $type" . ($nullable ? '?' : ''), ['fields' => ['v' => $type . ($nullable ? '?' : '')]], $rows);
    }
}

jf_rows('string values', ['fields' => ['s' => 'string']], [
    ['non-ASCII', "{\"s\":\"caf\u{e9} \u{20ac} \u{1F600} \u{10FFFF}\"}", 'match'],
    ['escaped non-ASCII', '{"s":"caf\u00e9 \u20ac \ud83d\ude00"}', 'match'],
    ['all short escapes', '{"s":"\"\\\\\/\b\f\n\r\t"}', 'match'],
    ['\u0000 in a value is valid', '{"s":"a\u0000b"}', 'match'],
    ['control character escapes', '{"s":"\u0001\u001f\u007f"}', 'match'],
    ['U+2028 and U+2029 raw', "{\"s\":\"\u{2028}\u{2029}\"}", 'match'],
    ['BOM bytes inside a string', "{\"s\":\"\xEF\xBB\xBF\"}", 'match'],
    ['lone high surrogate escape', '{"s":"\ud800"}', 'error'],
    ['lone low surrogate escape', '{"s":"\udc00"}', 'error'],
    ['invalid UTF-8: overlong', "{\"s\":\"\xC0\x80\"}", 'error'],
    ['invalid UTF-8: truncated', "{\"s\":\"\xE2\x82\"}", 'error'],
    ['invalid UTF-8: surrogate bytes', "{\"s\":\"\xED\xA0\x80\"}", 'error'],
    ['invalid UTF-8: above U+10FFFF', "{\"s\":\"\xF4\x90\x80\x80\"}", 'error'],
    ['raw TAB in a string', "{\"s\":\"a\tb\"}", 'error'],
    ['raw NUL in a string', "{\"s\":\"a\0b\"}", 'error'],
    ['unterminated string', '{"s":"abc}', 'error'],
]);

echo "-- missing is not null, and nullable is not optional\n";
$n = ['fields' => ['a' => 'int64?']];
jf_rows('one nullable field', $n, [
    ['present with a value', '{"a":1}', 'match'],
    ['present with null', '{"a":null}', 'match'],
    ['absent: empty object', '{}', 'error'],
    ['absent: other keys only', '{"b":1}', 'error'],
    ['absent: null under another key', '{"b":null}', 'error'],
    ['absent: key differs by case', '{"A":null}', 'error'],
    ['absent: key with a trailing space', '{"a ":null}', 'error'],
    ['absent: key is a prefix', '{"":null}', 'error'],
    ['absent: only nested', '{"o":{"a":null}}', 'error'],
    ['absent: only in an array element', '{"o":[{"a":null}]}', 'error'],
    ['wrong type, not null', '{"a":"1"}', 'error'],
]);
jf_rows('non-nullable fields reject null', ['fields' => ['a' => 'int64', 'b' => 'string', 'c' => 'bool', 'd' => 'float64']], [
    ['all present', '{"a":1,"b":"","c":false,"d":0}', 'match'],
    ['a null', '{"a":null,"b":"","c":false,"d":0}', 'error'],
    ['b null', '{"a":1,"b":null,"c":false,"d":0}', 'error'],
    ['c null', '{"a":1,"b":"","c":null,"d":0}', 'error'],
    ['d null', '{"a":1,"b":"","c":false,"d":null}', 'error'],
    ['d absent', '{"a":1,"b":"","c":false}', 'error'],
    ['a absent', '{"b":"","c":false,"d":0}', 'error'],
]);
jf_rows('falsy values are not null in nullable fields', ['fields' => ['a' => 'int64?', 'b' => 'string?', 'c' => 'bool?', 'd' => 'float64?']], [
    ['0, "", false, 0.0', '{"a":0,"b":"","c":false,"d":0.0}', 'match'],
    ['all null', '{"a":null,"b":null,"c":null,"d":null}', 'match'],
    ['nulls and values mixed', '{"a":null,"b":"x","c":null,"d":1.5}', 'match'],
    ['one of four absent', '{"a":null,"b":null,"c":null}', 'error'],
    ['one wrong among nulls', '{"a":null,"b":null,"c":0,"d":null}', 'error'],
]);

$all = ['i' => 'int64', 'f' => 'float64', 'b' => 'bool', 's' => 'string', 'ni' => 'int64?', 'nf' => 'float64?', 'nb' => 'bool?', 'ns' => 'string?'];
$good = '"i":-7,"f":2.5e3,"b":true,"s":"x","ni":null,"nf":3,"nb":false,"ns":null';
jf_rows('all eight type forms in one plan', ['fields' => $all], [
    ['valid', "{{$good}}", 'match'],
    ['valid, extra keys of every kind', "{{$good},\"x1\":[1,{\"y\":null}],\"x2\":{},\"x3\":1e400,\"x4\":\"\u0000\"}", 'match'],
    ['valid, reversed key order', '{"ns":"","nb":true,"nf":-0.0,"ni":9223372036854775807,"s":"","b":false,"f":0,"i":0}', 'match'],
    ['i is real', str_replace('"i":-7', '"i":-7.0', "{{$good}}"), 'error'],
    ['f is string', str_replace('"f":2.5e3', '"f":"2.5e3"', "{{$good}}"), 'error'],
    ['b is 1', str_replace('"b":true', '"b":1', "{{$good}}"), 'error'],
    ['s is number', str_replace('"s":"x"', '"s":7', "{{$good}}"), 'error'],
    ['ni is 2^63', str_replace('"ni":null', '"ni":9223372036854775808', "{{$good}}"), 'error'],
    ['nf is RAW', str_replace('"nf":3', '"nf":1e400', "{{$good}}"), 'error'],
    ['nb is "false"', str_replace('"nb":false', '"nb":"false"', "{{$good}}"), 'error'],
    ['ns is array', str_replace('"ns":null', '"ns":[]', "{{$good}}"), 'error'],
    ['i is null', str_replace('"i":-7', '"i":null', "{{$good}}"), 'error'],
    ['s is null', str_replace('"s":"x"', '"s":null', "{{$good}}"), 'error'],
    ['ns absent', str_replace(',"ns":null', '', "{{$good}}"), 'error'],
    ['i absent', str_replace('"i":-7,', '', "{{$good}}"), 'error'],
]);

echo "-- records after the first are validated too, with line, record and offset\n";
$plan = ['fields' => ['a' => 'int64', 'b' => 'float64?']];
$l1 = '{"a":1,"b":null}';
$l2 = '{"a":9223372036854775807,"b":1e3}';
foreach ([
    ['a is 2^63 on line 3', "$l1\n$l2\n" . '{"a":9223372036854775808,"b":null}' . "\n", 'line=3 record=3 offset=' . (strlen($l1) + strlen($l2) + 2)],
    ['b is 1e400 on line 2', "$l1\n" . '{"a":1,"b":1e400}' . "\n", 'line=2 record=2 offset=' . (strlen($l1) + 1)],
    ['b is a string on line 2, CRLF', "$l1\r\n" . '{"a":1,"b":"x"}' . "\r\n", 'line=2 record=2 offset=' . (strlen($l1) + 2)],
    ['a absent on the last line without LF', "$l1\n$l1\n" . '{"b":1}', 'line=3 record=3 offset=' . (2 * strlen($l1) + 2)],
    ['a is -0.0 on line 1', '{"a":-0.0,"b":null}' . "\n", 'line=1 record=1 offset=0'],
] as [$name, $data, $ctx]) {
    echo str_pad($name, 66), jf_expect_error($data, RecordException::class, $ctx, ['SECRET', '9223372036854775808'], $plan), "\n";
}
echo str_pad('every record valid', 66), jf_run_str("$l1\n$l2\n{\"a\":-9223372036854775808,\"b\":-0.0}\n", $plan), "\n";
?>
--EXPECT--
-- number literals as int64
0                                                                 ok
5                                                                 ok
-5                                                                ok
-0                                                                ok
123456789012345678                                                ok
9007199254740993                                                  ok
1.0                                                               ok
1e3                                                               ok
1E3                                                               ok
1e+3                                                              ok
1e-3                                                              ok
10E-1                                                             ok
0.5                                                               ok
0.0                                                               ok
0e0                                                               ok
-0.0                                                              ok
-0e5                                                              ok
3.141592653589793                                                 ok
9223372036854775806                                               ok
9223372036854775807                                               ok
-9223372036854775807                                              ok
-9223372036854775808                                              ok
9223372036854775808                                               ok
9223372036854775809                                               ok
10000000000000000000                                              ok
18446744073709551614                                              ok
18446744073709551615                                              ok
18446744073709551616                                              ok
-9223372036854775809                                              ok
-18446744073709551616                                             ok
99999999999999999999999999999                                     ok
100000000000...(309 chars)                                        ok
100000000000...(310 chars)                                        ok
999999999999...(400 chars)                                        ok
-99999999999...(401 chars)                                        ok
1e308                                                             ok
1.7976931348623157e308                                            ok
1.7976931348623158e308                                            ok
1.7976931348623159e308                                            ok
-1.7976931348623157e308                                           ok
-1.7976931348623159e308                                           ok
1e309                                                             ok
1e400                                                             ok
-1e400                                                            ok
1E400                                                             ok
1e+400                                                            ok
1e999999999                                                       ok
-1e999999999                                                      ok
1e-400                                                            ok
-1e-400                                                           ok
1e-324                                                            ok
4.9e-324                                                          ok
01                                                                ok
-01                                                               ok
+1                                                                ok
.5                                                                ok
1.                                                                ok
1e                                                                ok
1e+                                                               ok
0x10                                                              ok
- 1                                                               ok
--1                                                               ok
NaN                                                               ok
Infinity                                                          ok
-Infinity                                                         ok
-- number literals as int64?
0                                                                 ok
5                                                                 ok
-5                                                                ok
-0                                                                ok
123456789012345678                                                ok
9007199254740993                                                  ok
1.0                                                               ok
1e3                                                               ok
1E3                                                               ok
1e+3                                                              ok
1e-3                                                              ok
10E-1                                                             ok
0.5                                                               ok
0.0                                                               ok
0e0                                                               ok
-0.0                                                              ok
-0e5                                                              ok
3.141592653589793                                                 ok
9223372036854775806                                               ok
9223372036854775807                                               ok
-9223372036854775807                                              ok
-9223372036854775808                                              ok
9223372036854775808                                               ok
9223372036854775809                                               ok
10000000000000000000                                              ok
18446744073709551614                                              ok
18446744073709551615                                              ok
18446744073709551616                                              ok
-9223372036854775809                                              ok
-18446744073709551616                                             ok
99999999999999999999999999999                                     ok
100000000000...(309 chars)                                        ok
100000000000...(310 chars)                                        ok
999999999999...(400 chars)                                        ok
-99999999999...(401 chars)                                        ok
1e308                                                             ok
1.7976931348623157e308                                            ok
1.7976931348623158e308                                            ok
1.7976931348623159e308                                            ok
-1.7976931348623157e308                                           ok
-1.7976931348623159e308                                           ok
1e309                                                             ok
1e400                                                             ok
-1e400                                                            ok
1E400                                                             ok
1e+400                                                            ok
1e999999999                                                       ok
-1e999999999                                                      ok
1e-400                                                            ok
-1e-400                                                           ok
1e-324                                                            ok
4.9e-324                                                          ok
01                                                                ok
-01                                                               ok
+1                                                                ok
.5                                                                ok
1.                                                                ok
1e                                                                ok
1e+                                                               ok
0x10                                                              ok
- 1                                                               ok
--1                                                               ok
NaN                                                               ok
Infinity                                                          ok
-Infinity                                                         ok
-- number literals as float64
0                                                                 ok
5                                                                 ok
-5                                                                ok
-0                                                                ok
123456789012345678                                                ok
9007199254740993                                                  ok
1.0                                                               ok
1e3                                                               ok
1E3                                                               ok
1e+3                                                              ok
1e-3                                                              ok
10E-1                                                             ok
0.5                                                               ok
0.0                                                               ok
0e0                                                               ok
-0.0                                                              ok
-0e5                                                              ok
3.141592653589793                                                 ok
9223372036854775806                                               ok
9223372036854775807                                               ok
-9223372036854775807                                              ok
-9223372036854775808                                              ok
9223372036854775808                                               ok
9223372036854775809                                               ok
10000000000000000000                                              ok
18446744073709551614                                              ok
18446744073709551615                                              ok
18446744073709551616                                              ok
-9223372036854775809                                              ok
-18446744073709551616                                             ok
99999999999999999999999999999                                     ok
100000000000...(309 chars)                                        ok
100000000000...(310 chars)                                        ok
999999999999...(400 chars)                                        ok
-99999999999...(401 chars)                                        ok
1e308                                                             ok
1.7976931348623157e308                                            ok
1.7976931348623158e308                                            ok
1.7976931348623159e308                                            ok
-1.7976931348623157e308                                           ok
-1.7976931348623159e308                                           ok
1e309                                                             ok
1e400                                                             ok
-1e400                                                            ok
1E400                                                             ok
1e+400                                                            ok
1e999999999                                                       ok
-1e999999999                                                      ok
1e-400                                                            ok
-1e-400                                                           ok
1e-324                                                            ok
4.9e-324                                                          ok
01                                                                ok
-01                                                               ok
+1                                                                ok
.5                                                                ok
1.                                                                ok
1e                                                                ok
1e+                                                               ok
0x10                                                              ok
- 1                                                               ok
--1                                                               ok
NaN                                                               ok
Infinity                                                          ok
-Infinity                                                         ok
-- number literals as float64?
0                                                                 ok
5                                                                 ok
-5                                                                ok
-0                                                                ok
123456789012345678                                                ok
9007199254740993                                                  ok
1.0                                                               ok
1e3                                                               ok
1E3                                                               ok
1e+3                                                              ok
1e-3                                                              ok
10E-1                                                             ok
0.5                                                               ok
0.0                                                               ok
0e0                                                               ok
-0.0                                                              ok
-0e5                                                              ok
3.141592653589793                                                 ok
9223372036854775806                                               ok
9223372036854775807                                               ok
-9223372036854775807                                              ok
-9223372036854775808                                              ok
9223372036854775808                                               ok
9223372036854775809                                               ok
10000000000000000000                                              ok
18446744073709551614                                              ok
18446744073709551615                                              ok
18446744073709551616                                              ok
-9223372036854775809                                              ok
-18446744073709551616                                             ok
99999999999999999999999999999                                     ok
100000000000...(309 chars)                                        ok
100000000000...(310 chars)                                        ok
999999999999...(400 chars)                                        ok
-99999999999...(401 chars)                                        ok
1e308                                                             ok
1.7976931348623157e308                                            ok
1.7976931348623158e308                                            ok
1.7976931348623159e308                                            ok
-1.7976931348623157e308                                           ok
-1.7976931348623159e308                                           ok
1e309                                                             ok
1e400                                                             ok
-1e400                                                            ok
1E400                                                             ok
1e+400                                                            ok
1e999999999                                                       ok
-1e999999999                                                      ok
1e-400                                                            ok
-1e-400                                                           ok
1e-324                                                            ok
4.9e-324                                                          ok
01                                                                ok
-01                                                               ok
+1                                                                ok
.5                                                                ok
1.                                                                ok
1e                                                                ok
1e+                                                               ok
0x10                                                              ok
- 1                                                               ok
--1                                                               ok
NaN                                                               ok
Infinity                                                          ok
-Infinity                                                         ok
-- a number in an unreferenced key is never converted
-- unreferenced magnitude is irrelevant
1e400 elsewhere                                                   ok
400-digit integer elsewhere                                       ok
1e-400 elsewhere                                                  ok
1e400 before the field                                            ok
RAW inside a nested array                                         ok
-- JSON value kinds against int64
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against int64?
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against float64
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against float64?
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against bool
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against bool?
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against string
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- JSON value kinds against string?
string x                                                          ok
empty string                                                      ok
numeric string                                                    ok
string 1.5                                                        ok
string true                                                       ok
integer 5                                                         ok
integer 0                                                         ok
real 1.5                                                          ok
real 1.0                                                          ok
true                                                              ok
false                                                             ok
null                                                              ok
empty array                                                       ok
array [1]                                                         ok
array of strings                                                  ok
empty object                                                      ok
object                                                            ok
-- string values
non-ASCII                                                         ok
escaped non-ASCII                                                 ok
all short escapes                                                 ok
\u0000 in a value is valid                                        ok
control character escapes                                         ok
U+2028 and U+2029 raw                                             ok
BOM bytes inside a string                                         ok
lone high surrogate escape                                        ok
lone low surrogate escape                                         ok
invalid UTF-8: overlong                                           ok
invalid UTF-8: truncated                                          ok
invalid UTF-8: surrogate bytes                                    ok
invalid UTF-8: above U+10FFFF                                     ok
raw TAB in a string                                               ok
raw NUL in a string                                               ok
unterminated string                                               ok
-- missing is not null, and nullable is not optional
-- one nullable field
present with a value                                              ok
present with null                                                 ok
absent: empty object                                              ok
absent: other keys only                                           ok
absent: null under another key                                    ok
absent: key differs by case                                       ok
absent: key with a trailing space                                 ok
absent: key is a prefix                                           ok
absent: only nested                                               ok
absent: only in an array element                                  ok
wrong type, not null                                              ok
-- non-nullable fields reject null
all present                                                       ok
a null                                                            ok
b null                                                            ok
c null                                                            ok
d null                                                            ok
d absent                                                          ok
a absent                                                          ok
-- falsy values are not null in nullable fields
0, "", false, 0.0                                                 ok
all null                                                          ok
nulls and values mixed                                            ok
one of four absent                                                ok
one wrong among nulls                                             ok
-- all eight type forms in one plan
valid                                                             ok
valid, extra keys of every kind                                   ok
valid, reversed key order                                         ok
i is real                                                         ok
f is string                                                       ok
b is 1                                                            ok
s is number                                                       ok
ni is 2^63                                                        ok
nf is RAW                                                         ok
nb is "false"                                                     ok
ns is array                                                       ok
i is null                                                         ok
s is null                                                         ok
ns absent                                                         ok
i absent                                                          ok
-- records after the first are validated too, with line, record and offset
a is 2^63 on line 3                                               ok
b is 1e400 on line 2                                              ok
b is a string on line 2, CRLF                                     ok
a absent on the last line without LF                              ok
a is -0.0 on line 1                                               ok
every record valid                                                read=3 matched=3 emitted=3 bytesRead=87 bytesWritten=90
