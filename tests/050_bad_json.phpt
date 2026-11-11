--TEST--
jsonfuse: invalid JSON, invalid UTF-8 and non-object roots raise RecordException with line context and no record bytes
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\RecordException;

const M = 'SECRETMARK';
// Every bad record is line 2: a valid first line makes the context non-trivial. Every
// accepted record must carry the default plan's int64 field `id`, so FIRST and the valid controls do.
const FIRST = "{\"id\":1}\n";
const CTX = 'line=2 record=2 offset=9';

function bad(string $name, string $record, string $terminator = "\n"): void
{
    $r = jf_expect_error(FIRST . $record . $terminator, RecordException::class, CTX,
        [M, 'SECRET', strlen($record) >= 8 ? $record : '', "\xC0", "\xE2\x82", "\xFF", "\x80", "\xED", "\xF4", "\xF0", "\xF5"]);
    echo str_pad($name, 44), $r, "\n";
}

function good(string $name, string $record, string $terminator = "\n"): void
{
    // The valid control gets the required `id` as its first member (unchanged text otherwise).
    $record = preg_replace('/\{/', '{"id":1,', $record, 1);
    $r = jf_run_str(FIRST . $record . $terminator);
    echo str_pad($name, 44), $r, "\n";
}

echo "-- syntax\n";
bad('trailing comma in object', '{"a":"' . M . '",}');
bad('trailing comma in array', '{"a":["' . M . '",]}');
bad('leading comma in array', '{"a":[,"' . M . '"]}');
bad('double comma', '{"a":"' . M . '",,"b":1}');
bad('missing comma', '{"a":"' . M . '" "b":1}');
bad('missing colon', '{"a" "' . M . '"}');
bad('missing value', '{"a":,"m":"' . M . '"}');
bad('unquoted key', '{a:"' . M . '"}');
bad('block comment', '{"a":"' . M . '"/*c*/}');
bad('line comment', '{"a":"' . M . '"}//c');
bad('comment before value', '{"a":/*c*/"' . M . '"}');
bad('NaN', '{"a":NaN,"m":"' . M . '"}');
bad('Infinity', '{"a":Infinity,"m":"' . M . '"}');
bad('-Infinity', '{"a":-Infinity,"m":"' . M . '"}');
bad('nan lowercase', '{"a":nan,"m":"' . M . '"}');
bad("single-quoted key and value 'x'", "{'x':'" . M . "'}");
bad('single-quoted value', "{\"a\":'" . M . "'}");
bad('leading plus', '{"a":+1,"m":"' . M . '"}');
bad('leading zero 01', '{"a":01,"m":"' . M . '"}');
bad('leading zero 00', '{"a":00,"m":"' . M . '"}');
bad('leading zero -01', '{"a":-01,"m":"' . M . '"}');
bad('leading zero 0123', '{"a":0123,"m":"' . M . '"}');
bad('leading zero 00.5', '{"a":00.5,"m":"' . M . '"}');
bad('hex number', '{"a":0x10,"m":"' . M . '"}');
bad('no digits after point', '{"a":1.,"m":"' . M . '"}');
bad('no digits before point', '{"a":.5,"m":"' . M . '"}');
bad('empty exponent', '{"a":1e,"m":"' . M . '"}');
bad('exponent sign only', '{"a":1e+,"m":"' . M . '"}');
bad('lone minus', '{"a":-,"m":"' . M . '"}');
bad('minus then space', '{"a":- 1,"m":"' . M . '"}');
bad('capitalised True', '{"a":True,"m":"' . M . '"}');
bad('undefined literal', '{"a":undefined,"m":"' . M . '"}');
bad('invalid escape \\x', '{"a":"' . M . '\\x41"}');
bad('invalid escape \\\'', '{"a":"' . M . "\\'" . '"}');
bad('short \\u escape', '{"a":"' . M . '\\u12"}');
bad('non-hex \\u escape', '{"a":"' . M . '\\u12G4"}');
bad('raw TAB in string', "{\"a\":\"" . M . "\t\"}");
bad('raw 0x1F in string', "{\"a\":\"" . M . "\x1F\"}");
bad('raw 0x01 in key', "{\"" . M . "\x01\":1}");
bad('unterminated string, LF', '{"a":"' . M);
bad('unterminated object, LF', '{"a":"' . M . '"');
bad('unterminated after key', '{"' . M . '"');
bad('unterminated array', '{"a":["' . M . '"');
bad('only open brace', '{');
bad('trailing garbage letter', '{"a":"' . M . '"}x');
bad('trailing second object', '{"a":"' . M . '"} {"b":1}');
bad('trailing closing brace', '{"a":"' . M . '"}}');
bad('trailing comma after root', '{"a":"' . M . '"},');
bad('trailing marker text', '{}' . M);
bad('trailing NUL-free digits', '{"a":1}2');

