--TEST--
jsonfuse: duplicate referenced keys raise RecordException (bytes after escape decoding, any position); unreferenced duplicates are accepted at any depth
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;

// 'error' = RecordException for a duplicate key, 'match' / 'nomatch' = accepted.
// Every 'error' row is a record that would be accepted if the duplicate were removed, so a
// bare "missing field" cannot satisfy it. Duplicates are never resolved first-wins or
// last-wins: each "wins" direction gets a row whose other copy would have made the record valid.

$A = ['fields' => ['i' => 'int64']];
jf_rows('duplicates of a fields key', $A, [
    ['control: no duplicate', '{"i":1}', 'match'],
    ['i twice, different values', '{"i":1,"i":2}', 'error'],
    ['i twice, same value', '{"i":1,"i":1}', 'error'],
    ['i twice, non-adjacent', '{"i":1,"x":"a","i":2}', 'error'],
    ['i twice, first copy invalid (last-wins would accept)', '{"i":"bad","i":1}', 'error'],
    ['i twice, last copy invalid (first-wins would accept)', '{"i":1,"i":"bad"}', 'error'],
    ['i twice, second copy null', '{"i":1,"i":null}', 'error'],
    ['i three times', '{"i":1,"i":2,"i":3}', 'error'],
    ['i twice with SECRET values', '{"i":"SECRET1","i":"SECRET2"}', 'error'],
    ['key differing by case is not a duplicate', '{"i":1,"I":2}', 'match'],
    ['key with a trailing space is not a duplicate', '{"i":1,"i ":2}', 'match'],
    ['key with a leading space is not a duplicate', '{"i":1," i":2}', 'match'],
    ['two copies of an unreferenced key', '{"i":1,"j":1,"j":2}', 'match'],
]);

$A2 = ['fields' => ['i' => 'int64', 's' => 'string?']];
jf_rows('duplicates of a nullable fields key', $A2, [
    ['control: both present', '{"i":1,"s":null}', 'match'],
    ['s twice, different values', '{"s":"a","s":"b","i":1}', 'error'],
    ['s twice, null then string', '{"s":null,"s":"x","i":1}', 'error'],
    ['s twice, same value', '{"i":1,"s":"a","s":"a"}', 'error'],
    ['i twice, s between', '{"i":1,"s":"a","i":2}', 'error'],
    ['i twice, before s', '{"i":1,"i":2,"s":"x"}', 'error'],
]);

jf_rows('duplicates through escapes (compared as bytes after decoding)', $A, [
    ['literal then \u0069', '{"i":1,"\u0069":2}', 'error'],
    ['\u0069 then literal', '{"\u0069":1,"i":2}', 'error'],
    ['\u0069 twice', '{"\u0069":1,"\u0069":2}', 'error'],
    ['\u0069 once is just i', '{"\u0069":1}', 'match'],
    ['\u0069 then \u0049 (I) is no duplicate', '{"\u0069":1,"\u0049":2}', 'match'],
    ['\u0069 twice, then a literal copy', '{"\u0069":1,"\u0069":2,"i":3}', 'error'],
    ['i escaped, then its duplicate after other keys', '{"\u0069":1,"x":1,"y":2,"i":3}', 'error'],
]);
jf_rows('duplicates through escapes of a nullable key', $A2, [
    ['s versus \u0073', '{"i":1,"s":"a","\u0073":"b"}', 'error'],
    ['both copies of s escaped', '{"i":1,"\u0073":"a","\u0073":"b"}', 'error'],
    ['s escaped once', '{"i":1,"\u0073":"a"}', 'match'],
]);

