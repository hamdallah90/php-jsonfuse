--TEST--
jsonfuse 1.0: a float64 default may be a PHP int exactly representable as binary64, stored as a REAL and emitted in float form; other ints are invalid_value, while record ints in a float64 field still round
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$ints = [
    '0' => 0,
    '1' => 1,
    '-1' => -1,
    '2^53' => 9007199254740992,
    '-2^53' => -9007199254740992,
    '2^53 + 1' => 9007199254740993,
    '-(2^53 + 1)' => -9007199254740993,
    '2^53 + 2' => 9007199254740994,
    '2^60' => 1152921504606846976,
    '2^60 + 1' => 1152921504606846977,
    '2^63 - 1024' => 9223372036854774784,
    '2^63 - 1023' => 9223372036854774785,
    'PHP_INT_MAX' => PHP_INT_MAX,
    'PHP_INT_MIN' => PHP_INT_MIN,
    'PHP_INT_MIN + 1' => PHP_INT_MIN + 1,
];
foreach (['float64', 'float64?'] as $type) {
    echo "-- $type\n";
    foreach ($ints as $label => $i) {
        [$o, $r] = jf10_run("{}\n", ['fields' => ['f' => ['type' => $type, 'default' => $i]]]);
        echo str_pad($label, 18), str_starts_with($r, 'read=') ? rtrim($o, "\n") : $r, "\n";
    }
}

echo "-- an int64 default stays integral\n";
jf10_show('int64 default 2^53 + 1', "{}\n", ['fields' => ['i' => ['type' => 'int64', 'default' => 9007199254740993]]]);
echo "-- record ints in a float64 field are rounded, unlike defaults\n";
jf10_show('record 2^53 + 1', "{\"f\":9007199254740993}\n", ['fields' => ['f' => ['type' => 'float64', 'default' => 0]]]);
?>
--EXPECT--
-- float64
0                 {"f":0.0}
1                 {"f":1.0}
-1                {"f":-1.0}
2^53              {"f":9007199254740992.0}
-2^53             {"f":-9007199254740992.0}
2^53 + 1          PlanException invalid_value option="fields" field="f"
-(2^53 + 1)       PlanException invalid_value option="fields" field="f"
2^53 + 2          {"f":9007199254740994.0}
2^60              {"f":1152921504606847000.0}
2^60 + 1          PlanException invalid_value option="fields" field="f"
2^63 - 1024       {"f":9223372036854775000.0}
2^63 - 1023       PlanException invalid_value option="fields" field="f"
PHP_INT_MAX       PlanException invalid_value option="fields" field="f"
PHP_INT_MIN       {"f":-9223372036854776000.0}
PHP_INT_MIN + 1   PlanException invalid_value option="fields" field="f"
-- float64?
0                 {"f":0.0}
1                 {"f":1.0}
-1                {"f":-1.0}
2^53              {"f":9007199254740992.0}
-2^53             {"f":-9007199254740992.0}
2^53 + 1          PlanException invalid_value option="fields" field="f"
-(2^53 + 1)       PlanException invalid_value option="fields" field="f"
2^53 + 2          {"f":9007199254740994.0}
2^60              {"f":1152921504606847000.0}
2^60 + 1          PlanException invalid_value option="fields" field="f"
2^63 - 1024       {"f":9223372036854775000.0}
2^63 - 1023       PlanException invalid_value option="fields" field="f"
PHP_INT_MAX       PlanException invalid_value option="fields" field="f"
PHP_INT_MIN       {"f":-9223372036854776000.0}
PHP_INT_MIN + 1   PlanException invalid_value option="fields" field="f"
-- an int64 default stays integral
int64 default 2^53 + 1
  > {"i":9007199254740993}
  = read=1 matched=1 emitted=1 bytesRead=3 bytesWritten=23
-- record ints in a float64 field are rounded, unlike defaults
record 2^53 + 1
  > {"f":9007199254740992.0}
  = read=1 matched=1 emitted=1 bytesRead=23 bytesWritten=25
