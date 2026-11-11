--TEST--
jsonfuse: referenced keys and string values with escapes and UTF-8, __proto__, numeric-string and empty field names, NUL in keys and values
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;

$cafe = "caf\u{e9}";
$smile = "\u{1F600}";
$cjk = "\u{65E5}\u{672C}\u{8A9E}";
$fieldsU = ['fields' => [$cafe => 'int64', $smile => 'string', "\u{fc}" => 'bool?', $cjk => 'string?']];

jf_rows('non-ASCII field names, raw UTF-8 keys', $fieldsU, [
    ['all four present', "{\"$cafe\":1,\"$smile\":\"a\",\"\u{fc}\":true,\"$cjk\":null}", 'match'],
    ['different order', "{\"$cjk\":\"x\",\"\u{fc}\":null,\"$smile\":\"\",\"$cafe\":-1}", 'match'],
    ['the 4-byte field is missing', "{\"$cafe\":1,\"\u{fc}\":true,\"$cjk\":null}", 'error'],
    ['the 2-byte nullable field is missing', "{\"$cafe\":1,\"$smile\":\"a\",\"$cjk\":null}", 'error'],
    ['wrong type under a non-ASCII key', "{\"$cafe\":\"1\",\"$smile\":\"a\",\"\u{fc}\":true,\"$cjk\":null}", 'error'],
    ['accented key lacks its accent', "{\"cafe\":1,\"$smile\":\"a\",\"\u{fc}\":true,\"$cjk\":null}", 'error'],
    ['u with diaeresis written as u + U+0308 is another key', "{\"$cafe\":1,\"$smile\":\"a\",\"u\u0308\":true,\"$cjk\":null}", 'error'],
]);
jf_rows('non-ASCII field names, every non-ASCII key character escaped', $fieldsU, [
    ['all four escaped', '{"' . jf_u_escape($cafe) . '":1,"' . jf_u_escape($smile) . '":"a","' . jf_u_escape("\u{fc}") . '":true,"' . jf_u_escape($cjk) . '":null}', 'match'],
    ['mixed raw and escaped', "{\"" . jf_u_escape($cafe) . "\":1,\"$smile\":\"a\",\"" . jf_u_escape("\u{fc}") . "\":true,\"$cjk\":null}", 'match'],
    ['upper-case hex digits', '{"caf\u00E9":1,"\uD83D\uDE00":"a","\u00FC":true,"\u65E5\u672C\u8A9E":null}', 'match'],
    ['escaped key, wrong type', '{"caf\u00e9":"1","\ud83d\ude00":"a","\u00fc":true,"\u65e5\u672c\u8a9e":null}', 'error'],
    ['a lone surrogate escape in an unreferenced key', '{"caf\u00e9":1,"\ud83d\ude00":"a","\u00fc":true,"\u65e5\u672c\u8a9e":null,"x\ud83d":1}', 'error'],
    ['a valid pair in an unreferenced key', '{"caf\u00e9":1,"\ud83d\ude00":"a","\u00fc":true,"\u65e5\u672c\u8a9e":null,"x\ud83d\ude00":1}', 'match'],
]);

// Code points at every UTF-8 length boundary: raw and escaped spellings in values and in keys.
$cps = [0x80, 0x7FF, 0x800, 0xD7FF, 0xE000, 0xFFFF, 0x10000, 0x10FFFF, 0xE9, 0x20AC, 0x2028, 0x2029, 0xFEFF];
foreach ($cps as $cp) {
    $ch = jf_chr($cp);
    $hex = sprintf('U+%04X', $cp);
    $next = jf_chr($cp === 0x10FFFF || $cp === 0xD7FF ? $cp - 1 : $cp + 1);
    jf_rows("string where $hex", ['fields' => ['id' => 'int64', $ch => 'int64?'], 'where' => ['t' => $ch]], [
        ['raw value, raw key', "{\"id\":1,\"t\":\"$ch\",\"$ch\":null}", 'match'],
        ['escaped value, escaped key', '{"id":1,"t":"' . jf_u_escape($ch) . '","' . jf_u_escape($ch) . '":null}', 'match'],
        ['raw value, escaped key', "{\"id\":1,\"t\":\"$ch\",\"" . jf_u_escape($ch) . '":null}', 'match'],
        ['neighbouring code point as the value', "{\"id\":1,\"t\":\"$next\",\"$ch\":null}", 'nomatch'],
        ['value with the character twice', "{\"id\":1,\"t\":\"$ch$ch\",\"$ch\":null}", 'nomatch'],
        ['key missing', "{\"id\":1,\"t\":\"$ch\"}", 'error'],
        ['key spelled with the neighbouring code point', "{\"id\":1,\"t\":\"$ch\",\"$next\":null}", 'error'],
    ]);
}