jf_rows('duplicates of a non-ASCII field name', ['fields' => ["caf\u{e9}" => 'int64']], [
    ['literal then escaped', "{\"caf\u{e9}\":1,\"caf\u00e9\":2}", 'error'],
    ['escaped then literal', "{\"caf\u00e9\":1,\"caf\u{e9}\":2}", 'error'],
    ['both escaped, hex digit case differs', '{"caf\u00e9":1,"caf\u00E9":2}', 'error'],
    ['both literal', "{\"caf\u{e9}\":1,\"caf\u{e9}\":2}", 'error'],
    ['escaped once', '{"caf\u00e9":1}', 'match'],
    ['literal once', "{\"caf\u{e9}\":1}", 'match'],
    ['decomposed form is another key, unreferenced', '{"caf\u00e9":1,"cafe\u0301":2}', 'match'],
    ['upper-case letter is another key', '{"caf\u00e9":1,"caf\u00c9":2}', 'match'],
]);
jf_rows('duplicates of a 4-byte field name (surrogate pairs)', ['fields' => ["\u{1F600}" => 'int64']], [
    ['literal then pair', "{\"\u{1F600}\":1,\"\ud83d\ude00\":2}", 'error'],
    ['pair then literal', "{\"\ud83d\ude00\":1,\"\u{1F600}\":2}", 'error'],
    ['pair twice, hex digit case differs', '{"\ud83d\ude00":1,"\uD83D\uDE00":2}', 'error'],
    ['pair once', '{"\ud83d\ude00":1}', 'match'],
    ['a different emoji is another key', '{"\ud83d\ude00":1,"\ud83d\ude01":2}', 'match'],
    ['literal and pair with a key between', "{\"\ud83d\ude00\":1,\"x\":0,\"\u{1F600}\":2}", 'error'],
]);
jf_rows('duplicates of a field name with NUL', ['fields' => ["a\0b" => 'int64']], [
    ['\u0000 twice', '{"a\u0000b":1,"a\u0000b":2}', 'error'],
    ['\u0000 once', '{"a\u0000b":1}', 'match'],
    ['a different key past the NUL is unreferenced', '{"a\u0000b":1,"a\u0000c":2}', 'match'],
    ['the part before the NUL is another key', '{"a\u0000b":1,"a":2}', 'match'],
]);
jf_rows('duplicates of field names with solidus and quote', ['fields' => ['a/b' => 'int64', 'q"x' => 'int64']], [
    ['a/b literal and escaped solidus', '{"a/b":1,"q\u0022x":1,"a\/b":2}', 'error'],
    ['q"x with two spellings of the quote', '{"a/b":1,"q\"x":1,"q\u0022x":2}', 'error'],
    ['one spelling each', '{"a\/b":1,"q\u0022x":1}', 'match'],
]);
jf_rows('duplicates of special field names', ['fields' => ['' => 'int64', '123' => 'int64?', '__proto__' => 'int64?']], [
    ['control: each once', '{"":1,"123":null,"__proto__":null}', 'match'],
    ['empty key twice', '{"":1,"123":null,"__proto__":null,"":2}', 'error'],
    ['123 twice', '{"":1,"123":1,"123":2,"__proto__":null}', 'error'],
    ['123 spelled with escapes', '{"":1,"123":1,"\u0031\u0032\u0033":2,"__proto__":null}', 'error'],
    ['0123 is another key', '{"":1,"123":1,"0123":2,"__proto__":null}', 'match'],
    ['__proto__ twice', '{"":1,"123":null,"__proto__":1,"__proto__":2}', 'error'],
    ['__proto__ with an escaped underscore', '{"":1,"123":null,"__proto__":1,"\u005f_proto__":2}', 'error'],
]);

