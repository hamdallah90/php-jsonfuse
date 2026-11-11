--TEST--
jsonfuse 1.0: '=' applies exactly the type-strict equality and gives the same results as the scalar shorthand for every scalar type
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$lits = ['1', '1.0', '"1"', '0', '-0', '0.0', '-0.0', 'true', 'false', 'null', '""', '"a"', '"A"', '"é"',
    '9007199254740993', '9007199254740992.0', '-9223372036854775808', '18446744073709551615', '1e400', '123456789012345678901234567890'];
$entries = [
    'int 1' => 1, 'float 1.0' => 1.0, 'string "1"' => '1', 'int 0' => 0, 'float 0.0' => 0.0, 'float -0.0' => -0.0,
    'true' => true, 'false' => false, 'null' => null, 'string ""' => '', 'string "a"' => 'a', 'string "é"' => 'é',
    'int 2^53 + 1' => 9007199254740993, 'float 2^53' => 9007199254740992.0, 'int PHP_INT_MIN' => PHP_INT_MIN,
    'int PHP_INT_MAX' => PHP_INT_MAX, 'float 1e300' => 1e300,
];
foreach ($entries as $label => $v) {
    $short = jf10_match($v, $lits);
    $eq = jf10_match(['=' => $v], $lits);
    echo str_pad($label, 18), $short, $eq === $short ? '' : " DIFFERENT for '=': $eq", "\n";
}

echo "-- strings compare as bytes after escape decoding\n";
$esc = ['escaped a' => '"\\u0061"', 'escaped é' => '"\\u00e9"', 'escaped pair' => '"\\ud83d\\ude00"', 'raw 😀' => '"😀"', 'a NUL' => '"a\\u0000"'];
echo str_pad("'=' a", 18), jf10_match(['=' => 'a'], $esc), "\n";
echo str_pad("'=' é", 18), jf10_match(['=' => 'é'], $esc), "\n";
echo str_pad("'=' 😀", 18), jf10_match(['=' => '😀'], $esc), "\n";
echo str_pad("'=' a NUL", 18), jf10_match(['=' => "a\0"], $esc), "\n";

echo "-- '=' compile checks\n";
foreach (['array' => [1], 'NAN' => NAN, 'INF' => INF, 'object' => new stdClass(), 'bad UTF-8' => "\xff", '1025 bytes' => str_repeat('s', 1025)] as $label => $bad) {
    echo str_pad("'=' $label", 18), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => ['=' => $bad]]]), "\n";
}
echo str_pad("'=' 1024 bytes", 18), jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => ['=' => str_repeat('s', 1024)]]]), "\n";
?>
--EXPECT--
int 1             [1]
float 1.0         [1.0]
string "1"        ["1"]
int 0             [0 -0]
float 0.0         [0.0 -0.0]
float -0.0        [0.0 -0.0]
true              [true]
false             [false]
null              [null]
string ""         [""]
string "a"        ["a"]
string "é"       ["é"]
int 2^53 + 1      [9007199254740993]
float 2^53        [9007199254740992.0]
int PHP_INT_MIN   [-9223372036854775808]
int PHP_INT_MAX   []
float 1e300       []
-- strings compare as bytes after escape decoding
'=' a             [escaped a]
'=' é            [escaped é]
'=' 😀          [escaped pair raw 😀]
'=' a NUL         [a NUL]
-- '=' compile checks
'=' array         PlanException invalid_value option="where" field="v"
'=' NAN           PlanException invalid_value option="where" field="v"
'=' INF           PlanException invalid_value option="where" field="v"
'=' object        PlanException invalid_value option="where" field="v"
'=' bad UTF-8     PlanException invalid_value option="where" field="v"
'=' 1025 bytes    PlanException invalid_value option="where" field="v"
'=' 1024 bytes    ok