echo "-- lone surrogate escapes\n";
bad('lone high surrogate in value', '{"a":"' . M . '\\ud800"}');
bad('lone low surrogate in value', '{"a":"' . M . '\\udc00"}');
bad('high surrogate then letter', '{"a":"' . M . '\\ud800x"}');
bad('high surrogate then non-low escape', '{"a":"' . M . '\\ud800\\u0041"}');
bad('high then high', '{"a":"' . M . '\\ud800\\ud800"}');
bad('low then high', '{"a":"' . M . '\\udc00\\ud800"}');
bad('highest high surrogate alone', '{"a":"' . M . '\\udbff"}');
bad('highest low surrogate alone', '{"a":"' . M . '\\udfff"}');
bad('lone surrogate in referenced-style key', '{"\\ud800":"' . M . '"}');
bad('lone surrogate in unreferenced key', '{"x":1,"\\udc00":"' . M . '"}');
bad('lone surrogate uppercase hex', '{"a":"' . M . '\\uD800"}');
bad('lone surrogate in nested value', '{"a":{"b":["' . M . '\\ud800"]}}');

echo "-- invalid UTF-8 in values\n";
bad('overlong C0 80', "{\"a\":\"" . M . "\xC0\x80\"}");
bad('overlong C1 BF', "{\"a\":\"" . M . "\xC1\xBF\"}");
bad('overlong E0 80 80', "{\"a\":\"" . M . "\xE0\x80\x80\"}");
bad('overlong E0 9F BF', "{\"a\":\"" . M . "\xE0\x9F\xBF\"}");
bad('overlong F0 80 80 80', "{\"a\":\"" . M . "\xF0\x80\x80\x80\"}");
bad('overlong F0 8F BF BF', "{\"a\":\"" . M . "\xF0\x8F\xBF\xBF\"}");
bad('surrogate bytes ED A0 80', "{\"a\":\"" . M . "\xED\xA0\x80\"}");
bad('surrogate bytes ED AF BF', "{\"a\":\"" . M . "\xED\xAF\xBF\"}");
bad('surrogate bytes ED BF BF', "{\"a\":\"" . M . "\xED\xBF\xBF\"}");
bad('surrogate pair as bytes', "{\"a\":\"" . M . "\xED\xA0\xBD\xED\xB8\x80\"}");
bad('above U+10FFFF F4 90 80 80', "{\"a\":\"" . M . "\xF4\x90\x80\x80\"}");
bad('above U+10FFFF F5 80 80 80', "{\"a\":\"" . M . "\xF5\x80\x80\x80\"}");
bad('5-byte lead F8', "{\"a\":\"" . M . "\xF8\x88\x80\x80\x80\"}");
bad('6-byte lead FC', "{\"a\":\"" . M . "\xFC\x84\x80\x80\x80\x80\"}");
bad('lead byte FF', "{\"a\":\"" . M . "\xFF\"}");
bad('lead byte FE', "{\"a\":\"" . M . "\xFE\"}");
bad('lone continuation 80', "{\"a\":\"" . M . "\x80\"}");
bad('lone continuation BF', "{\"a\":\"" . M . "\xBF\"}");
bad('truncated 2-byte before quote', "{\"a\":\"" . M . "\xC3\"}");
bad('truncated 3-byte E2 82', "{\"a\":\"" . M . "\xE2\x82\"}");
bad('truncated 3-byte E2', "{\"a\":\"" . M . "\xE2\"}");
bad('truncated 4-byte F0 9F 98', "{\"a\":\"" . M . "\xF0\x9F\x98\"}");
bad('truncated 4-byte F0 9F', "{\"a\":\"" . M . "\xF0\x9F\"}");
bad('lead then ASCII', "{\"a\":\"" . M . "\xC3(\"}");
bad('3-byte with bad 2nd byte', "{\"a\":\"" . M . "\xE2(\xA1\"}");
bad('3-byte with bad 3rd byte', "{\"a\":\"" . M . "\xE2\x82(\"}");
bad('4-byte with bad 4th byte', "{\"a\":\"" . M . "\xF0\x9F\x98(\"}");
bad('invalid byte right after opening quote', "{\"a\":\"\xC0\x80" . M . "\"}");
bad('invalid byte in nested array value', "{\"a\":[\"" . M . "\xC0\x80\"]}");
bad('invalid byte in nested object value', "{\"a\":{\"b\":\"" . M . "\xFF\"}}");
bad('invalid byte, last value of record', "{\"a\":1,\"z\":\"" . M . "\xE2\x82\"}");
bad('truncated sequence at end of record text', "{\"a\":\"" . M . "\xE2\x82", '');
bad('truncated sequence then LF', "{\"a\":\"" . M . "\xE2\x82");

