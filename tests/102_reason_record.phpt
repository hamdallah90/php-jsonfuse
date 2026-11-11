--TEST--
jsonfuse 1.0: every record reason code with getField(), position getters and progress, the validation order, and the buffered good records written before the throw
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

const P = "{\"id\":1}\n";   // 9 bytes, a good matching record for the default plan
const T = "{\"id\":3}\n";   // after the failing line: never read into the counts, never written

function r(string $label, string $input, array $spec = []): void
{
    [$o, $res] = jf10_run($input, $spec + ['fields' => ['id' => 'int64']]);
    echo $label, "\n  ", $res, "\n  out=", json_encode($o), "\n";
}

$w = ['where' => ['w' => 'x']];

echo "-- framing\n";
r('blank line', P . "\n" . T);
r('blank line after CRLF', "{\"id\":1}\r\n\r\n" . T);
r('skipped blanks count in line and offset, not in record', "\n" . P . "\r\n{bad\n" . T, ['skipEmptyLines' => true]);
r('over maxRecordBytes 8, LF', P . "{\"id\":12}\n" . T, ['maxRecordBytes' => 8]);
r('over maxRecordBytes 8, at EOF', P . '{"id":12}', ['maxRecordBytes' => 8]);

echo "-- JSON\n";
r('syntax', P . "{\"id\":1,}\n" . T);
r('UTF-8', P . "{\"id\":1,\"s\":\"\xff\"}\n" . T);
r('nesting 512', P . '{"id":2,"z":' . str_repeat('[', 511) . str_repeat(']', 511) . "}\n" . T);
r('nesting 511 is accepted', P . '{"id":2,"z":' . str_repeat('[', 510) . str_repeat(']', 510) . "}\n");
r('open-container flood', P . str_repeat('[', 300) . "\n" . T);
foreach (['[1]', '1', '"x"', 'null'] as $root) {
    r("root $root", P . $root . "\n" . T);
}

echo "-- typed\n";
r('duplicate field key', P . "{\"id\":1,\"id\":2}\n" . T);
r('duplicate spelled with an escape', P . "{\"id\":1,\"\\u0069d\":2}\n" . T);
r('duplicate where-only key', P . "{\"id\":1,\"w\":\"x\",\"w\":\"y\"}\n" . T, $w);
r('lowest second occurrence wins', "{\"a\":1,\"b\":1,\"b\":2,\"a\":2}\n", ['fields' => ['a' => 'int64', 'b' => 'int64']]);
r('unreferenced duplicates are accepted', P . "{\"id\":2,\"z\":1,\"z\":2,\"y\":{\"q\":1,\"q\":2}}\n");
r('missing field', "{\"id\":1,\"s\":\"a\"}\n{\"id\":2}\n" . T, ['fields' => ['id' => 'int64', 's' => 'string']]);
r('null in a non-nullable field', P . "{\"id\":null}\n" . T);
r('null field after CRLF lines', "{\"id\":1}\r\n{\"id\":null}\r\n" . T);
r('type mismatch', P . "{\"id\":\"1\"}\n" . T);
r('where key holding an array', P . "{\"id\":2,\"w\":[1]}\n" . T, $w);
r('where key holding an object', P . "{\"id\":2,\"w\":{}}\n" . T, $w);

echo "-- validation order\n";
r('field type checked though the record does not match', P . "{\"id\":\"s\",\"w\":\"y\"}\n" . T, $w);
r('field type before where scalar form', P . "{\"id\":\"s\",\"w\":[1]}\n" . T, $w);
r('duplicate before missing field', P . "{\"w\":1,\"w\":2}\n" . T, $w);
r('not-object before duplicate', P . "[{\"id\":1,\"id\":2}]\n" . T);
?>
--EXPECT--
-- framing
blank line
  RecordException blank_line field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
blank line after CRLF
  RecordException blank_line field=null at=2/2/10 [read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=9]
  out="{\"id\":1}\n"
skipped blanks count in line and offset, not in record
  RecordException invalid_json field=null at=4/2/12 [read=1 matched=1 emitted=1 bytesRead=12 bytesWritten=9]
  out="{\"id\":1}\n"
over maxRecordBytes 8, LF
  RecordException record_too_large field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
over maxRecordBytes 8, at EOF
  RecordException record_too_large field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
-- JSON
syntax
  RecordException invalid_json field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
UTF-8
  RecordException invalid_json field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
nesting 512
  RecordException invalid_json field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
nesting 511 is accepted
  read=2 matched=2 emitted=2 bytesRead=1043 bytesWritten=18
  out="{\"id\":1}\n{\"id\":2}\n"
open-container flood
  RecordException invalid_json field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
root [1]
  RecordException not_object field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
root 1
  RecordException not_object field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
root "x"
  RecordException not_object field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
root null
  RecordException not_object field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
-- typed
duplicate field key
  RecordException duplicate_key field="id" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
duplicate spelled with an escape
  RecordException duplicate_key field="id" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
duplicate where-only key
  RecordException duplicate_key field="w" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
lowest second occurrence wins
  RecordException duplicate_key field="b" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
  out=""
unreferenced duplicates are accepted
  read=2 matched=2 emitted=2 bytesRead=48 bytesWritten=18
  out="{\"id\":1}\n{\"id\":2}\n"
missing field
  RecordException missing_field field="s" at=2/2/17 [read=1 matched=1 emitted=1 bytesRead=17 bytesWritten=17]
  out="{\"id\":1,\"s\":\"a\"}\n"
null in a non-nullable field
  RecordException null_field field="id" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
null field after CRLF lines
  RecordException null_field field="id" at=2/2/10 [read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=9]
  out="{\"id\":1}\n"
type mismatch
  RecordException type_mismatch field="id" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
where key holding an array
  RecordException where_not_scalar field="w" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
where key holding an object
  RecordException where_not_scalar field="w" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
-- validation order
field type checked though the record does not match
  RecordException type_mismatch field="id" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
field type before where scalar form
  RecordException type_mismatch field="id" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
duplicate before missing field
  RecordException duplicate_key field="w" at=2/2/9 [read=1 matched=0 emitted=0 bytesRead=9 bytesWritten=0]
  out=""
not-object before duplicate
  RecordException not_object field=null at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9]
  out="{\"id\":1}\n"