$B = ['fields' => ['i' => 'int64'], 'where' => ['w' => 'x']];
jf_rows('duplicates of a where key', $B, [
    ['control: w once, matching', '{"i":1,"w":"x"}', 'match'],
    ['w twice, different values', '{"i":1,"w":"x","w":"y"}', 'error'],
    ['w twice, same value', '{"i":1,"w":"x","w":"x"}', 'error'],
    ['w twice, first mismatches, last matches (last-wins would match)', '{"i":1,"w":"y","w":"x"}', 'error'],
    ['w twice, first matches, last mismatches (first-wins would match)', '{"i":1,"w":"x","w":"y"}', 'error'],
    ['w twice, neither matches', '{"i":1,"w":"y","w":"z"}', 'error'],
    ['w twice, one copy an array', '{"i":1,"w":[1],"w":"x"}', 'error'],
    ['w twice through an escape', '{"i":1,"\u0077":"x","w":"x"}', 'error'],
    ['w twice, both escaped', '{"i":1,"\u0077":"y","\u0077":"y"}', 'error'],
    ['w twice, SECRET values', '{"i":1,"w":"SECRET1","w":"SECRET2"}', 'error'],
    ['W is another key', '{"i":1,"W":"x","w":"x"}', 'match'],
    ['W alone does not satisfy the filter', '{"i":1,"W":"x"}', 'nomatch'],
    ['w absent: nothing to duplicate', '{"i":1}', 'nomatch'],
    ['duplicates of i still count', '{"i":1,"i":2,"w":"x"}', 'error'],
]);
jf_rows('duplicate of a key in both fields and where', ['fields' => ['a' => 'int64'], 'where' => ['a' => 5]], [
    ['a once', '{"a":5}', 'match'],
    ['a twice, both 5', '{"a":5,"a":5}', 'error'],
    ['a twice, 5 then 6', '{"a":5,"a":6}', 'error'],
]);
$many = ['fields' => ['i' => 'int64'], 'where' => ['w1' => 1, 'w2' => 2, 'w3' => 3, 'w4' => 4]];
jf_rows('duplicates among several where keys', $many, [
    ['no duplicate', '{"i":1,"w1":1,"w2":2,"w3":3,"w4":4}', 'match'],
    ['w3 twice, spread out', '{"w3":3,"i":1,"w1":1,"w2":2,"w4":4,"w3":3}', 'error'],
    ['w4 twice, adjacent, record ends there', '{"i":1,"w1":1,"w2":2,"w3":3,"w4":4,"w4":4}', 'error'],
    ['only unreferenced keys repeat', '{"i":1,"w1":1,"w2":2,"w3":3,"w4":4,"w5":0,"w5":1}', 'match'],
]);

// "Every root key is inspected on every record; processing never stops once all referenced keys are found."
$C = ['fields' => ['i' => 'int64', 's' => 'string?'], 'where' => ['w' => 'x']];
$filler = function (int $n): string {
    $o = [];
    for ($k = 0; $k < $n; $k++) {
        $o[] = "\"f$k\":$k";
    }
    return implode(',', $o);
};
$base = '"i":1,"s":"a","w":"x"';
jf_rows('a duplicate after every referenced key was already seen', $C, [
    ['control: 300 keys and no duplicate', "{{$base}," . $filler(300) . '}', 'match'],
    ['control: referenced keys come last', '{' . $filler(300) . ",{$base}}", 'match'],
    ['control: only unreferenced keys repeat afterwards', "{{$base},\"z\":1,\"z\":2}", 'match'],
    ['all three seen, then i again', '{"i":1,"s":null,"w":"x","pad":1,"i":2}', 'error'],
    ['all three seen, then w again at the very end', "{{$base},\"w\":\"x\"}", 'error'],
    ['all three seen, then s again after 300 keys', "{{$base}," . $filler(300) . ',"s":"b"}', 'error'],
    ['all three seen, then w again after 300 keys', "{{$base}," . $filler(300) . ',"w":"y"}', 'error'],
    ['i again after 5000 keys', "{{$base}," . $filler(5000) . ',"i":2}', 'error'],
    ['i again as an escape, after other keys', "{{$base},\"z\":1,\"\u0069\":2}", 'error'],
    ['i again after a big nested array', "{{$base},\"big\":[" . str_repeat('0,', 1000) . '0],"i":2}', 'error'],
    ['i again after a nested object holding a copy of i', "{{$base},\"o\":{\"i\":1},\"i\":2}", 'error'],
]);

