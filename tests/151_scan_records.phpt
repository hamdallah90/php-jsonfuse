--TEST--
jsonfuse 1.0: records of a flat plan give the same output, Stats, exception, reason, getters, progress and message whichever parser path reads them: escapes, UTF-8, control bytes, numbers, RAW values, nesting, mismatched brackets, duplicates, whitespace, syntax errors
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * Output, Stats or the exception chain, and the message, which both parser paths must give alike.
 * $scan is how many of the records the validating scan must accept; a build with -DJF_SCAN_STATS
 * checks it and prints a line on a mismatch (other builds have no counter and skip the check).
 */
function show(string $label, string $input, array $spec = [], ?int $scan = null): void
{
    $spec += ['fields' => ['s' => 'string', 'n' => 'int64']];
    $before = jf10_scan_accepted();
    $in = jf_mem($input);
    $out = jf_out();
    try {
        $r = jf_stats(Runner::process($in, $out, Plan::compile($spec)));
    } catch (Throwable $e) {
        $r = jf10_chain($e) . ' | ' . $e->getMessage();
    }
    jf10_print($label, jf_out_bytes($out), $r);
    fclose($in);
    fclose($out);
    if ($before !== null && $scan !== null && ($got = jf10_scan_accepted() - $before) !== $scan) {
        echo "SCAN COUNT $label: accepted $got, expected $scan\n";
    }
}

$f = ['fields' => ['s' => 'string', 'n' => 'int64', 'f' => 'float64']];
$deep = fn (int $d) => '{"s":"x","n":1,"z":' . str_repeat('[', $d) . str_repeat(']', $d) . '}';
$deepObj = fn (int $d) => '{"s":"x","n":1,"z":' . str_repeat('{"k":', $d) . '0' . str_repeat('}', $d) . '}';

show('plain', '{"s":"x","n":1}', [], 1);
show('escapes in a referenced string', '{"s":"a\\n\\t\\"\\\\\\/\\b\\f\\r\\u00e9\\u00E9\\ud83d\\ude00\\uD83D\\uDE00z","n":2}', [], 1);
show('escaped NUL', '{"s":"a\\u0000b","n":3}', [], 1);
show('escapes across 8-byte words', '{"s":"0123456\\"89abcdef\\\\0123456789\\u0041BCDEFGHIJKLMNOP\\/","n":4}', [], 1);
show('escaped where operand', "{\"s\":\"\\u00e9\",\"n\":5}\n{\"s\":\"e\",\"n\":6}\n{\"s\":\"a\\u0000b\",\"n\":7}",
    ['where' => ['s' => ['in' => ["\u{e9}", "a\0b"]]]], 3);
