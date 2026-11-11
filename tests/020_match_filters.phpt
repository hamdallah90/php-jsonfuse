--TEST--
jsonfuse: where filters - AND of type-strict equality, missing keys, RAW numbers, non-scalar values, key in fields and where, recordsMatched
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;
use JsonFuse\Runner;

// One record per run. 'match' / 'nomatch' = success with recordsMatched 1 / 0 (a match writes
// exactly one output line, a non-match writes nothing); 'error' = RecordException.
$f = ['id' => 'int64'];
$d400 = str_repeat('9', 400);

jf_rows('string where: byte-exact after escape decoding, case-sensitive', ['fields' => $f, 'where' => ['kind' => 'customer']], [
    ['exact', '{"id":1,"kind":"customer"}', 'match'],
    ['other value', '{"id":1,"kind":"vendor"}', 'nomatch'],
    ['capitalised', '{"id":1,"kind":"Customer"}', 'nomatch'],
    ['upper case', '{"id":1,"kind":"CUSTOMER"}', 'nomatch'],
    ['longer (prefix of record value)', '{"id":1,"kind":"customers"}', 'nomatch'],
    ['shorter (prefix of where value)', '{"id":1,"kind":"custome"}', 'nomatch'],
    ['trailing space', '{"id":1,"kind":"customer "}', 'nomatch'],
    ['leading space', '{"id":1,"kind":" customer"}', 'nomatch'],
    ['empty string', '{"id":1,"kind":""}', 'nomatch'],
    ['missing key', '{"id":1}', 'nomatch'],
    ['key differs by case', '{"id":1,"Kind":"customer"}', 'nomatch'],
    ['key only nested', '{"id":1,"o":{"kind":"customer"}}', 'nomatch'],
    ['key only inside array element', '{"id":1,"o":[{"kind":"customer"}]}', 'nomatch'],
    ['value is null', '{"id":1,"kind":null}', 'nomatch'],
    ['value is number', '{"id":1,"kind":5}', 'nomatch'],
    ['value is true', '{"id":1,"kind":true}', 'nomatch'],
    ['escaped e: custom\u0065r', '{"id":1,"kind":"custom\u0065r"}', 'match'],
    ['escaped first letter', '{"id":1,"kind":"\u0063ustomer"}', 'match'],
    ['escaped letter, upper-case hex digits', '{"id":1,"kind":"cust\u006Fmer"}', 'match'],
    ['every letter escaped', '{"id":1,"kind":"\u0063\u0075\u0073\u0074\u006f\u006d\u0065\u0072"}', 'match'],
    ['escape decodes to a different letter', '{"id":1,"kind":"\u0043ustomer"}', 'nomatch'],
    ['text \u0063 without the backslash escape', '{"id":1,"kind":"\\\\u0063ustomer"}', 'nomatch'],
    ['SECRET marker value does not match', '{"id":1,"kind":"SECRETMARK"}', 'nomatch'],
]);

jf_rows('string where with non-ASCII bytes', ['fields' => $f, 'where' => ['t' => "caf\u{e9}"]], [
    ['raw UTF-8', "{\"id\":1,\"t\":\"caf\u{e9}\"}", 'match'],
    ['escaped \u00e9', '{"id":1,"t":"caf\u00e9"}', 'match'],
    ['escaped \u00E9 (upper-case hex)', '{"id":1,"t":"caf\u00E9"}', 'match'],
    ['decomposed e + U+0301 is another byte string', '{"id":1,"t":"cafe\u0301"}', 'nomatch'],
    ['plain e', '{"id":1,"t":"cafe"}', 'nomatch'],
    ['upper-case \u00c9', '{"id":1,"t":"caf\u00c9"}', 'nomatch'],
]);
jf_rows('string where with a 4-byte character', ['fields' => $f, 'where' => ['t' => "\u{1F600}"]], [
    ['raw UTF-8', "{\"id\":1,\"t\":\"\u{1F600}\"}", 'match'],
    ['surrogate pair, lower-case hex', '{"id":1,"t":"\ud83d\ude00"}', 'match'],
    ['surrogate pair, upper-case hex', '{"id":1,"t":"\uD83D\uDE00"}', 'match'],
    ['another emoji', '{"id":1,"t":"\ud83d\ude01"}', 'nomatch'],
]);
jf_rows('string where with control characters and punctuation', ['fields' => $f, 'where' => ['t' => "a\nb\t\"\\/"]], [
    ['escapes \n \t \" \\ \/', '{"id":1,"t":"a\nb\t\"\\\\\/"}', 'match'],
    ['\/ replaced by raw /', '{"id":1,"t":"a\nb\t\"\\\\/"}', 'match'],
    ['\u000a instead of backslash-n', '{"id":1,"t":"a\u000ab\u0009\u0022\u005c\u002f"}', 'match'],
    ['\n left out', '{"id":1,"t":"ab\t\"\\\\/"}', 'nomatch'],
    ['backslash then n (two chars)', '{"id":1,"t":"a\\\\nb\t\"\\\\/"}', 'nomatch'],
]);
jf_rows('string where with NUL and empty values', ['fields' => $f, 'where' => ['t' => "a\0b"]], [
    ['\u0000 in the middle', '{"id":1,"t":"a\u0000b"}', 'match'],
    ['no NUL', '{"id":1,"t":"ab"}', 'nomatch'],
    ['NUL at the end only', '{"id":1,"t":"a\u0000"}', 'nomatch'],
    ['NUL, another letter', '{"id":1,"t":"a\u0000c"}', 'nomatch'],
]);
jf_rows('empty-string where', ['fields' => $f, 'where' => ['t' => '']], [
    ['empty string', '{"id":1,"t":""}', 'match'],
    ['one space', '{"id":1,"t":" "}', 'nomatch'],
    ['\u0000', '{"id":1,"t":"\u0000"}', 'nomatch'],
    ['null', '{"id":1,"t":null}', 'nomatch'],
    ['0', '{"id":1,"t":0}', 'nomatch'],
    ['false', '{"id":1,"t":false}', 'nomatch'],
    ['missing', '{"id":1}', 'nomatch'],
]);
jf_rows('string where that looks like another type', ['fields' => $f, 'where' => ['v' => '5']], [
    ['string "5"', '{"id":1,"v":"5"}', 'match'],
    ['integer 5', '{"id":1,"v":5}', 'nomatch'],
    ['float 5.0', '{"id":1,"v":5.0}', 'nomatch'],
    ['string "5.0"', '{"id":1,"v":"5.0"}', 'nomatch'],
]);
jf_rows('string where "true" and "null"', ['fields' => $f, 'where' => ['v' => 'true', 'w' => 'null']], [
    ['both strings', '{"id":1,"v":"true","w":"null"}', 'match'],
    ['literals true / null', '{"id":1,"v":true,"w":null}', 'nomatch'],
    ['string true, literal null', '{"id":1,"v":"true","w":null}', 'nomatch'],
]);