jf_rows('a 1024-byte where value and field name', [
    'fields' => [str_repeat("\u{e9}", 512) => 'int64'], 'where' => ['t' => str_repeat("\u{e9}", 512)],
], [
    ['raw key, raw value', '{"' . str_repeat("\u{e9}", 512) . '":1,"t":"' . str_repeat("\u{e9}", 512) . '"}', 'match'],
    ['escaped key, escaped value', '{"' . jf_u_escape(str_repeat("\u{e9}", 512)) . '":1,"t":"' . jf_u_escape(str_repeat("\u{e9}", 512)) . '"}', 'match'],
    ['value one character short', '{"' . str_repeat("\u{e9}", 512) . '":1,"t":"' . str_repeat("\u{e9}", 511) . '"}', 'nomatch'],
    ['value one character long', '{"' . str_repeat("\u{e9}", 512) . '":1,"t":"' . str_repeat("\u{e9}", 513) . '"}', 'nomatch'],
    ['key one character short', '{"' . str_repeat("\u{e9}", 511) . '":1,"t":"' . str_repeat("\u{e9}", 512) . '"}', 'error'],
]);

echo "-- __proto__ has no special behaviour\n";
$p = ['fields' => ['__proto__' => 'string'], 'where' => ['__proto__' => 'x']];
jf_rows('__proto__ as field and filter key', $p, [
    ['string x', '{"__proto__":"x"}', 'match'],
    ['string y', '{"__proto__":"y"}', 'nomatch'],
    ['object value fails the field type', '{"__proto__":{"a":1}}', 'error'],
    ['missing', '{"a":1}', 'error'],
    ['twice', '{"__proto__":"x","__proto__":"x"}', 'error'],
    ['escaped underscores', '{"\u005f\u005fproto\u005f\u005f":"x"}', 'match'],
    ['nested __proto__ is not the key', '{"o":{"__proto__":"x"}}', 'error'],
]);
jf_rows('unreferenced __proto__ is ordinary data', ['fields' => ['id' => 'int64']], [
    ['object', '{"id":1,"__proto__":{"id":"bad"}}', 'match'],
    ['repeated', '{"id":1,"__proto__":1,"__proto__":2}', 'match'],
    ['first', '{"__proto__":{"polluted":true},"id":1}', 'match'],
    ['constructor and prototype', '{"id":1,"constructor":{"prototype":{}},"prototype":1}', 'match'],
]);

echo "-- numeric-string and integer field names\n";
$num = ['fields' => ['123' => 'int64', '-5' => 'string', '0123' => 'bool', '-0' => 'int64?', '1.5' => 'float64?']];
jf_rows('numeric-string keys given as strings', $num, [
    ['all five', '{"123":1,"-5":"a","0123":true,"-0":null,"1.5":2.5}', 'match'],
    ['keys escaped', '{"\u0031\u0032\u0033":1,"\u002d5":"a","0\u0031\u0032\u0033":true,"\u002d0":null,"1\u002e5":2.5}', 'match'],
    ['0123 is not 123', '{"123":1,"-5":"a","-0":null,"1.5":null}', 'error'],
    ['-0 is not 0', '{"123":1,"-5":"a","0123":true,"0":null,"1.5":null}', 'error'],
    ['1.50 is not 1.5', '{"123":1,"-5":"a","0123":true,"-0":null,"1.50":null}', 'error'],
    ['00123 is not 123', '{"00123":1,"-5":"a","0123":true,"-0":null,"1.5":null}', 'error'],
    ['+123 is not 123', '{"+123":1,"-5":"a","0123":true,"-0":null,"1.5":null}', 'error'],
    [' 123 is not 123', '{" 123":1,"-5":"a","0123":true,"-0":null,"1.5":null}', 'error'],
    ['wrong type under 123', '{"123":"1","-5":"a","0123":true,"-0":null,"1.5":null}', 'error'],
    ['wrong type under 1.5', '{"123":1,"-5":"a","0123":true,"-0":null,"1.5":"x"}', 'error'],
]);
jf_rows('integer array keys in the plan are their decimal strings', ['fields' => [123 => 'int64', -5 => 'string', 0 => 'bool?'], 'where' => [7 => 'x', -2 => 1]], [
    ['all present, filters match', '{"123":1,"-5":"a","0":null,"7":"x","-2":1}', 'match'],
    ['filter 7 mismatches', '{"123":1,"-5":"a","0":null,"7":"y","-2":1}', 'nomatch'],
    ['filter -2 is a string', '{"123":1,"-5":"a","0":null,"7":"x","-2":"1"}', 'nomatch'],
    ['key 0 is missing', '{"123":1,"-5":"a","7":"x","-2":1}', 'error'],
    ['key 0 written -0', '{"123":1,"-5":"a","-0":null,"7":"x","-2":1}', 'error'],
    ['key 123 written 0123', '{"0123":1,"-5":"a","0":null,"7":"x","-2":1}', 'error'],
    ['key 7 twice', '{"123":1,"-5":"a","0":null,"7":"x","7":"x","-2":1}', 'error'],
]);