show('escaped string range', "{\"s\":\"b\\u0062\",\"n\":8}\n{\"s\":\"\\u0063\",\"n\":9}", ['where' => ['s' => ['>=' => 'bb', '<' => 'c']]]);
show('escaped referenced string before a syntax error', '{"s":"\\u0022\\"x","n":1,"z":tru}', [], 0);
show('escaped where value before trailing bytes', '{"s":"x","n":2,"w":"\\"\\u0041"}x', ['where' => ['w' => '"A']], 0);
show('escaped referenced key', '{"\\u0073":"x","n":10}');
show('escaped duplicate of a referenced key', '{"s":"x","\\u0073":"y","n":11}');
show('duplicate referenced key', '{"s":"x","n":12,"s":"y"}');
show('duplicate referenced key, where', '{"s":"x","n":13,"w":1,"w":2}', ['where' => ['w' => 1]]);
show('duplicate unreferenced key', '{"s":"x","n":14,"z":1,"z":2}');
show('escaped unreferenced key', '{"s":"x","n":15,"z\\"":1,"\\u007a":2}');
show('empty key', '{"":1,"s":"x","n":16}');
show('lone high surrogate', '{"s":"x","n":17,"z":"\\ud800"}');
show('lone low surrogate', '{"s":"x","n":18,"z":"\\udc00x"}');
show('high surrogate, bad low', '{"s":"x","n":19,"z":"\\ud800\\u0041"}');
show('high surrogate, then a byte other than a backslash before u', '{"s":"x","n":19,"z":"\\ud800Xudc00"}', [], 0);
show('high surrogate, then an escape other than u', '{"s":"x","n":19,"z":"\\ud800\\tdc00"}', [], 0);
show('bad escape', '{"s":"x","n":20,"z":"\\x"}', [], 0);
show('short unicode escape', '{"s":"x","n":21,"z":"\\u12"}');
show('bad hex digit', '{"s":"\\u00g0","n":22}');
show('valid UTF-8 in a referenced string', "{\"s\":\"\u{e9}\u{20ac}\u{1f600}\u{10ffff}\",\"n\":23}", [], 1);
show('invalid byte 0xFF', "{\"s\":\"x\",\"n\":24,\"z\":\"\xff\"}", [], 0);
show('overlong C0 80', "{\"s\":\"x\",\"n\":25,\"z\":\"\xc0\x80\"}");
show('overlong E0 80 80', "{\"s\":\"x\",\"n\":26,\"z\":\"\xe0\x80\x80\"}");
show('UTF-8 surrogate ED A0 80', "{\"s\":\"x\",\"n\":27,\"z\":\"\xed\xa0\x80\"}");
show('above U+10FFFF', "{\"s\":\"x\",\"n\":28,\"z\":\"\xf4\x90\x80\x80\"}");
show('truncated sequence', "{\"s\":\"x\",\"n\":29,\"z\":\"\xe2\x82\"}");
show('lone continuation byte', "{\"s\":\"\x80\",\"n\":30}", [], 0);
show('bad UTF-8 in a key', "{\"s\":\"x\",\"n\":31,\"\xff\":1}");
show('escape length boundaries', '{"s":"\u007f\u0080\u07ff\u0800\uffff\ud800\udc00\udbff\udfff","n":40}');
show('two low surrogates', '{"s":"x","n":41,"z":"\udc00\udc00"}');
show('high then high surrogate', '{"s":"x","n":42,"z":"\ud800\ud800"}');
show('literal true with a wrong 4th byte', '{"s":"x","n":43,"t":truE}');
show('literal null with a wrong 4th byte', '{"s":"x","n":44,"t":nulL}');
show('literal false with a wrong 5th byte', '{"s":"x","n":45,"t":falsE}');
foreach (['ff' => "\xff", 'c0 80' => "\xc0\x80", 'c3 41' => "\xc3\x41", 'e2 82 41' => "\xe2\x82\x41", 'f0 80 80 80' => "\xf0\x80\x80\x80",
    'f0 8f bf bf' => "\xf0\x8f\xbf\xbf", 'f5 80 80 80' => "\xf5\x80\x80\x80", 'f8 80 80 80' => "\xf8\x80\x80\x80", 'f0 9f 41 80' => "\xf0\x9f\x41\x80",
    'f0 9f 98 41' => "\xf0\x9f\x98\x41", 'f0 90 80 80' => "\xf0\x90\x80\x80", 'f4 8f bf bf' => "\xf4\x8f\xbf\xbf", 'ed 9f bf' => "\xed\x9f\xbf"] as $label => $bytes) {
    $valid = (int)in_array($label, ['f0 90 80 80', 'f4 8f bf bf', 'ed 9f bf'], true);
    show("bytes $label inside a long string", "{\"z\":\"0$bytes" . "abcdefghijklmnop\",\"s\":\"x\",\"n\":50}", [], $valid);
    show("bytes $label inside a long referenced string", "{\"s\":\"0$bytes" . "abcdefghijklmnop\",\"n\":51}", [], $valid);
}
show('raw control byte', "{\"s\":\"a\x01b\",\"n\":32}", [], 0);
show('raw DEL', "{\"s\":\"a\x7fb\",\"n\":33}");
/* Every C0 byte raw inside a string, in an 8-byte word the scan checks at once (0x0A ends the line there), and space as the neighbour it accepts. */
foreach (range(0, 0x20) as $b) {
    $c = chr($b);
    show(sprintf('raw byte 0x%02X inside a long string', $b), "{\"z\":\"0{$c}abcdefghijklmnop\",\"s\":\"x\",\"n\":52}", [], (int)($b === 0x20));
    show(sprintf('raw byte 0x%02X inside a long referenced string', $b), "{\"s\":\"0{$c}abcdefghijklmnop\",\"n\":53}", [], (int)($b === 0x20));
}
/* Bytes that are not JSON whitespace, where whitespace may stand: before the root, before a colon, inside an array, after the root. */
foreach (["\x0c" => 'form feed', "\x0b" => 'vertical tab', "\xa0" => 'byte 0xA0'] as $c => $name) {
    show("$name before the root", "$c{\"s\":\"x\",\"n\":54}", [], 0);
    show("$name before a colon", "{\"s\"$c:\"x\",\"n\":55}", [], 0);
    show("$name inside an ignored array", "{\"s\":\"x\",\"n\":56,\"z\":[1$c]}", [], 0);
    show("$name after the root", "{\"s\":\"x\",\"n\":57}$c", [], 0);
}
show('numbers', "{\"s\":\"x\",\"n\":-0,\"f\":1E+2}\n{\"s\":\"x\",\"n\":0,\"f\":0.5e-3}\n{\"s\":\"x\",\"n\":-9223372036854775808,\"f\":-1.5E-300}\n{\"s\":\"x\",\"n\":9223372036854775807,\"f\":5e-324}\n{\"s\":\"x\",\"n\":1,\"f\":-0.0}\n{\"s\":\"x\",\"n\":1,\"f\":18446744073709551616}", $f, 5);
show('float64 from RAW 1e400', '{"s":"x","n":1,"f":1e400}', $f);
show('int64 from UINT above INT64_MAX', '{"s":"x","n":9223372036854775808}');
show('int64 from RAW 2^64', '{"s":"x","n":18446744073709551616}', [], 0);
show('int64 from RAW below INT64_MIN', '{"s":"x","n":-9223372036854775809}');
show('int64 from a real', '{"s":"x","n":1.0}');
foreach (['01', '1.', '.5', '-', '+1', '1e', '1e+', '0x1', 'NaN', 'Infinity', '-Infinity', '1.5e', '--1', '-01', '1E400', '-1e400', '123456789012345678901234567890', '1.e5', '1.a', '0.a1', '1ea', '1e+a', '1E-x'] as $lit) {
    show("unreferenced number $lit", "{\"s\":\"x\",\"n\":1,\"z\":$lit}");
}
show('number range on scanned values', "{\"s\":\"x\",\"n\":15}\n{\"s\":\"x\",\"n\":20}\n{\"s\":\"x\",\"n\":9}\n{\"s\":\"x\",\"n\":10,\"w\":1.5e1}\n{\"s\":\"x\",\"n\":11,\"w\":18446744073709551616}",
    ['where' => ['n' => ['>=' => 10, '<' => 20]]]);
