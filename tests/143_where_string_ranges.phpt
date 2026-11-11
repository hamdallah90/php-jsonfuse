--TEST--
jsonfuse 1.0: string range operators compare unescaped record strings bytewise as unsigned bytes, shorter prefix first, which is code point order; other kinds never match; operands are UTF-8 of 0 to 1024 bytes
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$q = fn (string $s) => '"' . $s . '"';
$lits = [
    'a' => '"a"', 'ab' => '"ab"', 'abc' => '"abc"', 'abd' => '"abd"', 'b' => '"b"', 'B' => '"B"', 'empty' => '""',
    'e-acute' => '"é"', 'escaped-e-acute' => '"\\u00e9"', 'a-NUL' => '"a\\u0000"',
    'U+E000' => $q("\u{E000}"), 'U+FFFF' => $q("\u{FFFF}"), 'U+10000' => $q("\u{10000}"),
    'escaped-pair-U+1F600' => '"\\ud83d\\ude00"', 'raw-U+1F600' => $q("\u{1F600}"),
    'int-1' => '1', 'true' => 'true', 'null' => 'null', 'real-0.5' => '0.5',
];
jf10_match_rows([
    ["< 'abc'", ['<' => 'abc']],
    ["> 'ab'", ['>' => 'ab']],
    [">= 'é' and <= 'é'", ['>=' => 'é', '<=' => 'é']],
    ["> 'a' and < 'ab'", ['>' => 'a', '<' => 'ab']],
    ["< 'a'", ['<' => 'a']],
    ["> ''", ['>' => '']],
    [">= ''", ['>=' => '']],
    ["< ''", ['<' => '']],
    ["> U+FFFF", ['>' => "\u{FFFF}"]],
    ["> 'z' and < U+10000", ['>' => 'z', '<' => "\u{10000}"]],
    [">= U+1F600", ['>=' => "\u{1F600}"]],
], $lits);

echo "-- ISO 8601 text sorts as bytes, not as time\n";
$ts = ['10:00Z' => '"2026-11-09T10:00:00Z"', '10:00.5Z' => '"2026-11-09T10:00:00.5Z"',
    '12:00+02' => '"2026-11-09T12:00:00+02:00"', '09:59Z' => '"2026-11-09T09:59:59Z"'];
jf10_match_rows([
    ["> 2026-11-09T10:00:00Z", ['>' => '2026-11-09T10:00:00Z']],
    ["< 2026-11-09T10:00:00Z", ['<' => '2026-11-09T10:00:00Z']],
    ["day range", ['>=' => '2026-11-09', '<' => '2026-11-10']],
], $ts);

echo "-- compile checks\n";
$c = fn (array $e) => jf10_compile(['fields' => ['i' => 'int64'], 'where' => ['v' => $e]]);
foreach ([
    "> 1024 bytes" => ['>' => str_repeat('o', 1024)],
    "> 1025 bytes" => ['>' => str_repeat('o', 1025)],
    "< bad UTF-8" => ['<' => "ok\xff"],
    ">= surrogate bytes" => ['>=' => "\xed\xa0\x80"],
    "> '' and < 'b'" => ['>' => '', '<' => 'b'],
    "> 'a' and <= 2" => ['>' => 'a', '<=' => 2],
] as $label => $e) {
    echo str_pad($label, 22), $c($e), "\n";
}
?>
--EXPECT--
< 'abc'                            [a ab B empty a-NUL]
> 'ab'                             [abc abd b e-acute escaped-e-acute U+E000 U+FFFF U+10000 escaped-pair-U+1F600 raw-U+1F600]
>= 'é' and <= 'é'                [e-acute escaped-e-acute]
> 'a' and < 'ab'                   [a-NUL]
< 'a'                              [B empty]
> ''                               [a ab abc abd b B e-acute escaped-e-acute a-NUL U+E000 U+FFFF U+10000 escaped-pair-U+1F600 raw-U+1F600]
>= ''                              [a ab abc abd b B empty e-acute escaped-e-acute a-NUL U+E000 U+FFFF U+10000 escaped-pair-U+1F600 raw-U+1F600]
< ''                               []
> U+FFFF                           [U+10000 escaped-pair-U+1F600 raw-U+1F600]
> 'z' and < U+10000                [e-acute escaped-e-acute U+E000 U+FFFF]
>= U+1F600                         [escaped-pair-U+1F600 raw-U+1F600]
-- ISO 8601 text sorts as bytes, not as time
> 2026-11-09T10:00:00Z             [12:00+02]
< 2026-11-09T10:00:00Z             [10:00.5Z 09:59Z]
day range                          [10:00Z 10:00.5Z 12:00+02 09:59Z]
-- compile checks
> 1024 bytes          ok
> 1025 bytes          PlanException invalid_value option="where" field="v"
< bad UTF-8           PlanException invalid_value option="where" field="v"
>= surrogate bytes    PlanException invalid_value option="where" field="v"
> '' and < 'b'        ok
> 'a' and <= 2        PlanException invalid_value option="where" field="v"