jf_rows('int where 5: only an integer literal of equal value', ['fields' => $f, 'where' => ['n' => 5]], [
    ['5', '{"id":1,"n":5}', 'match'],
    ['6', '{"id":1,"n":6}', 'nomatch'],
    ['-5', '{"id":1,"n":-5}', 'nomatch'],
    ['4', '{"id":1,"n":4}', 'nomatch'],
    ['15', '{"id":1,"n":15}', 'nomatch'],
    ['50', '{"id":1,"n":50}', 'nomatch'],
    ['5.0 is a real literal', '{"id":1,"n":5.0}', 'nomatch'],
    ['5e0 is a real literal', '{"id":1,"n":5e0}', 'nomatch'],
    ['50e-1 is a real literal', '{"id":1,"n":50e-1}', 'nomatch'],
    ['0.5e1 is a real literal', '{"id":1,"n":0.5e1}', 'nomatch'],
    ['5.5', '{"id":1,"n":5.5}', 'nomatch'],
    ['string "5"', '{"id":1,"n":"5"}', 'nomatch'],
    ['true', '{"id":1,"n":true}', 'nomatch'],
    ['null', '{"id":1,"n":null}', 'nomatch'],
    ['missing', '{"id":1}', 'nomatch'],
    ['leading zero 05 is invalid JSON', '{"id":1,"n":05}', 'error'],
    ['plus sign +5 is invalid JSON', '{"id":1,"n":+5}', 'error'],
    ['RAW real 1e400: no match, no error', '{"id":1,"n":1e400}', 'nomatch'],
    ['RAW real -1e400', '{"id":1,"n":-1e400}', 'nomatch'],
    ['RAW integer: 400 digits', '{"id":1,"n":' . $d400 . '}', 'nomatch'],
    ['RAW integer: negative 400 digits', '{"id":1,"n":-' . $d400 . '}', 'nomatch'],
    ['RAW integer: uint64 max + 1', '{"id":1,"n":18446744073709551616}', 'nomatch'],
    ['RAW integer: int64 min - 1', '{"id":1,"n":-9223372036854775809}', 'nomatch'],
    ['UINT: uint64 max', '{"id":1,"n":18446744073709551615}', 'nomatch'],
]);
jf_rows('int where 0: -0 is the integer 0', ['fields' => $f, 'where' => ['n' => 0]], [
    ['0', '{"id":1,"n":0}', 'match'],
    ['-0', '{"id":1,"n":-0}', 'match'],
    ['0.0', '{"id":1,"n":0.0}', 'nomatch'],
    ['-0.0', '{"id":1,"n":-0.0}', 'nomatch'],
    ['0e0', '{"id":1,"n":0e0}', 'nomatch'],
    ['1e-400 (real)', '{"id":1,"n":1e-400}', 'nomatch'],
    ['false', '{"id":1,"n":false}', 'nomatch'],
    ['empty string', '{"id":1,"n":""}', 'nomatch'],
    ['null', '{"id":1,"n":null}', 'nomatch'],
]);
jf_rows('int where PHP_INT_MAX', ['fields' => $f, 'where' => ['n' => PHP_INT_MAX]], [
    ['int64 max', '{"id":1,"n":9223372036854775807}', 'match'],
    ['int64 max - 1', '{"id":1,"n":9223372036854775806}', 'nomatch'],
    ['2^63 (UINT)', '{"id":1,"n":9223372036854775808}', 'nomatch'],
    ['int64 max as real', '{"id":1,"n":9223372036854775807.0}', 'nomatch'],
    ['2^63 as real', '{"id":1,"n":9.223372036854775807e18}', 'nomatch'],
]);
jf_rows('int where PHP_INT_MIN', ['fields' => $f, 'where' => ['n' => PHP_INT_MIN]], [
    ['int64 min', '{"id":1,"n":-9223372036854775808}', 'match'],
    ['int64 min + 1', '{"id":1,"n":-9223372036854775807}', 'nomatch'],
    ['int64 min - 1 (RAW)', '{"id":1,"n":-9223372036854775809}', 'nomatch'],
    ['positive 2^63', '{"id":1,"n":9223372036854775808}', 'nomatch'],
]);
jf_rows('int where 2^53 + 1 (not representable as binary64)', ['fields' => $f, 'where' => ['n' => 9007199254740993]], [
    ['exact', '{"id":1,"n":9007199254740993}', 'match'],
    ['2^53', '{"id":1,"n":9007199254740992}', 'nomatch'],
    ['2^53 + 2', '{"id":1,"n":9007199254740994}', 'nomatch'],
    ['as real literal', '{"id":1,"n":9007199254740993.0}', 'nomatch'],
]);
jf_rows('int where -1', ['fields' => $f, 'where' => ['n' => -1]], [
    ['-1', '{"id":1,"n":-1}', 'match'],
    ['1', '{"id":1,"n":1}', 'nomatch'],
    ['-1.0', '{"id":1,"n":-1.0}', 'nomatch'],
    ['"-1"', '{"id":1,"n":"-1"}', 'nomatch'],
]);