show('in on a RAW where value', '{"s":"x","n":1,"w":18446744073709551616}', ['where' => ['w' => ['in' => [1, 1.8446744073709552e19]]]]);
show('RAW numbers in referenced and ignored members',
    "{\"s\":\"x\",\"n\":1,\"w\":-1e400,\"z\":123456789012345678901234567890}\n{\"s\":\"x\",\"n\":2,\"w\":1e400,\"z\":[1e400,-18446744073709551617]}\n{\"s\":\"x\",\"n\":3,\"w\":5,\"z\":1e400}\n{\"s\":\"x\",\"n\":4,\"w\":-18446744073709551617}",
    ['where' => ['w' => ['>=' => 0]]], 1);
show('referenced object field', '{"s":{"a":1},"n":1}', [], 1);
show('referenced array field', '{"s":[1,[2]],"n":1}');
show('where on an object', '{"s":"x","n":1,"w":{"a":[]}}', ['where' => ['w' => 1]]);
show('where on an array', '{"s":"x","n":1,"w":[]}', ['where' => ['w' => 1]]);
show('nesting 64 below the root', $deep(63), [], 1);
show('nesting 65 below the root', $deep(64));
show('nesting 66 below the root', $deep(65), [], 0);
show('objects 65 deep', $deepObj(65));
show('nesting at the yyjson limit', $deep(509));
show('nesting past the yyjson limit', $deep(510));
show('unclosed nesting', '{"s":"x","n":1,"z":[[[{"a":[}');
show('mismatched brackets', '{"s":"x","n":1,"z":[}');
show('empty array closed by a brace', '{"s":"x","n":1,"z":[}}');
show('empty object closed by a bracket', '{"s":"x","n":1,"z":{]}');
show('array closed by a brace after a value', '{"u":[1},"s":"x","n":1}', [], 0);
show('object closed by a bracket after a value', '{"u":{"a":1],"s":"x","n":1}', [], 0);
show('inner array closed by a brace', '{"u":[[1]},"s":"x","n":1}', [], 0);
show('array in an object closed by a brace', '{"u":{"k":[1}},"s":"x","n":1}', [], 0);
show('object in an array closed by a bracket', '{"u":[{"a":1]],"s":"x","n":1}', [], 0);
show('referenced array closed by a brace', '{"s":[1},"n":1}', [], 0);
show('object at depth 66, outer array closed by a brace', '{"s":"x","n":1,"z":' . str_repeat('[', 64) . '{}' . str_repeat(']', 63) . '}}');
show('whitespace', " \t{\t\"s\"\r:\"x\" , \"n\" :1\t}\t ", [], 1);
show('trailing garbage', '{"s":"x","n":1}x', [], 0);
show('second value', '{"s":"x","n":1} {}', [], 0);
show('trailing comma', '{"s":"x","n":1,}', [], 0);
show('missing colon', '{"s" "x","n":1}');
show('missing comma', '{"s":"x" "n":1}');
show('unquoted key', '{s:"x","n":1}');
show('single quotes', "{'s':\"x\",\"n\":1}");
show('unterminated string', '{"s":"x,"n":1}');
show('true false null', "{\"s\":\"x\",\"n\":1,\"t\":true,\"u\":false,\"v\":null}\n{\"s\":\"x\",\"n\":null}", [], 2);
show('bad literals', "{\"s\":\"x\",\"n\":1,\"t\":tru}");
show('bad literal nul', "{\"s\":\"x\",\"n\":1,\"t\":nul}");
show('bad literal falsy', "{\"s\":\"x\",\"n\":1,\"t\":falsy}");
show('where on bools and null', "{\"s\":\"x\",\"n\":1,\"w\":true}\n{\"s\":\"x\",\"n\":2,\"w\":false}\n{\"s\":\"x\",\"n\":3,\"w\":null}", ['where' => ['w' => ['in' => [true, null]]]], 3);
show('roots that are not objects', "[1]", [], 0);
show('string root', '"s"');
show('number root', '1');
show('empty object', '{}', [], 1);
show('key filter neighbours', "{\"accent\":1,\"amount\":5,\"s\":\"x\",\"n\":1}\n{\"accent\":2,\"s\":\"x\",\"n\":2}", ['fields' => ['amount' => 'int64']], 2);
show('last record without LF ends in a string', "{\"s\":\"x\",\"n\":1}\n{\"n\":2,\"s\":\"abcdefghijklmnop\\u0041\"}", [], 2);
show('nested plan uses yyjson only', '{"s":"x","n":1,"m":{"k":"\\u00e9"}}', ['fields' => ['s' => 'string', 'n' => 'int64', 'k' => ['type' => 'string', 'path' => ['m', 'k']]]], 0);
show('mixed valid and invalid records', "{\"s\":\"a\",\"n\":1}\n{\"s\":\"b\",\"n\":2}\n{\"s\":\"c\",\"n\":3,\"z\":\"\\ud800\"}", [], 2);