echo "-- empty field name\n";
$e = ['fields' => ['' => 'int64'], 'where' => ['' => 5]];
jf_rows('empty key as field and filter', $e, [
    ['present and matching', '{"":5}', 'match'],
    ['present, other value', '{"":6}', 'nomatch'],
    ['missing', '{}', 'error'],
    ['other keys only', '{"a":5}', 'error'],
    ['wrong type', '{"":"5"}', 'error'],
    ['twice', '{"":5,"":5}', 'error'],
    ['nested empty key is not the key', '{"o":{"":5}}', 'error'],
]);
jf_rows('empty key among others', ['fields' => ['' => 'string?', 'a' => 'int64']], [
    ['empty key null', '{"":null,"a":1}', 'match'],
    ['empty key string', '{"a":1,"":""}', 'match'],
    ['empty key missing', '{"a":1}', 'error'],
]);

echo "-- NUL in keys and values\n";
$nul = ['fields' => ["a\0b" => 'int64', 'v' => 'string'], 'where' => ['v' => "x\0y"]];
jf_rows('NUL inside a field name and a filter value', $nul, [
    ['escaped NUL in key and value', '{"a\u0000b":1,"v":"x\u0000y"}', 'match'],
    ['value without the NUL', '{"a\u0000b":1,"v":"xy"}', 'nomatch'],
    ['value with a space for the NUL', '{"a\u0000b":1,"v":"x y"}', 'nomatch'],
    ['value ends at the NUL', '{"a\u0000b":1,"v":"x\u0000"}', 'nomatch'],
    ['key without the NUL', '{"ab":1,"v":"x\u0000y"}', 'error'],
    ['key ends at the NUL', '{"a\u0000":1,"v":"x\u0000y"}', 'error'],
    ['key is only the part before the NUL', '{"a":1,"v":"x\u0000y"}', 'error'],
    ['raw NUL inside the key is invalid JSON', "{\"a\0b\":1,\"v\":\"x\u0000y\"}", 'error'],
    ['raw NUL inside the value is invalid JSON', "{\"a\u0000b\":1,\"v\":\"x\0y\"}", 'error'],
    ['raw NUL after the object is invalid JSON', "{\"a\u0000b\":1,\"v\":\"x\u0000y\"}\0", 'error'],
]);
jf_rows('NUL-only names and values', ['fields' => ["\0" => 'string'], 'where' => ["\0" => "\0"]], [
    ['escaped NUL key and value', '{"\u0000":"\u0000"}', 'match'],
    ['empty value', '{"\u0000":""}', 'nomatch'],
    ['two NULs in the value', '{"\u0000":"\u0000\u0000"}', 'nomatch'],
    ['empty key instead of the NUL key', '{"":"\u0000"}', 'error'],
]);