jf_rows('float where 1.5: only a real literal, by IEEE equality', ['fields' => $f, 'where' => ['x' => 1.5]], [
    ['1.5', '{"id":1,"x":1.5}', 'match'],
    ['1.50', '{"id":1,"x":1.50}', 'match'],
    ['15e-1', '{"id":1,"x":15e-1}', 'match'],
    ['0.15E1', '{"id":1,"x":0.15E1}', 'match'],
    ['1.5e0', '{"id":1,"x":1.5e0}', 'match'],
    ['0.15e+1', '{"id":1,"x":0.15e+1}', 'match'],
    ['1.5000000000000002 (next binary64)', '{"id":1,"x":1.5000000000000002}', 'nomatch'],
    ['1.4999999999999998 (previous binary64)', '{"id":1,"x":1.4999999999999998}', 'nomatch'],
    ['1.6', '{"id":1,"x":1.6}', 'nomatch'],
    ['-1.5', '{"id":1,"x":-1.5}', 'nomatch'],
    ['integer 1', '{"id":1,"x":1}', 'nomatch'],
    ['integer 2', '{"id":1,"x":2}', 'nomatch'],
    ['string "1.5"', '{"id":1,"x":"1.5"}', 'nomatch'],
    ['true', '{"id":1,"x":true}', 'nomatch'],
    ['null', '{"id":1,"x":null}', 'nomatch'],
    ['missing', '{"id":1}', 'nomatch'],
    ['RAW integer 400 digits', '{"id":1,"x":' . $d400 . '}', 'nomatch'],
    ['RAW real 1e400', '{"id":1,"x":1e400}', 'nomatch'],
    ['.5 is invalid JSON', '{"id":1,"x":.5}', 'error'],
    ['1. is invalid JSON', '{"id":1,"x":1.}', 'error'],
]);
jf_rows('float where 5.0: never the literal 5', ['fields' => $f, 'where' => ['x' => 5.0]], [
    ['5.0', '{"id":1,"x":5.0}', 'match'],
    ['5e0', '{"id":1,"x":5e0}', 'match'],
    ['50e-1', '{"id":1,"x":50e-1}', 'match'],
    ['0.5E+1', '{"id":1,"x":0.5E+1}', 'match'],
    ['5', '{"id":1,"x":5}', 'nomatch'],
    ['-0 (integer literal 0)', '{"id":1,"x":-0}', 'nomatch'],
    ['"5"', '{"id":1,"x":"5"}', 'nomatch'],
    ['"5.0"', '{"id":1,"x":"5.0"}', 'nomatch'],
    ['5.1', '{"id":1,"x":5.1}', 'nomatch'],
]);
jf_rows('float where 0.0: IEEE equality, -0.0 equals 0.0', ['fields' => $f, 'where' => ['x' => 0.0]], [
    ['0.0', '{"id":1,"x":0.0}', 'match'],
    ['-0.0', '{"id":1,"x":-0.0}', 'match'],
    ['0e0', '{"id":1,"x":0e0}', 'match'],
    ['-0e5', '{"id":1,"x":-0e5}', 'match'],
    ['1e-400 (REAL, rounds to 0.0)', '{"id":1,"x":1e-400}', 'match'],
    ['-1e-400 (REAL, rounds to -0.0)', '{"id":1,"x":-1e-400}', 'match'],
    ['0 (integer literal)', '{"id":1,"x":0}', 'nomatch'],
    ['-0 (integer literal)', '{"id":1,"x":-0}', 'nomatch'],
    ['5e-324 (smallest subnormal)', '{"id":1,"x":5e-324}', 'nomatch'],
    ['0.1', '{"id":1,"x":0.1}', 'nomatch'],
]);
jf_rows('float where -0.0', ['fields' => $f, 'where' => ['x' => -0.0]], [
    ['-0.0', '{"id":1,"x":-0.0}', 'match'],
    ['0.0', '{"id":1,"x":0.0}', 'match'],
    ['0e10', '{"id":1,"x":0e10}', 'match'],
    ['0', '{"id":1,"x":0}', 'nomatch'],
    ['-0', '{"id":1,"x":-0}', 'nomatch'],
]);
jf_rows('float where 0.1', ['fields' => $f, 'where' => ['x' => 0.1]], [
    ['0.1', '{"id":1,"x":0.1}', 'match'],
    ['0.10', '{"id":1,"x":0.10}', 'match'],
    ['1e-1', '{"id":1,"x":1e-1}', 'match'],
    ['10e-2', '{"id":1,"x":10e-2}', 'match'],
    ['0.1000000000000000055511151231257827 is the same binary64', '{"id":1,"x":0.1000000000000000055511151231257827}', 'match'],
    ['0.2', '{"id":1,"x":0.2}', 'nomatch'],
    ['0.10000000000000002 (next binary64)', '{"id":1,"x":0.10000000000000002}', 'nomatch'],
]);
jf_rows('float where 1e300 and 1e21', ['fields' => $f, 'where' => ['big' => 1e300, 'e21' => 1e21]], [
    ['both', '{"id":1,"big":1e300,"e21":1e21}', 'match'],
    ['upper E and plus', '{"id":1,"big":1E+300,"e21":1E21}', 'match'],
    ['mantissa form', '{"id":1,"big":1.0e300,"e21":10e20}', 'match'],
    ['e21 spelled out with a fraction', '{"id":1,"big":1e300,"e21":1000000000000000000000.0}', 'match'],
    ['1e21 as an integer literal (RAW)', '{"id":1,"big":1e300,"e21":1000000000000000000000}', 'nomatch'],
    ['1e301', '{"id":1,"big":1e301,"e21":1e21}', 'nomatch'],
    ['RAW real', '{"id":1,"big":1e400,"e21":1e21}', 'nomatch'],
]);
jf_rows('float where 1.7976931348623157e308 (largest finite)', ['fields' => $f, 'where' => ['x' => 1.7976931348623157e308]], [
    ['same literal', '{"id":1,"x":1.7976931348623157e308}', 'match'],
    ['1e309 is RAW', '{"id":1,"x":1e309}', 'nomatch'],
    ['smaller', '{"id":1,"x":1.7976931348623155e308}', 'nomatch'],
]);
jf_rows('float where 1.8446744073709552e19 against integer literals', ['fields' => $f, 'where' => ['x' => 1.8446744073709552e19]], [
    ['uint64 max as integer literal (UINT)', '{"id":1,"x":18446744073709551615}', 'nomatch'],
    ['2^64 as integer literal (RAW)', '{"id":1,"x":18446744073709551616}', 'nomatch'],
    ['2^64 as real literal', '{"id":1,"x":18446744073709551616.0}', 'match'],
    ['1.8446744073709552e19', '{"id":1,"x":1.8446744073709552e19}', 'match'],
]);