jf_rows('unreferenced duplicates are accepted at the top level', $C, [
    ['z twice', "{{$base},\"z\":1,\"z\":2}", 'match'],
    ['z with different types', "{{$base},\"z\":1,\"z\":\"a\",\"z\":null,\"z\":[1],\"z\":{}}", 'match'],
    ['z a thousand times', "{{$base}" . str_repeat(',"z":1', 1000) . '}', 'match'],
    ['z and its escape', "{{$base},\"z\":1,\"\u007a\":2}", 'match'],
    ['both copies escaped', "{{$base},\"\u007a\":1,\"\u007a\":2}", 'match'],
    ['non-adjacent', '{"z":1,"i":1,"y":1,"s":null,"z":2,"w":"x","y":3}', 'match'],
    ['empty key twice', "{{$base},\"\":1,\"\":2}", 'match'],
    ['I twice next to i', "{\"I\":1,\"I\":2,{$base}}", 'match'],
    ['literal and escaped non-ASCII key', "{{$base},\"caf\u{e9}\":1,\"caf\u00e9\":2}", 'match'],
    ['duplicate keys holding RAW numbers', "{{$base},\"z\":1e400,\"z\":" . str_repeat('9', 400) . '}', 'match'],
    ['duplicates next to a filter that does not match', '{"i":1,"s":null,"w":"y","z":1,"z":2}', 'nomatch'],
]);
jf_rows('unreferenced duplicates are accepted at depth', $C, [
    ['nested object repeats keys named like fields', '{"i":1,"s":"a","w":"x","o":{"i":"x","i":2,"s":1,"s":2,"w":[1],"w":[2]}}', 'match'],
    ['nested objects two levels', '{"i":1,"s":null,"o":{"a":1,"a":2,"b":{"c":1,"c":2}},"w":"x"}', 'match'],
    ['array of objects with duplicates', '{"i":1,"s":null,"l":[{"a":1,"a":2},{"i":1,"i":2}],"w":"x"}', 'match'],
    ['duplicates in a nested object, filter key only there', '{"i":1,"s":null,"o":{"w":"a","w":"b"}}', 'nomatch'],
    ['nested copy of i with the wrong type is not inspected', '{"i":1,"s":null,"o":{"i":"SECRET"},"w":"x"}', 'match'],
    ['50 levels with a duplicate at the bottom', '{"i":1,"s":null,"w":"x","d":' . str_repeat('{"a":', 50) . '{"k":1,"k":2}' . str_repeat('}', 50) . '}', 'match'],
    ['300 levels of arrays around duplicate objects', '{"i":1,"s":null,"w":"x","d":' . str_repeat('[', 300) . '{"k":1,"k":2}' . str_repeat(']', 300) . '}', 'match'],
]);

echo "-- runs of several records\n";
$fields = ['fields' => ['i' => 'int64', 's' => 'string?']];
$ok = '{"i":1,"s":null,"z":1,"z":2}';
foreach ([
    ['duplicate only in record 2', "$ok\n" . '{"i":1,"s":null,"i":2}' . "\n$ok\n", 'line=2 record=2 offset=' . (strlen($ok) + 1)],
    ['duplicate only in record 3, CRLF', "$ok\r\n$ok\r\n" . '{"s":"a","i":1,"\u0073":"b"}' . "\r\n", 'line=3 record=3 offset=' . (2 * strlen($ok) + 4)],
    ['duplicate on the last line without LF', "$ok\n" . '{"i":1,"s":null,"i":1}', 'line=2 record=2 offset=' . (strlen($ok) + 1)],
] as [$name, $data, $ctx]) {
    echo str_pad($name, 66), jf_expect_error($data, RecordException::class, $ctx, ['SECRET'], $fields), "\n";
}
echo str_pad('the same key on different records is no duplicate', 66),
    jf_run_str("{\"i\":1}\n{\"i\":2}\n{\"i\":3,\"z\":1,\"z\":2}\n", ['fields' => ['i' => 'int64']]), "\n";
