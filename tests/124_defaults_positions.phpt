--TEST--
jsonfuse 1.0: defaults in the first, middle and last output position and in every position, decided per record
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$spec = ['fields' => [
    'a' => ['type' => 'int64', 'default' => 1],
    'b' => 'int64',
    'c' => ['type' => 'string', 'default' => 'x'],
    'd' => ['type' => 'bool', 'default' => true],
]];
jf10_show('first, third and fourth defaulted, then each present in turn',
    "{\"b\":0}\n{\"a\":9,\"b\":1}\n{\"b\":2,\"c\":\"y\"}\n{\"b\":3,\"d\":false}\n{\"d\":false,\"c\":\"z\",\"b\":4,\"a\":8}\n{\"b\":5}\n", $spec);

$all = ['fields' => [
    'p' => ['type' => 'float64', 'default' => 0],
    'q' => ['type' => 'string?', 'default' => null],
    'r' => ['type' => 'int64', 'default' => -1],
]];
jf10_show('every field defaulted', "{}\n{\"z\":1}\n{\"q\":\"s\"}\n{}\n", $all);
?>
--EXPECT--
first, third and fourth defaulted, then each present in turn
  > {"a":1,"b":0,"c":"x","d":true}
  > {"a":9,"b":1,"c":"x","d":true}
  > {"a":1,"b":2,"c":"y","d":true}
  > {"a":1,"b":3,"c":"x","d":false}
  > {"a":8,"b":4,"c":"z","d":false}
  > {"a":1,"b":5,"c":"x","d":true}
  = read=6 matched=6 emitted=6 bytesRead=96 bytesWritten=188
every field defaulted
  > {"p":0.0,"q":null,"r":-1}
  > {"p":0.0,"q":null,"r":-1}
  > {"p":0.0,"q":"s","r":-1}
  > {"p":0.0,"q":null,"r":-1}
  = read=4 matched=4 emitted=4 bytesRead=24 bytesWritten=103