--EXPECT--
plain
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=15 bytesWritten=16
escapes in a referenced string
  > {"s":"a\n\t\"\\/\b\f\réé😀😀z","n":2}
  = read=1 matched=1 emitted=1 bytesRead=68 bytesWritten=44
escaped NUL
  > {"s":"a\u0000b","n":3}
  = read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=23
escapes across 8-byte words
  > {"s":"0123456\"89abcdef\\0123456789ABCDEFGHIJKLMNOP/","n":4}
  = read=1 matched=1 emitted=1 bytesRead=66 bytesWritten=61
escaped where operand
  > {"s":"é","n":5}
  > {"s":"a\u0000b","n":7}
  = read=3 matched=2 emitted=2 bytesRead=59 bytesWritten=40
escaped string range
  > {"s":"bb","n":8}
  = read=2 matched=1 emitted=1 bytesRead=42 bytesWritten=17
escaped referenced string before a syntax error
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 27: invalid literal, expected 'true'
escaped where value before trailing bytes
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 30: unexpected content after document
escaped referenced key
  > {"s":"x","n":10}
  = read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=17
escaped duplicate of a referenced key
  = RecordException duplicate_key field="s" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): duplicate key "s"
duplicate referenced key
  = RecordException duplicate_key field="s" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): duplicate key "s"
