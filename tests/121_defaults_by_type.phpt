--TEST--
jsonfuse 1.0: a declared default is emitted for a missing key of each type; a present value, JSON null included, is never replaced; nullable without a default still requires the key
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$spec = ['fields' => [
    'id' => 'int64',
    'n' => ['type' => 'int64', 'default' => -5],
    'f' => ['type' => 'float64', 'default' => 1.5],
    'g' => ['type' => 'float64', 'default' => 2.0],
    'z' => ['type' => 'float64', 'default' => -0.0],
    'b' => ['type' => 'bool', 'default' => false],
    's' => ['type' => 'string', 'default' => 'free'],
    'e' => ['type' => 'string', 'default' => ''],
    'm' => ['type' => 'int64?', 'default' => null],
    'k' => ['type' => 'bool?', 'default' => true],
]];

jf10_show('every defaulted key missing', "{\"id\":1}\n", $spec);
jf10_show('every key present', "{\"id\":2,\"n\":7,\"f\":3,\"g\":0.5,\"z\":1e3,\"b\":true,\"s\":\"paid\",\"e\":\"x\",\"m\":4,\"k\":false}\n", $spec);
jf10_show('present null in nullable fields with defaults', "{\"id\":3,\"m\":null,\"k\":null}\n", $spec);
jf10_show('present null in a non-nullable field with a default', "{\"id\":1}\n{\"id\":4,\"n\":null}\n", $spec);
jf10_show('present wrong type in a field with a default', "{\"id\":1}\n{\"id\":5,\"s\":5}\n", $spec);

$nullable = ['fields' => ['id' => 'int64', 'q' => 'string?']];
jf10_show('nullable without a default, key present as null', "{\"id\":6,\"q\":null}\n", $nullable);
jf10_show('nullable without a default, key missing', "{\"id\":7}\n", $nullable);

echo "-- string defaults go through the output escape table\n";
$raw = "q\"\\\n\r\t\x08\x0C\x01\x1F/\x7F\u{E9}\u{2028}\u{1F600}";
[$o] = jf10_run("{}\n", ['fields' => ['s' => ['type' => 'string', 'default' => $raw]]]);
$want = '{"s":' . json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS) . "}\n";
var_dump($o === $want);
?>
--EXPECT--
every defaulted key missing
  > {"id":1,"n":-5,"f":1.5,"g":2.0,"z":-0.0,"b":false,"s":"free","e":"","m":null,"k":true}
  = read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=87
every key present
  > {"id":2,"n":7,"f":3.0,"g":0.5,"z":1000.0,"b":true,"s":"paid","e":"x","m":4,"k":false}
  = read=1 matched=1 emitted=1 bytesRead=81 bytesWritten=86
present null in nullable fields with defaults
  > {"id":3,"n":-5,"f":1.5,"g":2.0,"z":-0.0,"b":false,"s":"free","e":"","m":null,"k":null}
  = read=1 matched=1 emitted=1 bytesRead=27 bytesWritten=87
present null in a non-nullable field with a default
  > {"id":1,"n":-5,"f":1.5,"g":2.0,"z":-0.0,"b":false,"s":"free","e":"","m":null,"k":true}
  = RecordException null_field field="n" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=87]
present wrong type in a field with a default
  > {"id":1,"n":-5,"f":1.5,"g":2.0,"z":-0.0,"b":false,"s":"free","e":"","m":null,"k":true}
  = RecordException type_mismatch field="s" at=2/2/9 [read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=87]
nullable without a default, key present as null
  > {"id":6,"q":null}
  = read=1 matched=1 emitted=1 bytesRead=18 bytesWritten=18
nullable without a default, key missing
  = RecordException missing_field field="q" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- string defaults go through the output escape table
bool(true)
