--TEST--
jsonfuse: Plan::compile UTF-8 validation at the overlong, lead-byte and continuation-byte boundaries
--EXTENSIONS--
jsonfuse
--FILE--
<?php
use JsonFuse\Plan;
use JsonFuse\PlanException;

function verdict(array $spec, string $option): string
{
    try {
        Plan::compile($spec);
        return 'accepted';
    } catch (PlanException $e) {
        return $e->getOption() === $option ? 'rejected' : 'rejected without naming option';
    }
}

$invalid = [
    'E0 80 80, overlong' => "\xE0\x80\x80",
    'E0 9F BF, overlong' => "\xE0\x9F\xBF",
    'F0 80 80 80, overlong' => "\xF0\x80\x80\x80",
    'F0 8F BF BF, overlong' => "\xF0\x8F\xBF\xBF",
    'F5 80 80 80, lead above F4' => "\xF5\x80\x80\x80",
    'F6 80 80 80, lead above F4' => "\xF6\x80\x80\x80",
    'F7 BF BF BF, lead above F4' => "\xF7\xBF\xBF\xBF",
    'E2 82 28, bad third byte' => "\xE2\x82(",
    'F0 9F 28 80, bad third byte' => "\xF0\x9F(\x80",
    'F0 9F 98 28, bad fourth byte' => "\xF0\x9F\x98(",
];
$valid = [
    'C2 80' => "\xC2\x80",
    'DF BF' => "\xDF\xBF",
    'E0 A0 80' => "\xE0\xA0\x80",
    'EF BF BF' => "\xEF\xBF\xBF",
    'F0 90 80 80' => "\xF0\x90\x80\x80",
    'F4 8F BF BF' => "\xF4\x8F\xBF\xBF",
];

$f = ['id' => 'int64'];
foreach ($invalid as $name => $s) {
    echo str_pad($name, 32), 'field name ', verdict(['fields' => [$s => 'int64']], 'fields'),
        ', where value ', verdict(['fields' => $f, 'where' => ['k' => $s]], 'where'), "\n";
}
foreach ($valid as $name => $s) {
    echo str_pad($name, 32), 'field name ', verdict(['fields' => [$s => 'int64']], 'fields'),
        ', where value ', verdict(['fields' => $f, 'where' => ['k' => $s]], 'where'), "\n";
}
?>
--EXPECT--
E0 80 80, overlong              field name rejected, where value rejected
E0 9F BF, overlong              field name rejected, where value rejected
F0 80 80 80, overlong           field name rejected, where value rejected
F0 8F BF BF, overlong           field name rejected, where value rejected
F5 80 80 80, lead above F4      field name rejected, where value rejected
F6 80 80 80, lead above F4      field name rejected, where value rejected
F7 BF BF BF, lead above F4      field name rejected, where value rejected
E2 82 28, bad third byte        field name rejected, where value rejected
F0 9F 28 80, bad third byte     field name rejected, where value rejected
F0 9F 98 28, bad fourth byte    field name rejected, where value rejected
C2 80                           field name accepted, where value accepted
DF BF                           field name accepted, where value accepted
E0 A0 80                        field name accepted, where value accepted
EF BF BF                        field name accepted, where value accepted
F0 90 80 80                     field name accepted, where value accepted
F4 8F BF BF                     field name accepted, where value accepted