echo "-- control characters in keys and values\n";
jf_rows('escaped controls in a referenced key', ['fields' => ["t\tab" => 'int64', "n\nl" => 'int64', "c\x1f" => 'int64', "d\x7f" => 'int64']], [
    ['short escapes and \u escapes', '{"t\tab":1,"n\nl":2,"c\u001f":3,"d\u007f":4}', 'match'],
    ['\u0009 for the tab, upper-case hex digits', '{"t\u0009ab":1,"n\u000Al":2,"c\u001F":3,"d\u007F":4}', 'match'],
    ['raw DEL is allowed in a key', "{\"t\\tab\":1,\"n\\nl\":2,\"c\u001f\":3,\"d\x7f\":4}", 'match'],
    ['raw TAB in a key is invalid JSON', "{\"t\tab\":1,\"n\\nl\":2,\"c\u001f\":3,\"d\u007f\":4}", 'error'],
    ['raw 0x1F in a key is invalid JSON', "{\"t\\tab\":1,\"n\\nl\":2,\"c\x1f\":3,\"d\u007f\":4}", 'error'],
]);

echo "-- invalid text in values and keys is an error even when the field type is fine\n";
jf_rows('invalid string content in referenced values', ['fields' => ['s' => 'string'], 'where' => ['k' => 'x']], [
    ['valid', '{"s":"ok","k":"x"}', 'match'],
    ['lone high surrogate in the field', '{"s":"\ud800","k":"x"}', 'error'],
    ['lone low surrogate in the field', '{"s":"\udc00","k":"x"}', 'error'],
    ['high surrogate followed by a non-low escape', '{"s":"\ud800\u0041","k":"x"}', 'error'],
    ['lone surrogate in the filter value', '{"s":"ok","k":"\ud800"}', 'error'],
    ['overlong sequence in the field', "{\"s\":\"\xC0\xAF\",\"k\":\"x\"}", 'error'],
    ['truncated sequence in the filter value', "{\"s\":\"ok\",\"k\":\"\xE2\x82\"}", 'error'],
    ['surrogate bytes in an unreferenced value', "{\"s\":\"ok\",\"k\":\"x\",\"u\":\"\xED\xA0\x80\"}", 'error'],
    ['invalid byte in an unreferenced key', "{\"s\":\"ok\",\"k\":\"x\",\"\xFF\":1}", 'error'],
    ['BOM before the object', "\xEF\xBB\xBF{\"s\":\"ok\",\"k\":\"x\"}", 'error'],
    ['U+FEFF inside a value is just text', "{\"s\":\"\u{FEFF}\",\"k\":\"x\"}", 'match'],
]);

echo "-- keys are case-sensitive and match whole names\n";
jf_rows('case and length', ['fields' => ['Name' => 'string']], [
    ['exact', '{"Name":"x"}', 'match'],
    ['lower case', '{"name":"x"}', 'error'],
    ['upper case', '{"NAME":"x"}', 'error'],
    ['longer key', '{"Names":"x"}', 'error'],
    ['shorter key', '{"Nam":"x"}', 'error'],
    ['escaped letters of the exact name', '{"\u004eame":"x"}', 'match'],
    ['escaped letter of the lower-case name', '{"\u006eame":"x"}', 'error'],
]);

echo "-- messages carry the context prefix and none of the record\n";
$secret = "{\"id\":1,\"note\":\"SECRETMARK caf\u{e9} \u{1F600}\",\"bad\":\"\xC0\"}\n";
echo str_pad('invalid UTF-8 next to non-ASCII text', 66),
    jf_expect_error($secret, RecordException::class, 'line=1 record=1 offset=0', ['SECRET', "caf\u{e9}", "\u{1F600}"], ['fields' => ['id' => 'int64']]), "\n";
echo str_pad('type error next to non-ASCII text', 66),
    jf_expect_error("{\"id\":\"SECRETMARK caf\u{e9}\"}\n", RecordException::class, 'line=1 record=1 offset=0', ['SECRET', "caf\u{e9}"], ['fields' => ['id' => 'int64']]), "\n";