jf_rows('bool where true', ['fields' => $f, 'where' => ['b' => true]], [
    ['true', '{"id":1,"b":true}', 'match'],
    ['false', '{"id":1,"b":false}', 'nomatch'],
    ['string "true"', '{"id":1,"b":"true"}', 'nomatch'],
    ['integer 1', '{"id":1,"b":1}', 'nomatch'],
    ['real 1.0', '{"id":1,"b":1.0}', 'nomatch'],
    ['null', '{"id":1,"b":null}', 'nomatch'],
    ['missing', '{"id":1}', 'nomatch'],
    ['capitalised True is invalid JSON', '{"id":1,"b":True}', 'error'],
]);
jf_rows('bool where false', ['fields' => $f, 'where' => ['b' => false]], [
    ['false', '{"id":1,"b":false}', 'match'],
    ['true', '{"id":1,"b":true}', 'nomatch'],
    ['integer 0', '{"id":1,"b":0}', 'nomatch'],
    ['real 0.0', '{"id":1,"b":0.0}', 'nomatch'],
    ['string "false"', '{"id":1,"b":"false"}', 'nomatch'],
    ['empty string', '{"id":1,"b":""}', 'nomatch'],
    ['null', '{"id":1,"b":null}', 'nomatch'],
    ['missing', '{"id":1}', 'nomatch'],
]);
jf_rows('null where', ['fields' => $f, 'where' => ['z' => null]], [
    ['null', '{"id":1,"z":null}', 'match'],
    ['0', '{"id":1,"z":0}', 'nomatch'],
    ['empty string', '{"id":1,"z":""}', 'nomatch'],
    ['false', '{"id":1,"z":false}', 'nomatch'],
    ['string "null"', '{"id":1,"z":"null"}', 'nomatch'],
    ['missing key is not null', '{"id":1}', 'nomatch'],
    ['null in another key', '{"id":1,"y":null}', 'nomatch'],
]);