duplicate referenced key, where
  = RecordException duplicate_key field="w" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): duplicate key "w"
duplicate unreferenced key
  > {"s":"x","n":14}
  = read=1 matched=1 emitted=1 bytesRead=28 bytesWritten=17
escaped unreferenced key
  > {"s":"x","n":15}
  = read=1 matched=1 emitted=1 bytesRead=35 bytesWritten=17
empty key
  > {"s":"x","n":16}
  = read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=17
lone high surrogate
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no low surrogate in string
lone low surrogate
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid high surrogate in string
high surrogate, bad low
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid low surrogate in string
high surrogate, then a byte other than a backslash before u
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no low surrogate in string
high surrogate, then an escape other than u
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no low surrogate in string
bad escape
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid escaped sequence in string
short unicode escape
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid escaped sequence in string
bad hex digit
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 6: invalid escaped sequence in string
valid UTF-8 in a referenced string
  > {"s":"é€😀􏿿","n":23}
  = read=1 matched=1 emitted=1 bytesRead=28 bytesWritten=29
invalid byte 0xFF
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
overlong C0 80
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
overlong E0 80 80
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
UTF-8 surrogate ED A0 80
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
above U+10FFFF
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
truncated sequence
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid UTF-8 encoding in string
lone continuation byte
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 6: invalid UTF-8 encoding in string
bad UTF-8 in a key
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 17: invalid UTF-8 encoding in string
escape length boundaries
  > {"s":"߿ࠀ￿𐀀􏿿","n":40}
  = read=1 matched=1 emitted=1 bytesRead=69 bytesWritten=35
two low surrogates
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid high surrogate in string
high then high surrogate
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: invalid low surrogate in string
literal true with a wrong 4th byte
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: invalid literal, expected 'true'
literal null with a wrong 4th byte
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: invalid literal, expected 'null'
literal false with a wrong 5th byte
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: invalid literal, expected 'false'
bytes ff inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes ff inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes c0 80 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes c0 80 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes c3 41 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes c3 41 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes e2 82 41 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes e2 82 41 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 80 80 80 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 80 80 80 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 8f bf bf inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 8f bf bf inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f5 80 80 80 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f5 80 80 80 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f8 80 80 80 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f8 80 80 80 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 9f 41 80 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 9f 41 80 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 9f 98 41 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 9f 98 41 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: invalid UTF-8 encoding in string
bytes f0 90 80 80 inside a long string
  > {"s":"x","n":50}
  = read=1 matched=1 emitted=1 bytesRead=44 bytesWritten=17
bytes f0 90 80 80 inside a long referenced string
  > {"s":"0𐀀abcdefghijklmnop","n":51}
  = read=1 matched=1 emitted=1 bytesRead=36 bytesWritten=37
bytes f4 8f bf bf inside a long string
  > {"s":"x","n":50}
  = read=1 matched=1 emitted=1 bytesRead=44 bytesWritten=17
bytes f4 8f bf bf inside a long referenced string
  > {"s":"0􏿿abcdefghijklmnop","n":51}
  = read=1 matched=1 emitted=1 bytesRead=36 bytesWritten=37
bytes ed 9f bf inside a long string
  > {"s":"x","n":50}
  = read=1 matched=1 emitted=1 bytesRead=43 bytesWritten=17
bytes ed 9f bf inside a long referenced string
  > {"s":"0퟿abcdefghijklmnop","n":51}
  = read=1 matched=1 emitted=1 bytesRead=35 bytesWritten=36
raw control byte
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw DEL
  > {"s":"ab","n":33}
  = read=1 matched=1 emitted=1 bytesRead=18 bytesWritten=19
