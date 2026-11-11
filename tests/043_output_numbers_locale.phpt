--TEST--
jsonfuse: number output is the same under a locale whose decimal separator is a comma
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
$found = false;
foreach (['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR', 'nl_NL.UTF-8', 'it_IT.UTF-8', 'es_ES.UTF-8', 'pt_BR.UTF-8', 'ru_RU.UTF-8', 'pl_PL.UTF-8', 'sv_SE.UTF-8'] as $l) {
    if (setlocale(LC_ALL, $l) !== false && (localeconv()['decimal_point'] ?? '.') === ',') {
        $found = true;
        break;
    }
}
setlocale(LC_ALL, 'C');
if (!$found) {
    die('skip no installed locale with a comma as decimal separator');
}
?>
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

$lits = ['0', '5', '-0', '1.0', '1e3', '-0.0', '1e-400', '5e-324', '100000000000000000000', '1e21', '1.7976931348623157e308', '0.1', '1.5', '123456.789',
    '0.30000000000000004', '9007199254740993', '9223372036854775807', '18446744073709551616', '2.5e-5', '1e-7', '123456789012345678901234567890'];

function lines(array $lits, string $type): string
{
    $in = jf_mem(implode('', array_map(fn($l) => '{"f":' . $l . "}\n", $lits)));
    $out = jf_out();
    $s = Runner::process($in, $out, Plan::compile(['fields' => ['f' => $type]]));
    $bytes = jf_out_bytes($out);
    fclose($in);
    fclose($out);
    return $bytes . jf_stats($s);
}

setlocale(LC_ALL, 'C');
$ref = lines($lits, 'float64');
$refInt = lines(['0', '-0', '5', '-5', '9223372036854775807', '-9223372036854775808'], 'int64');
echo 'C locale output has no comma at all: ', var_export(!str_contains($ref, ','), true), "\n";

$tested = 0;
$bad = [];
foreach (['de_DE.UTF-8', 'de_DE.utf8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR.utf8', 'fr_FR', 'nl_NL.UTF-8', 'it_IT.UTF-8', 'es_ES.UTF-8', 'pt_BR.UTF-8', 'ru_RU.UTF-8', 'pl_PL.UTF-8', 'sv_SE.UTF-8'] as $l) {
    foreach ([LC_ALL, LC_NUMERIC] as $cat) {
        if (setlocale($cat, $l) === false || (localeconv()['decimal_point'] ?? '.') !== ',') {
            setlocale(LC_ALL, 'C');
            continue;
        }
        $tested++;
        if (lines($lits, 'float64') !== $ref || lines(['0', '-0', '5', '-5', '9223372036854775807', '-9223372036854775808'], 'int64') !== $refInt) {
            $bad[] = "$l/$cat";
        }
        setlocale(LC_ALL, 'C');
    }
}
echo 'a comma locale was exercised: ', var_export($tested > 0, true), "\n";
echo 'locales whose output differs from the C locale: ', $bad ? implode(' ', $bad) : 'none', "\n";
?>
--EXPECT--
C locale output has no comma at all: true
a comma locale was exercised: true
locales whose output differs from the C locale: none