jf_rows('array or object values of a filter key raise RecordException', ['fields' => $f, 'where' => ['k' => 'x']], [
    ['empty array', '{"id":1,"k":[]}', 'error'],
    ['array holding the match value', '{"id":1,"k":["x"]}', 'error'],
    ['empty object', '{"id":1,"k":{}}', 'error'],
    ['object', '{"id":1,"k":{"a":1}}', 'error'],
    ['deeply nested array', '{"id":1,"k":[[[[["x"]]]]]}', 'error'],
    ['array before the id field', '{"k":[1],"id":1}', 'error'],
    ['array with a SECRET marker', '{"id":1,"k":["SECRETMARK"]}', 'error'],
    ['same shapes under another key are fine', '{"id":1,"other":[1,{"a":[]}],"k":"x"}', 'match'],
    ['key absent, other keys hold arrays', '{"id":1,"other":[1]}', 'nomatch'],
]);
jf_rows('a non-scalar filter value is an error even when an earlier filter already failed', ['fields' => $f, 'where' => ['a' => 1, 'k' => 'x']], [
    ['a mismatches, k is an array', '{"id":1,"a":2,"k":[1]}', 'error'],
    ['a missing, k is an object', '{"id":1,"k":{}}', 'error'],
    ['a matches, k is an array', '{"id":1,"a":1,"k":[]}', 'error'],
    ['a mismatches, k mismatches', '{"id":1,"a":2,"k":"y"}', 'nomatch'],
    ['a is an array, k missing', '{"id":1,"a":[1]}', 'error'],
    ['both match', '{"id":1,"a":1,"k":"x"}', 'match'],
]);

jf_rows('several where keys are ANDed', ['fields' => $f, 'where' => ['a' => 1, 'b' => 'x', 'c' => true, 'd' => null, 'e' => 1.5]], [
    ['all match', '{"id":1,"a":1,"b":"x","c":true,"d":null,"e":1.5}', 'match'],
    ['all match, record order reversed', '{"e":1.5,"d":null,"c":true,"b":"x","a":1,"id":1}', 'match'],
    ['extra keys are ignored', '{"id":1,"zz":0,"a":1,"b":"x","c":true,"d":null,"e":1.5,"yy":[1]}', 'match'],
    ['first differs', '{"id":1,"a":2,"b":"x","c":true,"d":null,"e":1.5}', 'nomatch'],
    ['second differs', '{"id":1,"a":1,"b":"y","c":true,"d":null,"e":1.5}', 'nomatch'],
    ['third differs', '{"id":1,"a":1,"b":"x","c":false,"d":null,"e":1.5}', 'nomatch'],
    ['fourth differs (0 instead of null)', '{"id":1,"a":1,"b":"x","c":true,"d":0,"e":1.5}', 'nomatch'],
    ['last differs (integer 1 instead of 1.5)', '{"id":1,"a":1,"b":"x","c":true,"d":null,"e":1}', 'nomatch'],
    ['first missing', '{"id":1,"b":"x","c":true,"d":null,"e":1.5}', 'nomatch'],
    ['null key missing', '{"id":1,"a":1,"b":"x","c":true,"e":1.5}', 'nomatch'],
    ['last missing', '{"id":1,"a":1,"b":"x","c":true,"d":null}', 'nomatch'],
    ['nothing but id', '{"id":1}', 'nomatch'],
]);
$w16 = [];
for ($i = 1; $i <= 16; $i++) {
    $w16["w$i"] = $i;
}
$all16 = '{"id":1,' . implode(',', array_map(fn($i) => "\"w$i\":$i", range(1, 16))) . '}';
$one16 = '{"id":1,' . implode(',', array_map(fn($i) => "\"w$i\":" . ($i === 16 ? 99 : $i), range(1, 16))) . '}';
jf_rows('sixteen where keys', ['fields' => $f, 'where' => $w16], [
    ['all sixteen match', $all16, 'match'],
    ['the last one differs', $one16, 'nomatch'],
    ['the last one is missing', '{"id":1,' . implode(',', array_map(fn($i) => "\"w$i\":$i", range(1, 15))) . '}', 'nomatch'],
]);

jf_rows('a key listed in both fields and where is type-checked as a field first (int64)', ['fields' => ['a' => 'int64'], 'where' => ['a' => 5]], [
    ['5 matches', '{"a":5}', 'match'],
    ['6 does not match', '{"a":6}', 'nomatch'],
    ['"5" fails the field type, not a mismatch', '{"a":"5"}', 'error'],
    ['5.0 fails the field type', '{"a":5.0}', 'error'],
    ['null fails the non-nullable field', '{"a":null}', 'error'],
    ['missing fails the field', '{"b":5}', 'error'],
    ['array fails the field', '{"a":[5]}', 'error'],
]);
jf_rows('a key in both fields and where (int64?, where null)', ['fields' => ['a' => 'int64?'], 'where' => ['a' => null]], [
    ['null matches', '{"a":null}', 'match'],
    ['5 is a valid field value but does not match', '{"a":5}', 'nomatch'],
    ['"x" fails the field type', '{"a":"x"}', 'error'],
    ['missing is still an error (nullable is not optional)', '{}', 'error'],
]);
jf_rows('a key in both fields and where (string)', ['fields' => ['a' => 'string'], 'where' => ['a' => 'x']], [
    ['x', '{"a":"x"}', 'match'],
    ['y', '{"a":"y"}', 'nomatch'],
    ['escaped x', '{"a":"x"}', 'match'],
    ['integer fails the field', '{"a":1}', 'error'],
]);
jf_rows('a key in both fields and where (float64, where 1.0)', ['fields' => ['a' => 'float64'], 'where' => ['a' => 1.0]], [
    ['1.0', '{"a":1.0}', 'match'],
    ['1e0', '{"a":1e0}', 'match'],
    ['integer 1 is a valid float64 but never matches a float filter', '{"a":1}', 'nomatch'],
    ['2.0', '{"a":2.0}', 'nomatch'],
    ['string fails the field', '{"a":"1.0"}', 'error'],
    ['1e400 fails the field (RAW)', '{"a":1e400}', 'error'],
]);
jf_rows('a key in both fields and where (bool)', ['fields' => ['a' => 'bool'], 'where' => ['a' => false]], [
    ['false', '{"a":false}', 'match'],
    ['true', '{"a":true}', 'nomatch'],
    ['0 fails the field', '{"a":0}', 'error'],
]);
jf_rows('a filter on a field in another position', ['fields' => ['a' => 'int64', 'b' => 'string'], 'where' => ['b' => 'x']], [
    ['b matches', '{"a":1,"b":"x"}', 'match'],
    ['b valid but different', '{"a":1,"b":"y"}', 'nomatch'],
    ['b has the wrong type', '{"a":1,"b":2}', 'error'],
]);

