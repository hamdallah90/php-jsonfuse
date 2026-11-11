--TEST--
jsonfuse: matching records are written as NDJSON of the declared fields in schema order, with exact recordsEmitted and bytesWritten
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * Expected output is written out literally below. The output form is the compact one,
 * {"a":1,"b":"x"} followed by LF (SPEC.md section 6: declared fields, schema order, LF-terminated).
 */
function go(string $title, array $spec, string $data): void
{
    echo "-- $title\n";
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $s = Runner::process($in, $out, Plan::compile($spec));
    } catch (Throwable $e) {
        echo get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
        return;
    }
    $bytes = jf_out_bytes($out);
    echo jf_stats($s), "\n";
    $consistent = $s->recordsEmitted === $s->recordsMatched && $s->bytesWritten === strlen($bytes)
        && $s->bytesWritten === ftell($out) && ($bytes === '' || str_ends_with($bytes, "\n"))
        && substr_count($bytes, "\n") === $s->recordsEmitted;
    echo 'emitted == matched, bytesWritten == output length == ftell, one LF per record: ', var_export($consistent, true), "\n";
    echo $bytes === '' ? "(no output)\n" : $bytes;
    fclose($in);
    fclose($out);
}

$spec = ['fields' => ['id' => 'int64', 'name' => 'string', 'active' => 'bool?'], 'where' => ['kind' => 'customer']];
go('declared order, whatever the input key order; where-only key and extra keys are not emitted', $spec, implode("\n", [
    '{"id":1,"name":"a","active":true,"kind":"customer"}',
    '{"kind":"customer","active":false,"name":"b","id":2}',
    '{"name":"c","kind":"customer","id":3,"active":null}',
    '{"id":4,"name":"d","active":true,"kind":"vendor"}',
    '{"extra":{"n":[1,2,{"x":1e400}]},"active":true,"name":"e","id":5,"kind":"customer","more":[]}',
]) . "\n");

go('a key in both fields and where is written once, at its schema position', ['fields' => ['b' => 'string', 'a' => 'int64'], 'where' => ['a' => 7]],
    "{\"a\":7,\"b\":\"x\"}\n{\"a\":8,\"b\":\"y\"}\n{\"b\":\"z\",\"a\":7}\n");

go('where-only keys: nothing of them reaches the output', ['fields' => ['c' => 'int64'], 'where' => ['a' => 1, 'b' => 'x', 'n' => null, 'f' => 1.5, 't' => true]],
    "{\"a\":1,\"b\":\"x\",\"n\":null,\"f\":1.5,\"t\":true,\"c\":10}\n{\"a\":2,\"b\":\"x\",\"n\":null,\"f\":1.5,\"t\":true,\"c\":11}\n");

go('schema order is neither input order nor alphabetical', ['fields' => ['z' => 'int64', 'y' => 'int64', 'x' => 'int64', 'm' => 'int64']],
    "{\"x\":1,\"m\":4,\"y\":2,\"z\":3}\n{\"m\":8,\"z\":5,\"y\":6,\"x\":7}\n");

go('every nullable type, null and a value', [
    'fields' => ['i' => 'int64?', 'f' => 'float64?', 'b' => 'bool?', 's' => 'string?'],
], implode("\n", [
    '{"i":null,"f":null,"b":null,"s":null}',
    '{"i":1,"f":2.5,"b":false,"s":"x"}',
    '{"s":null,"b":true,"f":null,"i":-9}',
    '{"i":null,"f":0,"b":null,"s":""}',
]) . "\n");

go('null is written as null; a non-nullable type never turns into null', ['fields' => ['a' => 'string?']], "{\"a\":null}\n{\"a\":\"null\"}\n");

go('__proto__ and constructor are plain names', ['fields' => ['__proto__' => 'string', 'constructor' => 'int64?', 'toString' => 'bool']],
    "{\"toString\":true,\"__proto__\":\"x\",\"constructor\":null}\n{\"__proto__\":\"\",\"constructor\":3,\"toString\":false}\n");

go('__proto__ in where is a plain key too', ['fields' => ['a' => 'int64'], 'where' => ['__proto__' => 'x']],
    "{\"a\":1,\"__proto__\":\"x\"}\n{\"a\":2,\"__proto__\":\"y\"}\n{\"a\":3}\n");

go('empty field name', ['fields' => ['' => 'int64', 'a' => 'int64']], "{\"a\":2,\"\":1}\n");
go('empty field name alone', ['fields' => ['' => 'string']], "{\"\":\"v\"}\n");