?>
--EXPECT--
-- duplicates of a fields key
control: no duplicate                                             ok
i twice, different values                                         ok
i twice, same value                                               ok
i twice, non-adjacent                                             ok
i twice, first copy invalid (last-wins would accept)              ok
i twice, last copy invalid (first-wins would accept)              ok
i twice, second copy null                                         ok
i three times                                                     ok
i twice with SECRET values                                        ok
key differing by case is not a duplicate                          ok
key with a trailing space is not a duplicate                      ok
key with a leading space is not a duplicate                       ok
two copies of an unreferenced key                                 ok
-- duplicates of a nullable fields key
control: both present                                             ok
s twice, different values                                         ok
s twice, null then string                                         ok
s twice, same value                                               ok
i twice, s between                                                ok
i twice, before s                                                 ok
-- duplicates through escapes (compared as bytes after decoding)
literal then \u0069                                               ok
\u0069 then literal                                               ok
\u0069 twice                                                      ok
\u0069 once is just i                                             ok
\u0069 then \u0049 (I) is no duplicate                            ok
\u0069 twice, then a literal copy                                 ok
i escaped, then its duplicate after other keys                    ok
-- duplicates through escapes of a nullable key
s versus \u0073                                                   ok
both copies of s escaped                                          ok
s escaped once                                                    ok
-- duplicates of a non-ASCII field name
literal then escaped                                              ok
escaped then literal                                              ok
both escaped, hex digit case differs                              ok
both literal                                                      ok
escaped once                                                      ok
literal once                                                      ok
decomposed form is another key, unreferenced                      ok
upper-case letter is another key                                  ok
-- duplicates of a 4-byte field name (surrogate pairs)
literal then pair                                                 ok
pair then literal                                                 ok
pair twice, hex digit case differs                                ok
pair once                                                         ok
a different emoji is another key                                  ok
literal and pair with a key between                               ok
-- duplicates of a field name with NUL
\u0000 twice                                                      ok
\u0000 once                                                       ok
a different key past the NUL is unreferenced                      ok
the part before the NUL is another key                            ok
-- duplicates of field names with solidus and quote
a/b literal and escaped solidus                                   ok
q"x with two spellings of the quote                               ok
one spelling each                                                 ok
-- duplicates of special field names
control: each once                                                ok
empty key twice                                                   ok
123 twice                                                         ok
123 spelled with escapes                                          ok
0123 is another key                                               ok
__proto__ twice                                                   ok
__proto__ with an escaped underscore                              ok
-- duplicates of a where key
control: w once, matching                                         ok
w twice, different values                                         ok
w twice, same value                                               ok
w twice, first mismatches, last matches (last-wins would match)   ok
w twice, first matches, last mismatches (first-wins would match)  ok
w twice, neither matches                                          ok
w twice, one copy an array                                        ok
w twice through an escape                                         ok
w twice, both escaped                                             ok
w twice, SECRET values                                            ok
W is another key                                                  ok
W alone does not satisfy the filter                               ok
w absent: nothing to duplicate                                    ok
duplicates of i still count                                       ok
-- duplicate of a key in both fields and where
a once                                                            ok
a twice, both 5                                                   ok
a twice, 5 then 6                                                 ok
-- duplicates among several where keys
no duplicate                                                      ok
w3 twice, spread out                                              ok
w4 twice, adjacent, record ends there                             ok
only unreferenced keys repeat                                     ok
-- a duplicate after every referenced key was already seen
control: 300 keys and no duplicate                                ok
control: referenced keys come last                                ok
control: only unreferenced keys repeat afterwards                 ok
all three seen, then i again                                      ok
all three seen, then w again at the very end                      ok
all three seen, then s again after 300 keys                       ok
all three seen, then w again after 300 keys                       ok
i again after 5000 keys                                           ok
i again as an escape, after other keys                            ok
i again after a big nested array                                  ok
i again after a nested object holding a copy of i                 ok
-- unreferenced duplicates are accepted at the top level
z twice                                                           ok
z with different types                                            ok
z a thousand times                                                ok
z and its escape                                                  ok
both copies escaped                                               ok
non-adjacent                                                      ok
empty key twice                                                   ok
I twice next to i                                                 ok
literal and escaped non-ASCII key                                 ok
duplicate keys holding RAW numbers                                ok
duplicates next to a filter that does not match                   ok
-- unreferenced duplicates are accepted at depth
nested object repeats keys named like fields                      ok
nested objects two levels                                         ok
array of objects with duplicates                                  ok
duplicates in a nested object, filter key only there              ok
nested copy of i with the wrong type is not inspected             ok
50 levels with a duplicate at the bottom                          ok
300 levels of arrays around duplicate objects                     ok
-- runs of several records
duplicate only in record 2                                        ok
duplicate only in record 3, CRLF                                  ok
duplicate on the last line without LF                             ok
the same key on different records is no duplicate                 read=3 matched=3 emitted=3 bytesRead=36 bytesWritten=24