?>
--EXPECT--
-- non-ASCII field names, raw UTF-8 keys
all four present                                                  ok
different order                                                   ok
the 4-byte field is missing                                       ok
the 2-byte nullable field is missing                              ok
wrong type under a non-ASCII key                                  ok
accented key lacks its accent                                     ok
u with diaeresis written as u + U+0308 is another key             ok
-- non-ASCII field names, every non-ASCII key character escaped
all four escaped                                                  ok
mixed raw and escaped                                             ok
upper-case hex digits                                             ok
escaped key, wrong type                                           ok
a lone surrogate escape in an unreferenced key                    ok
a valid pair in an unreferenced key                               ok
-- string where U+0080
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+07FF
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+0800
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+D7FF
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+E000
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+FFFF
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+10000
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+10FFFF
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+00E9
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+20AC
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+2028
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+2029
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- string where U+FEFF
raw value, raw key                                                ok
escaped value, escaped key                                        ok
raw value, escaped key                                            ok
neighbouring code point as the value                              ok
value with the character twice                                    ok
key missing                                                       ok
key spelled with the neighbouring code point                      ok
-- a 1024-byte where value and field name
raw key, raw value                                                ok
escaped key, escaped value                                        ok
value one character short                                         ok
value one character long                                          ok
key one character short                                           ok
-- __proto__ has no special behaviour
-- __proto__ as field and filter key
string x                                                          ok
string y                                                          ok
object value fails the field type                                 ok
missing                                                           ok
twice                                                             ok
escaped underscores                                               ok
nested __proto__ is not the key                                   ok
-- unreferenced __proto__ is ordinary data
object                                                            ok
repeated                                                          ok
first                                                             ok
constructor and prototype                                         ok
-- numeric-string and integer field names
-- numeric-string keys given as strings
all five                                                          ok
keys escaped                                                      ok
0123 is not 123                                                   ok
-0 is not 0                                                       ok
1.50 is not 1.5                                                   ok
00123 is not 123                                                  ok
+123 is not 123                                                   ok
 123 is not 123                                                   ok
wrong type under 123                                              ok
wrong type under 1.5                                              ok
-- integer array keys in the plan are their decimal strings
all present, filters match                                        ok
filter 7 mismatches                                               ok
filter -2 is a string                                             ok
key 0 is missing                                                  ok
key 0 written -0                                                  ok
key 123 written 0123                                              ok
key 7 twice                                                       ok
-- empty field name
-- empty key as field and filter
present and matching                                              ok
present, other value                                              ok
missing                                                           ok
other keys only                                                   ok
wrong type                                                        ok
twice                                                             ok
nested empty key is not the key                                   ok
-- empty key among others
empty key null                                                    ok
empty key string                                                  ok
empty key missing                                                 ok
-- NUL in keys and values
-- NUL inside a field name and a filter value
escaped NUL in key and value                                      ok
value without the NUL                                             ok
value with a space for the NUL                                    ok
value ends at the NUL                                             ok
key without the NUL                                               ok
key ends at the NUL                                               ok
key is only the part before the NUL                               ok
raw NUL inside the key is invalid JSON                            ok
raw NUL inside the value is invalid JSON                          ok
raw NUL after the object is invalid JSON                          ok
-- NUL-only names and values
escaped NUL key and value                                         ok
empty value                                                       ok
two NULs in the value                                             ok
empty key instead of the NUL key                                  ok
-- control characters in keys and values
-- escaped controls in a referenced key
short escapes and \u escapes                                      ok
\u0009 for the tab, upper-case hex digits                         ok
raw DEL is allowed in a key                                       ok
raw TAB in a key is invalid JSON                                  ok
raw 0x1F in a key is invalid JSON                                 ok
-- invalid text in values and keys is an error even when the field type is fine
-- invalid string content in referenced values
valid                                                             ok
lone high surrogate in the field                                  ok
lone low surrogate in the field                                   ok
high surrogate followed by a non-low escape                       ok
lone surrogate in the filter value                                ok
overlong sequence in the field                                    ok
truncated sequence in the filter value                            ok
surrogate bytes in an unreferenced value                          ok
invalid byte in an unreferenced key                               ok
BOM before the object                                             ok
U+FEFF inside a value is just text                                ok
-- keys are case-sensitive and match whole names
-- case and length
exact                                                             ok
lower case                                                        ok
upper case                                                        ok
longer key                                                        ok
shorter key                                                       ok
escaped letters of the exact name                                 ok
escaped letter of the lower-case name                             ok
-- messages carry the context prefix and none of the record
invalid UTF-8 next to non-ASCII text                              ok
type error next to non-ASCII text                                 ok
