--TEST--
jsonfuse: a RAW number never matches a where value, including the zero-like values a typed getter would return for it
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

// SPEC section 5: "A present scalar of another type, or a RAW number, does not match and is not
// an error." yyjson's typed getters return 0, 0.0 and false for a value of another type, so the
// filters that hold exactly those values are the ones a missing type check would satisfy.
$d400 = str_repeat('9', 400);
$raws = ['1e400', '-1e400', $d400, '-' . $d400, '18446744073709551616', '-9223372036854775809'];
$filters = ['int 0' => 0, 'float 0.0' => 0.0, 'float -0.0' => -0.0, 'false' => false, 'null' => null, 'empty string' => ''];

$bad = 0;
foreach ($filters as $fname => $want) {
    foreach ($raws as $raw) {
        $got = jf_v('{"id":1,"w":' . $raw . '}', ['where' => ['w' => $want]]);
        if ($got !== 'nomatch') {
            echo "RAW ", substr($raw, 0, 24), " against $fname: $got\n";
            $bad++;
        }
    }
}
echo "RAW against zero-like filters, wrong verdicts: $bad\n";

// Controls: each filter does match its own kind, so "nomatch" above is not vacuous.
foreach ([
    ['int 0', 0, '0'], ['int 0 and -0', 0, '-0'], ['float 0.0', 0.0, '0.0'], ['float 0.0 and -0.0', 0.0, '-0.0'],
    ['float -0.0 and 0e5', -0.0, '0e5'], ['false', false, 'false'], ['null', null, 'null'], ['empty string', '', '""'],
] as [$label, $want, $literal]) {
    echo str_pad("control: $label", 30), jf_v('{"id":1,"w":' . $literal . '}', ['where' => ['w' => $want]]), "\n";
}
?>
--EXPECT--
RAW against zero-like filters, wrong verdicts: 0
control: int 0                match
control: int 0 and -0         match
control: float 0.0            match
control: float 0.0 and -0.0   match
control: float -0.0 and 0e5   match
control: false                match
control: null                 match
control: empty string         match