go('numeric-string and integer field names stay strings, in schema order', [
    'fields' => [123 => 'int64', -5 => 'int64', '0123' => 'int64', '-0' => 'int64', '1.5' => 'int64', '' => 'int64', 'a' => 'int64'],
], '{"a":7,"":6,"1.5":5,"-0":4,"0123":3,"-5":2,"123":1}' . "\n");

go('non-ASCII field names are written raw', ['fields' => ["caf\u{e9}" => 'int64', "\u{1F600}" => 'string']],
    "{\"\u{1F600}\":\"s\",\"caf\u{e9}\":1}\n");

go('key written in the input with escapes, written in the output as the plan spells it', ['fields' => ["caf\u{e9}" => 'int64', 'a/b' => 'int64']],
    '{"caf\u00e9":1,"a\/b":2}' . "\n");

go('CRLF and a final record without LF: every output line ends in a bare LF', ['fields' => ['id' => 'int64']],
    "{\"id\":1}\r\n{\"id\":2}\r\n{\"id\":3}");

go('blank lines skipped: no output for them', ['fields' => ['id' => 'int64'], 'skipEmptyLines' => true], "\n{\"id\":1}\n\n\r\n{\"id\":2}\n");

go('integer field values', ['fields' => ['a' => 'int64', 'b' => 'int64', 'c' => 'int64', 'd' => 'int64']],
    '{"a":0,"b":-0,"c":9223372036854775807,"d":-9223372036854775808}' . "\n");

go('zero matches: empty output', ['fields' => ['id' => 'int64'], 'where' => ['kind' => 'x']], "{\"id\":1,\"kind\":\"y\"}\n{\"id\":2}\n{\"id\":3,\"kind\":null}\n");
go('empty input: empty output', ['fields' => ['id' => 'int64']], '');
go('only blank lines, skipped: empty output', ['fields' => ['id' => 'int64'], 'skipEmptyLines' => true], "\n\r\n\n");

echo "-- an existing prefix in the output stream is kept; bytesWritten counts this call only\n";
$out = jf_out();
fwrite($out, "HEAD\n");
$s = Runner::process(jf_mem("{\"id\":1}\n{\"id\":2}\n"), $out, jf_plan());
echo jf_stats($s), "\n";
echo 'position is 5 + bytesWritten: ', var_export(ftell($out) === 5 + $s->bytesWritten, true), "\n";
echo json_encode(jf_out_bytes($out)), "\n";
fclose($out);

echo "-- a second run on the same output appends\n";
$out = jf_out();
$a = Runner::process(jf_mem("{\"id\":1}\n"), $out, jf_plan());
$b = Runner::process(jf_mem("{\"id\":22}\n{\"id\":3}\n"), $out, jf_plan());
echo jf_stats($a), "\n", jf_stats($b), "\n";
echo jf_out_bytes($out);
fclose($out);

echo "-- the same bytes reach a plain file and a userland wrapper\n";
$path = jf_tmp('021');
try {
    $spec = ['fields' => ['id' => 'int64', 's' => 'string']];
    $data = "{\"id\":1,\"s\":\"a\"}\n{\"id\":2,\"s\":\"\u{e9}\"}\n";
    $f = fopen($path, 'wb');
    $s1 = Runner::process(jf_mem($data), $f, Plan::compile($spec));
    fclose($f);
    $w = jf_sink();
    $s2 = Runner::process(jf_mem($data), $w, Plan::compile($spec));
    $viaWrapper = JfSink::$last->data;
    fclose($w);
    $m = jf_out();
    $s3 = Runner::process(jf_mem($data), $m, Plan::compile($spec));
    $viaMemory = jf_out_bytes($m);
    fclose($m);
    $viaFile = file_get_contents($path);
    echo 'file == wrapper == memory: ', var_export($viaFile === $viaWrapper && $viaWrapper === $viaMemory, true), "\n";
    echo jf_stats($s1) === jf_stats($s2) && jf_stats($s2) === jf_stats($s3) ? "same stats\n" : "stats differ\n";
    echo $viaFile;
    echo jf_stats($s1), "\n";
} finally {
    @unlink($path);
}

