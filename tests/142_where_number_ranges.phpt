--TEST--
jsonfuse 1.0: number range operators compare by exact mathematical value, integer or real, at 2^53, the int64 and uint64 limits and around -0.0; strings, bools, null and RAW numbers never match and are not errors
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

echo "-- small values\n";
$small = ['4', '5', '6', '4.5', '5.0', '5.5', '-5', '-5.5', '"6"', 'true', 'null', '1e400'];
jf10_match_rows([
    ["> 5", ['>' => 5]],
    [">= 5", ['>=' => 5]],
    ["< 5", ['<' => 5]],
    ["<= 5", ['<=' => 5]],
    ["> 4.5", ['>' => 4.5]],
    [">= 4.5 and < 5.5", ['>=' => 4.5, '<' => 5.5]],
    ["> -5.5 and <= -5", ['>' => -5.5, '<=' => -5]],
], $small);

echo "-- 2^53: an int operand against reals and a float operand against ints\n";
// 9007199254740993.0 is a REAL that rounds to 2^53.
$p53 = ['9007199254740992', '9007199254740993', '9007199254740992.0', '9007199254740993.0', '9007199254740994.0'];
jf10_match_rows([
    ["> int 2^53", ['>' => 9007199254740992]],
    ["> float 2^53", ['>' => 9007199254740992.0]],
    [">= int 2^53 + 1", ['>=' => 9007199254740993]],
    ["< int 2^53 + 1", ['<' => 9007199254740993]],
    ["<= float 2^53", ['<=' => 9007199254740992.0]],
], $p53);

echo "-- int64 and uint64 limits\n";
$lim = ['9223372036854775807', '9223372036854775808', '18446744073709551615', '18446744073709551616',
    '-9223372036854775808', '-9223372036854775809', '9223372036854775808.0', '1.8446744073709552e19', '-9.223372036854775808e18'];
jf10_match_rows([
    ["> PHP_INT_MAX", ['>' => PHP_INT_MAX]],
    [">= float 2^63", ['>=' => 9.2233720368547758E18]],
    ["< float 2^63", ['<' => 9.2233720368547758E18]],
    ["< float 2^64", ['<' => 1.8446744073709552E19]],
    [">= float 2^64", ['>=' => 1.8446744073709552E19]],
    ["<= PHP_INT_MIN", ['<=' => PHP_INT_MIN]],
    ["< PHP_INT_MIN", ['<' => PHP_INT_MIN]],
    ["> float -2^63", ['>' => -9.2233720368547758E18]],
], $lim);

echo "-- zero, negative zero and the smallest subnormals\n";
$zero = ['0', '-0', '0.0', '-0.0', '1e-400', '-1e-400', '5e-324', '-5e-324'];
jf10_match_rows([
    [">= 0", ['>=' => 0]],
    ["> 0", ['>' => 0]],
    ["< 0", ['<' => 0]],
    ["<= -0.0", ['<=' => -0.0]],
    ["> -0.0", ['>' => -0.0]],
    [">= 5e-324", ['>=' => 5.0E-324]],
], $zero);

echo "-- other kinds never match\n";
$other = ['"5"', '""', 'true', 'false', 'null', '1e400', '-1e400', '123456789012345678901234567890'];
jf10_match_rows([["> 0", ['>' => 0]], ["< 0", ['<' => 0]], [">= PHP_INT_MIN", ['>=' => PHP_INT_MIN]], ["<= 1e308", ['<=' => 1e308]]], $other);
echo "-- arrays and objects are where_not_scalar whatever the operator\n";
echo jf10_match(['>' => 0], ['[1]']), "\n";
echo jf10_match(['<=' => 0.5], ['{}']), "\n";

echo "-- compile checks\n";
$c = fn (array $e) => jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => $e]]);
foreach ([
    "> NAN" => ['>' => NAN], ">= INF" => ['>=' => INF], "< -INF" => ['<' => -INF], "<= true" => ['<=' => true],
    "> null" => ['>' => null], "> [1]" => ['>' => [1]], "< object" => ['<' => new stdClass()],
    "> 1 and < 2.5" => ['>' => 1, '<' => 2.5], "> 1 and < 'z'" => ['>' => 1, '<' => 'z'], "< 'z' and >= 1.5" => ['<' => 'z', '>=' => 1.5],
] as $label => $e) {
    echo str_pad($label, 20), $c($e), "\n";
}
?>
--EXPECT--
-- small values
> 5                                [6 5.5]
>= 5                               [5 6 5.0 5.5]
< 5                                [4 4.5 -5 -5.5]
<= 5                               [4 5 4.5 5.0 -5 -5.5]
> 4.5                              [5 6 5.0 5.5]
>= 4.5 and < 5.5                   [5 4.5 5.0]
> -5.5 and <= -5                   [-5]
-- 2^53: an int operand against reals and a float operand against ints
> int 2^53                         [9007199254740993 9007199254740994.0]
> float 2^53                       [9007199254740993 9007199254740994.0]
>= int 2^53 + 1                    [9007199254740993 9007199254740994.0]
< int 2^53 + 1                     [9007199254740992 9007199254740992.0 9007199254740993.0]
<= float 2^53                      [9007199254740992 9007199254740992.0 9007199254740993.0]
-- int64 and uint64 limits
> PHP_INT_MAX                      [9223372036854775808 18446744073709551615 9223372036854775808.0 1.8446744073709552e19]
>= float 2^63                      [9223372036854775808 18446744073709551615 9223372036854775808.0 1.8446744073709552e19]
< float 2^63                       [9223372036854775807 -9223372036854775808 -9.223372036854775808e18]
< float 2^64                       [9223372036854775807 9223372036854775808 18446744073709551615 -9223372036854775808 9223372036854775808.0 -9.223372036854775808e18]
>= float 2^64                      [1.8446744073709552e19]
<= PHP_INT_MIN                     [-9223372036854775808 -9.223372036854775808e18]
< PHP_INT_MIN                      []
> float -2^63                      [9223372036854775807 9223372036854775808 18446744073709551615 9223372036854775808.0 1.8446744073709552e19]
-- zero, negative zero and the smallest subnormals
>= 0                               [0 -0 0.0 -0.0 1e-400 -1e-400 5e-324]
> 0                                [5e-324]
< 0                                [-5e-324]
<= -0.0                            [0 -0 0.0 -0.0 1e-400 -1e-400 -5e-324]
> -0.0                             [5e-324]
>= 5e-324                          [5e-324]
-- other kinds never match
> 0                                []
< 0                                []
>= PHP_INT_MIN                     []
<= 1e308                           []
-- arrays and objects are where_not_scalar whatever the operator
RecordException where_not_scalar field="v" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
RecordException where_not_scalar field="v" at=1/1/0 [read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0]
-- compile checks
> NAN               PlanException invalid_value option="where" field="v"
>= INF              PlanException invalid_value option="where" field="v"
< -INF              PlanException invalid_value option="where" field="v"
<= true             PlanException invalid_value option="where" field="v"
> null              PlanException invalid_value option="where" field="v"
> [1]               PlanException invalid_value option="where" field="v"
< object            PlanException invalid_value option="where" field="v"
> 1 and < 2.5       ok
> 1 and < 'z'       PlanException invalid_value option="where" field="v"
< 'z' and >= 1.5    PlanException invalid_value option="where" field="v"