echo "-- invalid UTF-8 in keys\n";
bad('unreferenced key overlong', "{\"x\":1,\"" . M . "\xC0\x80\":2}");
bad('unreferenced key only', "{\"\xC0\x80\":\"" . M . "\"}");
bad('unreferenced key surrogate bytes', "{\"\xED\xA0\x80\":\"" . M . "\"}");
bad('unreferenced key above U+10FFFF', "{\"\xF4\x90\x80\x80\":\"" . M . "\"}");
bad('unreferenced key truncated', "{\"" . M . "\xE2\x82\":1}");
bad('unreferenced key lone continuation', "{\"\x80\":\"" . M . "\"}");
bad('nested key invalid', "{\"a\":{\"\xC3(\":\"" . M . "\"}}");
bad('key in array of objects invalid', "{\"a\":[{\"\xFF\":\"" . M . "\"}]}");

echo "-- truncated record at EOF (no LF)\n";
bad('EOF inside string', '{"a":"' . M, '');
bad('EOF inside object', '{"a":"' . M . '"', '');
bad('EOF after comma', '{"a":"' . M . '",', '');
bad('EOF after colon', '{"' . M . '":', '');
bad('EOF inside escape', '{"a":"' . M . '\\', '');
bad('EOF inside \\u escape', '{"a":"' . M . '\\u00', '');
bad('EOF inside number exponent', '{"' . M . '":1e', '');
bad('EOF inside literal', '{"' . M . '":tru', '');
bad('EOF after lone brace', '{', '');
bad('EOF record is a single quote', '"', '');

echo "-- root is not an object\n";
bad('root array', '[{"a":"' . M . '"}]');
bad('root empty array', '[]');
bad('root array of numbers', '[1,2,3]');
bad('root number', '123');
bad('root negative number', '-12');
bad('root float', '1.5e3');
bad('root string', '"' . M . '"');
bad('root null', 'null');
bad('root true', 'true');
bad('root false', 'false');
bad('root array, CRLF', '[1]', "\r\n");
bad('root number, no LF', '5', '');
bad('root null, no LF', 'null', '');
bad('root string with leading whitespace', ' "' . M . '"');
bad('root array with trailing object', '[] {"a":"' . M . '"}');

echo "-- CRLF framing does not change the line context\n";
bad('trailing comma, CRLF', '{"a":"' . M . '",}', "\r\n");
bad('invalid UTF-8, CRLF', "{\"a\":\"" . M . "\xC0\x80\"}", "\r\n");