raw byte 0x00 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x00 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x01 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x01 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x02 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x02 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x03 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x03 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x04 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x04 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x05 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x05 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x06 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x06 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x07 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x07 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x08 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x08 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x09 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x09 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0A inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected end of data
raw byte 0x0A inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected end of data
raw byte 0x0B inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0B inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0C inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0C inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0D inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0D inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0E inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0E inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0F inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x0F inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x10 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x10 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x11 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x11 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x12 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x12 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x13 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x13 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x14 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x14 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x15 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x15 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x16 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x16 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x17 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x17 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x18 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x18 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x19 inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x19 inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1A inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1A inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1B inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1B inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1C inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1C inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1D inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1D inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1E inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1E inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1F inside a long string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x1F inside a long referenced string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected control character in string
raw byte 0x20 inside a long string
  > {"s":"x","n":52}
  = read=1 matched=1 emitted=1 bytesRead=41 bytesWritten=17
raw byte 0x20 inside a long referenced string
  > {"s":"0 abcdefghijklmnop","n":53}
  = read=1 matched=1 emitted=1 bytesRead=33 bytesWritten=34
form feed before the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 0: unexpected character, expected a JSON value
form feed before a colon
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 4: unexpected character, expected ':' after key
form feed inside an ignored array
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: unexpected character, expected ',' or ']'
form feed after the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 16: unexpected content after document
vertical tab before the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 0: unexpected character, expected a JSON value
vertical tab before a colon
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 4: unexpected character, expected ':' after key
vertical tab inside an ignored array
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: unexpected character, expected ',' or ']'
vertical tab after the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 16: unexpected content after document
byte 0xA0 before the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 0: unexpected character, expected a JSON value
byte 0xA0 before a colon
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 4: unexpected character, expected ':' after key
byte 0xA0 inside an ignored array
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: unexpected character, expected ',' or ']'
byte 0xA0 after the root
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 16: unexpected content after document
numbers
  > {"s":"x","n":0,"f":100.0}
  > {"s":"x","n":0,"f":0.0005}
  > {"s":"x","n":-9223372036854775808,"f":-1.5e-300}
  > {"s":"x","n":9223372036854775807,"f":5e-324}
  > {"s":"x","n":1,"f":-0.0}
  > {"s":"x","n":1,"f":18446744073709552000.0}
  = read=6 matched=6 emitted=6 bytesRead=212 bytesWritten=215
float64 from RAW 1e400
  = RecordException type_mismatch field="f" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "f": expected float64, got out-of-range number
int64 from UINT above INT64_MAX
  = RecordException type_mismatch field="n" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "n": expected int64, got out-of-range number
int64 from RAW 2^64
  = RecordException type_mismatch field="n" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "n": expected int64, got out-of-range number
int64 from RAW below INT64_MIN
  = RecordException type_mismatch field="n" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "n": expected int64, got out-of-range number
int64 from a real
  = RecordException type_mismatch field="n" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "n": expected int64, got real
unreferenced number 01
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: number with leading zero is not allowed
unreferenced number 1.
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after decimal point
unreferenced number .5
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: no digit after sign
unreferenced number -
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: no digit after sign
unreferenced number +1
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: no digit after sign
unreferenced number 1e
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after exponent sign
unreferenced number 1e+
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: no digit after exponent sign
unreferenced number 0x1
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: unexpected character, expected ',' or '}'
unreferenced number NaN
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: unexpected character, expected a JSON value
unreferenced number Infinity
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: unexpected character, expected a JSON value
unreferenced number -Infinity
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: no digit after sign
unreferenced number 1.5e
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 23: no digit after exponent sign
unreferenced number --1
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: no digit after sign
unreferenced number -01
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: number with leading zero is not allowed
unreferenced number 1E400
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=25 bytesWritten=16
unreferenced number -1e400
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=26 bytesWritten=16
unreferenced number 123456789012345678901234567890
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=50 bytesWritten=16
unreferenced number 1.e5
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after decimal point
unreferenced number 1.a
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after decimal point
unreferenced number 0.a1
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after decimal point
unreferenced number 1ea
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 21: no digit after exponent sign
unreferenced number 1e+a
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: no digit after exponent sign
unreferenced number 1E-x
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 22: no digit after exponent sign
number range on scanned values
  > {"s":"x","n":15}
  > {"s":"x","n":10}
  > {"s":"x","n":11}
  = read=5 matched=3 emitted=3 bytesRead=118 bytesWritten=51