jf_rows('absent where matches every record', ['fields' => $f], [
    ['plain', '{"id":1}', 'match'],
    ['with other keys', '{"id":1,"a":[1,2],"b":{"c":null}}', 'match'],
    ['with a SECRET value', '{"id":1,"a":"SECRETMARK"}', 'match'],
]);
jf_rows('empty where matches every record', ['fields' => $f, 'where' => []], [
    ['plain', '{"id":1}', 'match'],
    ['with other keys', '{"id":1,"kind":"x"}', 'match'],
]);

echo "-- whole runs: counters, order of failures, output is the reference's\n";
function run_counts(string $name, string $data, array $spec, int $read, int $matched): void
{
    $in = jf_mem($data);
    $out = jf_out();
    $s = Runner::process($in, $out, jf_plan($spec));
    rewind($out);
    $ref = JfRef::run($data, $spec);
    $want = sprintf('read=%d matched=%d emitted=%d bytesRead=%d bytesWritten=%d', $read, $matched, $matched, strlen($data), strlen($ref['out']));
    $ok = $ref['matched'] === $matched && jf_stats($s) === $want && stream_get_contents($out) === $ref['out'];
    echo str_pad($name, 50), $ok ? 'ok' : 'FAIL ' . jf_stats($s) . ' want ' . $want, "\n";
    fclose($in);
    fclose($out);
}

$kind = ['fields' => $f, 'where' => ['kind' => 'customer']];
run_counts('5 records, 3 match', implode("\n", [
    '{"id":1,"kind":"customer"}', '{"id":2,"kind":"vendor"}', '{"id":3,"kind":"customer"}',
    '{"id":4}', '{"id":5,"kind":"customer"}',
]) . "\n", $kind, 5, 3);
run_counts('same, CRLF and no final terminator', implode("\r\n", [
    '{"id":1,"kind":"customer"}', '{"id":2,"kind":"vendor"}', '{"id":3,"kind":"customer"}',
]), $kind, 3, 2);
run_counts('none match', "{\"id\":1,\"kind\":\"a\"}\n{\"id\":2}\n", $kind, 2, 0);
run_counts('all match, no where', "{\"id\":1}\n{\"id\":2}\n{\"id\":3}\n", ['fields' => $f], 3, 3);
run_counts('skipped blank lines are not records', "{\"id\":1}\n\n{\"id\":2}\n\n\n", ['fields' => $f, 'skipEmptyLines' => true], 2, 2);
run_counts('skipped blanks, where', "\n{\"id\":1,\"kind\":\"customer\"}\r\n\r\n{\"id\":2}\n", $kind + ['skipEmptyLines' => true], 2, 1);
run_counts('empty input', '', $kind, 0, 0);
run_counts('3000 records, every third matches', implode('', array_map(
    fn($i) => '{"id":' . $i . ',"kind":"' . ($i % 3 === 0 ? 'customer' : 'other') . "\"}\n", range(1, 3000))), $kind, 3000, 1000);

echo "-- a failing record ends the run with line, record and offset\n";
$ok1 = '{"id":1,"kind":"customer"}';
$ok2 = '{"id":2,"kind":"vendor"}';
$line3 = '{"id":"3","kind":"customer"}';
echo str_pad('type error on line 3 (matching kind)', 50),
    jf_expect_error("$ok1\n$ok2\n$line3\n$ok1\n", RecordException::class, 'line=3 record=3 offset=' . (strlen($ok1) + strlen($ok2) + 2), ['SECRET'], $kind), "\n";
echo str_pad('type error on line 2 (non-matching kind), CRLF', 50),
    jf_expect_error("$ok1\r\n" . '{"id":"2","kind":"vendor"}' . "\r\n$ok1\r\n", RecordException::class, 'line=2 record=2 offset=' . (strlen($ok1) + 2), [], $kind), "\n";
echo str_pad('missing id on a record that matches nothing', 50),
    jf_expect_error("$ok2\n{\"kind\":\"vendor\"}\n", RecordException::class, 'line=2 record=2 offset=' . (strlen($ok2) + 1), [], $kind), "\n";
echo str_pad('non-scalar filter value on line 2', 50),
    jf_expect_error("$ok1\n{\"id\":2,\"kind\":[\"customer\"]}\n", RecordException::class, 'line=2 record=2 offset=' . (strlen($ok1) + 1), ['customer"]'], $kind), "\n";