echo "-- many records: output equals the reference, byte for byte\n";
$spec = ['fields' => ['id' => 'int64', 'name' => 'string', 'ok' => 'bool?'], 'where' => ['grp' => 3]];
$data = '';
for ($i = 1; $i <= 4000; $i++) {
    $data .= '{"name":"n' . $i . '","grp":' . ($i % 4) . ',"ok":' . ($i % 3 === 0 ? 'null' : ($i % 2 ? 'true' : 'false')) . ',"id":' . $i . "}\n";
}
$ref = JfRef::run($data, $spec);
$in = jf_mem($data);
$out = jf_out();
$s = Runner::process($in, $out, Plan::compile($spec));
echo jf_stats($s), "\n";
echo 'output equals the reference: ', var_export(jf_out_bytes($out) === $ref['out'], true), ' (', $ref['matched'], ' lines, ', strlen($ref['out']), " bytes)\n";
fclose($in);
fclose($out);
?>
--CLEAN--
<?php
require __DIR__ . '/jf_helper.inc';
jf_clean('021');
?>
--EXPECT--
-- declared order, whatever the input key order; where-only key and extra keys are not emitted
read=5 matched=4 emitted=4 bytesRead=301 bytesWritten=137
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"id":1,"name":"a","active":true}
{"id":2,"name":"b","active":false}
{"id":3,"name":"c","active":null}
{"id":5,"name":"e","active":true}
-- a key in both fields and where is written once, at its schema position
read=3 matched=2 emitted=2 bytesRead=48 bytesWritten=32
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"b":"x","a":7}
{"b":"z","a":7}
-- where-only keys: nothing of them reaches the output
read=2 matched=1 emitted=1 bytesRead=98 bytesWritten=9
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"c":10}
-- schema order is neither input order nor alphabetical
read=2 matched=2 emitted=2 bytesRead=52 bytesWritten=52
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"z":3,"y":2,"x":1,"m":4}
{"z":5,"y":6,"x":7,"m":8}
-- every nullable type, null and a value
read=4 matched=4 emitted=4 bytesRead=141 bytesWritten=143
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"i":null,"f":null,"b":null,"s":null}
{"i":1,"f":2.5,"b":false,"s":"x"}
{"i":-9,"f":null,"b":true,"s":null}
{"i":null,"f":0.0,"b":null,"s":""}
-- null is written as null; a non-nullable type never turns into null
read=2 matched=2 emitted=2 bytesRead=24 bytesWritten=24
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"a":null}
{"a":"null"}
-- __proto__ and constructor are plain names
read=2 matched=2 emitted=2 bytesRead=103 bytesWritten=103
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"__proto__":"x","constructor":null,"toString":true}
{"__proto__":"","constructor":3,"toString":false}
-- __proto__ in where is a plain key too
read=3 matched=1 emitted=1 bytesRead=56 bytesWritten=8
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"a":1}
-- empty field name
read=1 matched=1 emitted=1 bytesRead=13 bytesWritten=13
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"":1,"a":2}
-- empty field name alone
read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"":"v"}
-- numeric-string and integer field names stay strings, in schema order
read=1 matched=1 emitted=1 bytesRead=52 bytesWritten=52
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"123":1,"-5":2,"0123":3,"-0":4,"1.5":5,"":6,"a":7}
-- non-ASCII field names are written raw
read=1 matched=1 emitted=1 bytesRead=23 bytesWritten=23
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"café":1,"😀":"s"}
-- key written in the input with escapes, written in the output as the plan spells it
read=1 matched=1 emitted=1 bytesRead=25 bytesWritten=20
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"café":1,"a/b":2}
-- CRLF and a final record without LF: every output line ends in a bare LF
read=3 matched=3 emitted=3 bytesRead=28 bytesWritten=27
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"id":1}
{"id":2}
{"id":3}
-- blank lines skipped: no output for them
read=2 matched=2 emitted=2 bytesRead=22 bytesWritten=18
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"id":1}
{"id":2}
-- integer field values
read=1 matched=1 emitted=1 bytesRead=64 bytesWritten=63
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
{"a":0,"b":0,"c":9223372036854775807,"d":-9223372036854775808}
-- zero matches: empty output
read=3 matched=0 emitted=0 bytesRead=50 bytesWritten=0
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
(no output)
-- empty input: empty output
read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
(no output)
-- only blank lines, skipped: empty output
read=0 matched=0 emitted=0 bytesRead=4 bytesWritten=0
emitted == matched, bytesWritten == output length == ftell, one LF per record: true
(no output)
-- an existing prefix in the output stream is kept; bytesWritten counts this call only
read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
position is 5 + bytesWritten: true
"HEAD\n{\"id\":1}\n{\"id\":2}\n"
-- a second run on the same output appends
read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
read=2 matched=2 emitted=2 bytesRead=19 bytesWritten=19
{"id":1}
{"id":22}
{"id":3}
-- the same bytes reach a plain file and a userland wrapper
file == wrapper == memory: true
same stats
{"id":1,"s":"a"}
{"id":2,"s":"é"}
read=2 matched=2 emitted=2 bytesRead=35 bytesWritten=35
-- many records: output equals the reference, byte for byte
read=4000 matched=1000 emitted=1000 bytesRead=179120 bytesWritten=36446
output equals the reference: true (1000 lines, 36446 bytes)