echo "-- controls: the same shapes, valid\n";
good('valid escapes', '{"a":"' . M . '\\"\\\\\\/\\b\\f\\n\\r\\t\\u00e9\\u0000"}');
good('valid surrogate pair', '{"a":"' . M . '\\ud83d\\ude00\\uD83D\\uDE00"}');
good('valid surrogate pair in key', '{"\\ud83d\\ude00":"' . M . '"}');
good('valid U+0080 C2 80', "{\"a\":\"\xC2\x80\"}");
good('valid U+07FF DF BF', "{\"a\":\"\xDF\xBF\"}");
good('valid U+0800 E0 A0 80', "{\"a\":\"\xE0\xA0\x80\"}");
good('valid U+D7FF ED 9F BF', "{\"a\":\"\xED\x9F\xBF\"}");
good('valid U+E000 EE 80 80', "{\"a\":\"\xEE\x80\x80\"}");
good('valid U+FFFF EF BF BF', "{\"a\":\"\xEF\xBF\xBF\"}");
good('valid U+10000 F0 90 80 80', "{\"a\":\"\xF0\x90\x80\x80\"}");
good('valid U+10FFFF F4 8F BF BF', "{\"a\":\"\xF4\x8F\xBF\xBF\"}");
good('valid UTF-8 key', "{\"caf\xC3\xA9\":\"" . M . "\"}");
good('valid DEL and slash raw', "{\"a\":\"\x7F/\"}");
good('valid numbers', '{"a":[-0,0,-0.0,1E+2,0.5e-3,10,1e0,123456789012345678901234567890]}');
good('valid literals', '{"a":[true,false,null],"b":{}}');
good('valid empty key', '{"":1}');
good('valid whitespace around tokens', " \t{ \"a\" \t: [ 1 , 2 ] }\t ");
good('valid record without LF at EOF', '{"a":"' . M . '"}', '');
?>
--EXPECT--
-- syntax
trailing comma in object                    ok
trailing comma in array                     ok
leading comma in array                      ok
double comma                                ok
missing comma                               ok
missing colon                               ok
missing value                               ok
unquoted key                                ok
block comment                               ok
line comment                                ok
comment before value                        ok
NaN                                         ok
Infinity                                    ok
-Infinity                                   ok
nan lowercase                               ok
single-quoted key and value 'x'             ok
single-quoted value                         ok
leading plus                                ok
leading zero 01                             ok
leading zero 00                             ok
leading zero -01                            ok
leading zero 0123                           ok
leading zero 00.5                           ok
hex number                                  ok
no digits after point                       ok
no digits before point                      ok
empty exponent                              ok
exponent sign only                          ok
lone minus                                  ok
minus then space                            ok
capitalised True                            ok
undefined literal                           ok
invalid escape \x                           ok
invalid escape \'                           ok
short \u escape                             ok
non-hex \u escape                           ok
raw TAB in string                           ok
raw 0x1F in string                          ok
raw 0x01 in key                             ok
unterminated string, LF                     ok
unterminated object, LF                     ok
unterminated after key                      ok
unterminated array                          ok
only open brace                             ok
trailing garbage letter                     ok
trailing second object                      ok
trailing closing brace                      ok
trailing comma after root                   ok
trailing marker text                        ok
trailing NUL-free digits                    ok
-- lone surrogate escapes
lone high surrogate in value                ok
lone low surrogate in value                 ok
high surrogate then letter                  ok
high surrogate then non-low escape          ok
high then high                              ok
low then high                               ok
highest high surrogate alone                ok
highest low surrogate alone                 ok
lone surrogate in referenced-style key      ok
lone surrogate in unreferenced key          ok
lone surrogate uppercase hex                ok
lone surrogate in nested value              ok
-- invalid UTF-8 in values
overlong C0 80                              ok
overlong C1 BF                              ok
overlong E0 80 80                           ok
overlong E0 9F BF                           ok
overlong F0 80 80 80                        ok
overlong F0 8F BF BF                        ok
surrogate bytes ED A0 80                    ok
surrogate bytes ED AF BF                    ok
surrogate bytes ED BF BF                    ok
surrogate pair as bytes                     ok
above U+10FFFF F4 90 80 80                  ok
above U+10FFFF F5 80 80 80                  ok
5-byte lead F8                              ok
6-byte lead FC                              ok
lead byte FF                                ok
lead byte FE                                ok
lone continuation 80                        ok
lone continuation BF                        ok
truncated 2-byte before quote               ok
truncated 3-byte E2 82                      ok
truncated 3-byte E2                         ok
truncated 4-byte F0 9F 98                   ok
truncated 4-byte F0 9F                      ok
lead then ASCII                             ok
3-byte with bad 2nd byte                    ok
3-byte with bad 3rd byte                    ok
4-byte with bad 4th byte                    ok
invalid byte right after opening quote      ok
invalid byte in nested array value          ok
invalid byte in nested object value         ok
invalid byte, last value of record          ok
truncated sequence at end of record text    ok
truncated sequence then LF                  ok
-- invalid UTF-8 in keys
unreferenced key overlong                   ok
unreferenced key only                       ok
unreferenced key surrogate bytes            ok
unreferenced key above U+10FFFF             ok
unreferenced key truncated                  ok
unreferenced key lone continuation          ok
nested key invalid                          ok
key in array of objects invalid             ok
-- truncated record at EOF (no LF)
EOF inside string                           ok
EOF inside object                           ok
EOF after comma                             ok
EOF after colon                             ok
EOF inside escape                           ok
EOF inside \u escape                        ok
EOF inside number exponent                  ok
EOF inside literal                          ok
EOF after lone brace                        ok
EOF record is a single quote                ok
-- root is not an object
root array                                  ok
root empty array                            ok
root array of numbers                       ok
root number                                 ok
root negative number                        ok
root float                                  ok
root string                                 ok
root null                                   ok
root true                                   ok
root false                                  ok
root array, CRLF                            ok
root number, no LF                          ok
root null, no LF                            ok
root string with leading whitespace         ok
root array with trailing object             ok
-- CRLF framing does not change the line context
trailing comma, CRLF                        ok
invalid UTF-8, CRLF                         ok
-- controls: the same shapes, valid
valid escapes                               read=2 matched=2 emitted=2 bytesRead=63 bytesWritten=18
valid surrogate pair                        read=2 matched=2 emitted=2 bytesRead=59 bytesWritten=18
valid surrogate pair in key                 read=2 matched=2 emitted=2 bytesRead=46 bytesWritten=18
valid U+0080 C2 80                          read=2 matched=2 emitted=2 bytesRead=27 bytesWritten=18
valid U+07FF DF BF                          read=2 matched=2 emitted=2 bytesRead=27 bytesWritten=18
valid U+0800 E0 A0 80                       read=2 matched=2 emitted=2 bytesRead=28 bytesWritten=18
valid U+D7FF ED 9F BF                       read=2 matched=2 emitted=2 bytesRead=28 bytesWritten=18
valid U+E000 EE 80 80                       read=2 matched=2 emitted=2 bytesRead=28 bytesWritten=18
valid U+FFFF EF BF BF                       read=2 matched=2 emitted=2 bytesRead=28 bytesWritten=18
valid U+10000 F0 90 80 80                   read=2 matched=2 emitted=2 bytesRead=29 bytesWritten=18
valid U+10FFFF F4 8F BF BF                  read=2 matched=2 emitted=2 bytesRead=29 bytesWritten=18
valid UTF-8 key                             read=2 matched=2 emitted=2 bytesRead=39 bytesWritten=18
valid DEL and slash raw                     read=2 matched=2 emitted=2 bytesRead=27 bytesWritten=18
valid numbers                               read=2 matched=2 emitted=2 bytesRead=84 bytesWritten=18
valid literals                              read=2 matched=2 emitted=2 bytesRead=47 bytesWritten=18
valid empty key                             read=2 matched=2 emitted=2 bytesRead=23 bytesWritten=18
valid whitespace around tokens              read=2 matched=2 emitted=2 bytesRead=41 bytesWritten=18
valid record without LF at EOF              read=2 matched=2 emitted=2 bytesRead=34 bytesWritten=18