echo str_pad('error after skipped blank lines', 50),
    jf_expect_error("\n$ok1\n\n{\"id\":null}\n", RecordException::class, 'line=4 record=2 offset=' . (strlen($ok1) + 3), [], $kind + ['skipEmptyLines' => true]), "\n";

echo "-- only the declared fields of the matching record are written\n";
$in = jf_mem("$ok1\n$ok2\n");
$out = jf_out();
$s = Runner::process($in, $out, jf_plan($kind));
var_dump($s->recordsMatched, $s->recordsEmitted, $s->bytesWritten, ftell($out));
rewind($out);
var_dump(stream_get_contents($out));
fclose($in);
fclose($out);
?>
--EXPECT--
-- string where: byte-exact after escape decoding, case-sensitive
exact                                                             ok
other value                                                       ok
capitalised                                                       ok
upper case                                                        ok
longer (prefix of record value)                                   ok
shorter (prefix of where value)                                   ok
trailing space                                                    ok
leading space                                                     ok
empty string                                                      ok
missing key                                                       ok
key differs by case                                               ok
key only nested                                                   ok
key only inside array element                                     ok
value is null                                                     ok
value is number                                                   ok
value is true                                                     ok
escaped e: custom\u0065r                                          ok
escaped first letter                                              ok
escaped letter, upper-case hex digits                             ok
every letter escaped                                              ok
escape decodes to a different letter                              ok
text \u0063 without the backslash escape                          ok
SECRET marker value does not match                                ok
-- string where with non-ASCII bytes
raw UTF-8                                                         ok
escaped \u00e9                                                    ok
escaped \u00E9 (upper-case hex)                                   ok
decomposed e + U+0301 is another byte string                      ok
plain e                                                           ok
upper-case \u00c9                                                 ok
-- string where with a 4-byte character
raw UTF-8                                                         ok
surrogate pair, lower-case hex                                    ok
surrogate pair, upper-case hex                                    ok
another emoji                                                     ok
-- string where with control characters and punctuation
escapes \n \t \" \ \/                                             ok
\/ replaced by raw /                                              ok
\u000a instead of backslash-n                                     ok
\n left out                                                       ok
backslash then n (two chars)                                      ok
-- string where with NUL and empty values
\u0000 in the middle                                              ok
no NUL                                                            ok
NUL at the end only                                               ok
NUL, another letter                                               ok
-- empty-string where
empty string                                                      ok
one space                                                         ok
\u0000                                                            ok
null                                                              ok
0                                                                 ok
false                                                             ok
missing                                                           ok
-- string where that looks like another type
string "5"                                                        ok
integer 5                                                         ok
float 5.0                                                         ok
string "5.0"                                                      ok
-- string where "true" and "null"
both strings                                                      ok
literals true / null                                              ok
string true, literal null                                         ok
-- int where 5: only an integer literal of equal value
5                                                                 ok
6                                                                 ok
-5                                                                ok
4                                                                 ok
15                                                                ok
50                                                                ok
5.0 is a real literal                                             ok
5e0 is a real literal                                             ok
50e-1 is a real literal                                           ok
0.5e1 is a real literal                                           ok
5.5                                                               ok
string "5"                                                        ok
true                                                              ok
null                                                              ok
missing                                                           ok
leading zero 05 is invalid JSON                                   ok
plus sign +5 is invalid JSON                                      ok
RAW real 1e400: no match, no error                                ok
RAW real -1e400                                                   ok
RAW integer: 400 digits                                           ok
RAW integer: negative 400 digits                                  ok
RAW integer: uint64 max + 1                                       ok
RAW integer: int64 min - 1                                        ok
UINT: uint64 max                                                  ok
-- int where 0: -0 is the integer 0
0                                                                 ok
-0                                                                ok
0.0                                                               ok
-0.0                                                              ok
0e0                                                               ok
1e-400 (real)                                                     ok
false                                                             ok
empty string                                                      ok
null                                                              ok
-- int where PHP_INT_MAX
int64 max                                                         ok
int64 max - 1                                                     ok
2^63 (UINT)                                                       ok
int64 max as real                                                 ok
2^63 as real                                                      ok
-- int where PHP_INT_MIN
int64 min                                                         ok
int64 min + 1                                                     ok
int64 min - 1 (RAW)                                               ok
positive 2^63                                                     ok
-- int where 2^53 + 1 (not representable as binary64)
exact                                                             ok
2^53                                                              ok
2^53 + 2                                                          ok
as real literal                                                   ok
-- int where -1
-1                                                                ok
1                                                                 ok
-1.0                                                              ok
"-1"                                                              ok
-- float where 1.5: only a real literal, by IEEE equality
1.5                                                               ok
1.50                                                              ok
15e-1                                                             ok
0.15E1                                                            ok
1.5e0                                                             ok
0.15e+1                                                           ok
1.5000000000000002 (next binary64)                                ok
1.4999999999999998 (previous binary64)                            ok
1.6                                                               ok
-1.5                                                              ok
integer 1                                                         ok
integer 2                                                         ok
string "1.5"                                                      ok
true                                                              ok
null                                                              ok
missing                                                           ok
RAW integer 400 digits                                            ok
RAW real 1e400                                                    ok
.5 is invalid JSON                                                ok
1. is invalid JSON                                                ok
-- float where 5.0: never the literal 5
5.0                                                               ok
5e0                                                               ok
50e-1                                                             ok
0.5E+1                                                            ok
5                                                                 ok
-0 (integer literal 0)                                            ok
"5"                                                               ok
"5.0"                                                             ok
5.1                                                               ok
-- float where 0.0: IEEE equality, -0.0 equals 0.0
0.0                                                               ok
-0.0                                                              ok
0e0                                                               ok
-0e5                                                              ok
1e-400 (REAL, rounds to 0.0)                                      ok
-1e-400 (REAL, rounds to -0.0)                                    ok
0 (integer literal)                                               ok
-0 (integer literal)                                              ok
5e-324 (smallest subnormal)                                       ok
0.1                                                               ok
-- float where -0.0
-0.0                                                              ok
0.0                                                               ok
0e10                                                              ok
0                                                                 ok
-0                                                                ok
-- float where 0.1
0.1                                                               ok
0.10                                                              ok
1e-1                                                              ok
10e-2                                                             ok
0.1000000000000000055511151231257827 is the same binary64         ok
0.2                                                               ok
0.10000000000000002 (next binary64)                               ok
-- float where 1e300 and 1e21
both                                                              ok
upper E and plus                                                  ok
mantissa form                                                     ok
e21 spelled out with a fraction                                   ok
1e21 as an integer literal (RAW)                                  ok
1e301                                                             ok
RAW real                                                          ok
-- float where 1.7976931348623157e308 (largest finite)
same literal                                                      ok
1e309 is RAW                                                      ok
smaller                                                           ok
-- float where 1.8446744073709552e19 against integer literals
uint64 max as integer literal (UINT)                              ok
2^64 as integer literal (RAW)                                     ok
2^64 as real literal                                              ok
1.8446744073709552e19                                             ok
-- bool where true
true                                                              ok
false                                                             ok
string "true"                                                     ok
integer 1                                                         ok
real 1.0                                                          ok
null                                                              ok
missing                                                           ok
capitalised True is invalid JSON                                  ok
-- bool where false
false                                                             ok
true                                                              ok
integer 0                                                         ok
real 0.0                                                          ok
string "false"                                                    ok
empty string                                                      ok
null                                                              ok
missing                                                           ok
-- null where
null                                                              ok
0                                                                 ok
empty string                                                      ok
false                                                             ok
string "null"                                                     ok
missing key is not null                                           ok
null in another key                                               ok
-- array or object values of a filter key raise RecordException
empty array                                                       ok
array holding the match value                                     ok
empty object                                                      ok
object                                                            ok
deeply nested array                                               ok
array before the id field                                         ok
array with a SECRET marker                                        ok
same shapes under another key are fine                            ok
key absent, other keys hold arrays                                ok
-- a non-scalar filter value is an error even when an earlier filter already failed
a mismatches, k is an array                                       ok
a missing, k is an object                                         ok
a matches, k is an array                                          ok
a mismatches, k mismatches                                        ok
a is an array, k missing                                          ok
both match                                                        ok
-- several where keys are ANDed
all match                                                         ok
all match, record order reversed                                  ok
extra keys are ignored                                            ok
first differs                                                     ok
second differs                                                    ok
third differs                                                     ok
fourth differs (0 instead of null)                                ok
last differs (integer 1 instead of 1.5)                           ok
first missing                                                     ok
null key missing                                                  ok
last missing                                                      ok
nothing but id                                                    ok
-- sixteen where keys
all sixteen match                                                 ok
the last one differs                                              ok
the last one is missing                                           ok
-- a key listed in both fields and where is type-checked as a field first (int64)
5 matches                                                         ok
6 does not match                                                  ok
"5" fails the field type, not a mismatch                          ok
5.0 fails the field type                                          ok
null fails the non-nullable field                                 ok
missing fails the field                                           ok
array fails the field                                             ok
-- a key in both fields and where (int64?, where null)
null matches                                                      ok
5 is a valid field value but does not match                       ok
"x" fails the field type                                          ok
missing is still an error (nullable is not optional)              ok
-- a key in both fields and where (string)
x                                                                 ok
y                                                                 ok
escaped x                                                         ok
integer fails the field                                           ok
-- a key in both fields and where (float64, where 1.0)
1.0                                                               ok
1e0                                                               ok
integer 1 is a valid float64 but never matches a float filter     ok
2.0                                                               ok
string fails the field                                            ok
1e400 fails the field (RAW)                                       ok
-- a key in both fields and where (bool)
false                                                             ok
true                                                              ok
0 fails the field                                                 ok
-- a filter on a field in another position
b matches                                                         ok
b valid but different                                             ok
b has the wrong type                                              ok
-- absent where matches every record
plain                                                             ok
with other keys                                                   ok
with a SECRET value                                               ok
-- empty where matches every record
plain                                                             ok
with other keys                                                   ok
-- whole runs: counters, order of failures, output is the reference's
5 records, 3 match                                ok
same, CRLF and no final terminator                ok
none match                                        ok
all match, no where                               ok
skipped blank lines are not records               ok
skipped blanks, where                             ok
empty input                                       ok
3000 records, every third matches                 ok
-- a failing record ends the run with line, record and offset
type error on line 3 (matching kind)              ok
type error on line 2 (non-matching kind), CRLF    ok
missing id on a record that matches nothing       ok
non-scalar filter value on line 2                 ok
error after skipped blank lines                   ok
-- only the declared fields of the matching record are written
int(1)
int(1)
int(9)
int(9)
string(9) "{"id":1}
"
