--TEST--
jsonfuse 1.0: a default must match the declared type at compile time with no conversion, null only for a nullable type; a 'default' key holding null counts
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$rows = [
    'int64' => [5, 0, PHP_INT_MIN, PHP_INT_MAX, '5', 5.0, true, null, [], NAN],
    'int64?' => [null, 5, '5'],
    'float64' => [1.5, -0.0, 1e308, '1.0', true, null, NAN, INF, -INF, []],
    'float64?' => [null, 2.5, false],
    'bool' => [true, false, 0, 1, 'true', null],
    'bool?' => [null, false, 'false'],
    'string' => ['x', '', str_repeat('s', 1024), str_repeat('s', 1025), "ok\xff", "\xed\xa0\x80", 5, 1.5, true, null, ['x']],
    'string?' => [null, 'x', 0],
];
foreach ($rows as $type => $defaults) {
    foreach ($defaults as $d) {
        $shown = match (true) {
            is_string($d) && strlen($d) > 20 => 'str(' . strlen($d) . ')',
            is_string($d) && !preg_match('//u', $d) => 'hex(' . bin2hex($d) . ')',
            default => str_replace("\n", '', var_export($d, true)),
        };
        echo str_pad("$type default $shown", 40), jf10_compile(['fields' => ['id' => 'int64', 'v' => ['type' => $type, 'default' => $d]]]), "\n";
    }
}
?>
--EXPECT--
int64 default 5                         ok
int64 default 0                         ok
int64 default -9223372036854775807-1    ok
int64 default 9223372036854775807       ok
int64 default '5'                       PlanException invalid_value option="fields" field="v"
int64 default 5.0                       PlanException invalid_value option="fields" field="v"
int64 default true                      PlanException invalid_value option="fields" field="v"
int64 default NULL                      PlanException invalid_value option="fields" field="v"
int64 default array ()                  PlanException invalid_value option="fields" field="v"
int64 default NAN                       PlanException invalid_value option="fields" field="v"
int64? default NULL                     ok
int64? default 5                        ok
int64? default '5'                      PlanException invalid_value option="fields" field="v"
float64 default 1.5                     ok
float64 default -0.0                    ok
float64 default 1.0E+308                ok
float64 default '1.0'                   PlanException invalid_value option="fields" field="v"
float64 default true                    PlanException invalid_value option="fields" field="v"
float64 default NULL                    PlanException invalid_value option="fields" field="v"
float64 default NAN                     PlanException invalid_value option="fields" field="v"
float64 default INF                     PlanException invalid_value option="fields" field="v"
float64 default -INF                    PlanException invalid_value option="fields" field="v"
float64 default array ()                PlanException invalid_value option="fields" field="v"
float64? default NULL                   ok
float64? default 2.5                    ok
float64? default false                  PlanException invalid_value option="fields" field="v"
bool default true                       ok
bool default false                      ok
bool default 0                          PlanException invalid_value option="fields" field="v"
bool default 1                          PlanException invalid_value option="fields" field="v"
bool default 'true'                     PlanException invalid_value option="fields" field="v"
bool default NULL                       PlanException invalid_value option="fields" field="v"
bool? default NULL                      ok
bool? default false                     ok
bool? default 'false'                   PlanException invalid_value option="fields" field="v"
string default 'x'                      ok
string default ''                       ok
string default str(1024)                ok
string default str(1025)                PlanException invalid_value option="fields" field="v"
string default hex(6f6bff)              PlanException invalid_value option="fields" field="v"
string default hex(eda080)              PlanException invalid_value option="fields" field="v"
string default 5                        PlanException invalid_value option="fields" field="v"
string default 1.5                      PlanException invalid_value option="fields" field="v"
string default true                     PlanException invalid_value option="fields" field="v"
string default NULL                     PlanException invalid_value option="fields" field="v"
string default array (  0 => 'x',)      PlanException invalid_value option="fields" field="v"
string? default NULL                    ok
string? default 'x'                     ok
string? default 0                       PlanException invalid_value option="fields" field="v"
