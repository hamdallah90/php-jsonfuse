--TEST--
jsonfuse 1.0: nested field paths of 1 to 8 literal segments project to flat output keys; segments are raw key bytes compared after escape decoding, with no dot parsing
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$spec = ['fields' => [
    'id' => 'int64',
    'city' => ['type' => 'string', 'path' => ['address', 'city']],
    'deep' => ['type' => 'int64', 'path' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']],
    'dot' => ['type' => 'int64', 'path' => ['x.y']],
    'zero' => ['type' => 'int64', 'path' => ['n', '0']],
    'empty' => ['type' => 'bool', 'path' => ['', 'k']],
]];
$r1 = '{"id":1,"address":{"city":"Berlin","zip":"1"},"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":8}}}}}}},"x.y":5,"x":{"y":6},"n":{"0":7},"":{"k":true}}';
$r2 = '{"\\u0069d":2,"addr\\u0065ss":{"c\\u0069ty":"B\\u00e9"},"a":{"b":{"c":{"d":{"e":{"f":{"g":{"\\u0068":-8}}}}}}},"x.y":0,"n":{"\\u0030":0},"":{"k":false}}';
jf10_show('nested, 8 deep, dotted key, "0" key, empty key, escaped spellings', "$r1\n$r2\n", $spec);

echo "-- a missing path means [name]: three spellings of one plan\n";
$in = "{\"a\":1,\"b\":{\"a\":2}}\n{\"a\":3}\n";
$outs = [];
foreach ([['a' => 'int64'], ['a' => ['type' => 'int64']], ['a' => ['type' => 'int64', 'path' => ['a']]]] as $fields) {
    $outs[] = jf10_run($in, ['fields' => $fields]);
}
jf10_print('a => int64', ...$outs[0]);
var_dump($outs[0] === $outs[1], $outs[0] === $outs[2]);

echo "-- the output key is the field name, whatever the path\n";
jf10_show('two names over one nested object', "{\"o\":{\"p\":1,\"q\":\"s\"}}\n",
    ['fields' => ['q' => ['type' => 'int64', 'path' => ['o', 'p']], 'p' => ['type' => 'string', 'path' => ['o', 'q']]]]);
?>
--EXPECT--
nested, 8 deep, dotted key, "0" key, empty key, escaped spellings
  > {"id":1,"city":"Berlin","deep":8,"dot":5,"zero":7,"empty":true}
  > {"id":2,"city":"Bé","deep":-8,"dot":0,"zero":0,"empty":false}
  = read=2 matched=2 emitted=2 bytesRead=288 bytesWritten=127
-- a missing path means [name]: three spellings of one plan
a => int64
  > {"a":1}
  > {"a":3}
  = read=2 matched=2 emitted=2 bytesRead=28 bytesWritten=16
bool(true)
bool(true)
-- the output key is the field name, whatever the path
two names over one nested object
  > {"q":1,"p":"s"}
  = read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=16