in on a RAW where value
  = read=1 matched=0 emitted=0 bytesRead=40 bytesWritten=0
RAW numbers in referenced and ignored members
  > {"s":"x","n":3}
  = read=4 matched=1 emitted=1 bytesRead=195 bytesWritten=16
referenced object field
  = RecordException type_mismatch field="s" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "s": expected string, got object
referenced array field
  = RecordException type_mismatch field="s" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): field "s": expected string, got array
where on an object
  = RecordException where_not_scalar field="w" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): where key "w" must be a JSON scalar
where on an array
  = RecordException where_not_scalar field="w" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): where key "w" must be a JSON scalar
nesting 64 below the root
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=146 bytesWritten=16
nesting 65 below the root
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=148 bytesWritten=16
nesting 66 below the root
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=150 bytesWritten=16
objects 65 deep
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=411 bytesWritten=16
nesting at the yyjson limit
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=1038 bytesWritten=16
nesting past the yyjson limit
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=1040 bytesWritten=16
unclosed nesting
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 28: unexpected character, expected a JSON value
mismatched brackets
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: unexpected character, expected a JSON value
empty array closed by a brace
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: unexpected character, expected a JSON value
empty object closed by a bracket
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 20: unexpected character, expected a string key
array closed by a brace after a value
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected character, expected ',' or ']'
object closed by a bracket after a value
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 11: unexpected character, expected ',' or '}'
inner array closed by a brace
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 9: unexpected character, expected ',' or ']'
array in an object closed by a brace
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 12: unexpected character, expected ',' or ']'
object in an array closed by a bracket
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 12: unexpected character, expected ',' or '}'
referenced array closed by a brace
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 7: unexpected character, expected ',' or ']'
object at depth 66, outer array closed by a brace
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 148: unexpected character, expected ',' or ']'
whitespace
  > {"s":"x","n":1}
  = read=1 matched=1 emitted=1 bytesRead=25 bytesWritten=16
trailing garbage
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 15: unexpected content after document
second value
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 16: unexpected content after document
trailing comma
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 14: trailing comma is not allowed
missing colon
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 5: unexpected character, expected ':' after key
missing comma
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 9: unexpected character, expected ',' or '}'
unquoted key
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 1: unexpected character, expected a string key
single quotes
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 1: unexpected character, expected a string key
unterminated string
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 9: unexpected character, expected ',' or '}'
true false null
  > {"s":"x","n":1}
  = RecordException null_field field="n" at=2/2/44 [read=1 matched=1 emitted=1 bytesRead=44 bytesWritten=16] | line 2 (record 2, offset 44): field "n" must not be null
bad literals
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: invalid literal, expected 'true'
bad literal nul
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: invalid literal, expected 'null'
bad literal falsy
  = RecordException invalid_json field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): invalid JSON at byte 19: invalid literal, expected 'false'
where on bools and null
  > {"s":"x","n":1}
  > {"s":"x","n":3}
  = read=3 matched=2 emitted=2 bytesRead=75 bytesWritten=32
roots that are not objects
  = RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): root is not an object
string root
  = RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): root is not an object
number root
  = RecordException not_object field=null at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): root is not an object
empty object
  = RecordException missing_field field="s" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0] | line 1 (record 1, offset 0): missing field "s"
key filter neighbours
  > {"amount":5}
  = RecordException missing_field field="amount" at=2/2/38 [read=1 matched=1 emitted=1 bytesRead=38 bytesWritten=13] | line 2 (record 2, offset 38): missing field "amount"
last record without LF ends in a string
  > {"s":"x","n":1}
  > {"s":"abcdefghijklmnopA","n":2}
  = read=2 matched=2 emitted=2 bytesRead=52 bytesWritten=48
nested plan uses yyjson only
  > {"s":"x","n":1,"k":"é"}
  = read=1 matched=1 emitted=1 bytesRead=34 bytesWritten=25
mixed valid and invalid records
  > {"s":"a","n":1}
  > {"s":"b","n":2}
  = RecordException invalid_json field=null at=3/3/32 [read=2 matched=2 emitted=2 bytesRead=32 bytesWritten=32] | line 3 (record 3, offset 32): invalid JSON at byte 20: no low surrogate in string
