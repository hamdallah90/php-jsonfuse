--TEST--
jsonfuse 1.0: typed validation failures report reason codes and the plan's own name in raw bytes, for every kind of wrong value
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\RecordException;
use JsonFuse\Runner;

function reason(string $record, array $spec): string
{
    $in = jf_mem($record . "\n");
    $out = jf_out();
    try {
        return 'no exception: ' . jf_stats(Runner::process($in, $out, jf_plan($spec)));
    } catch (RecordException $e) {
        return $e->getReason() . ' ' . jf10_q($e->getField()) . sprintf(' at=%d/%d/%d', $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset());
    } finally {
        fclose($in);
        fclose($out);
    }
}

function kinds(string $type, array $literals): void
{
    foreach ($literals as $literal) {
        $label = "$type vs " . (strlen($literal) > 30 ? substr($literal, 0, 12) . '...' : $literal);
        echo str_pad($label, 44), reason('{"v":' . $literal . '}', ['fields' => ['v' => $type]]), "\n";
    }
}

$d400 = str_repeat('9', 400);

echo "-- every wrong kind is type_mismatch\n";
kinds('int64', ['true', '1.5', '"x"', '[1]', '{}', '1e400', '18446744073709551616', '9223372036854775808', '18446744073709551615', '-9223372036854775809']);
kinds('int64?', ['"x"', '-1e400']);
kinds('float64', ['true', '"x"', '[]', '{}', '1e400', '-1e400', $d400]);
kinds('float64?', ['false', '1e400']);
kinds('bool', ['1', '-1', '18446744073709551615', '1.5', '"x"', '[]', '{}', '1e400', '18446744073709551616']);
kinds('bool?', ['0']);
kinds('string', ['1', '1.5', 'true', '[]', '{}', '1e400']);
kinds('string?', ['false']);

echo "-- null and missing\n";
foreach (['int64', 'float64', 'bool', 'string'] as $t) {
    echo str_pad("$t vs null", 44), reason('{"v":null}', ['fields' => ['v' => $t]]), "\n";
    echo str_pad("$t? vs missing", 44), reason('{"x":1}', ['fields' => ['v' => "$t?"]]), "\n";
}

echo "-- getField() returns the plan's own name bytes, also when the record escapes the key\n";
$name = "a\"b\\c\nd\te\x08\x0C\x0D/\x7F\u{E9}\u{2028}\x01\x0B\x1F\0z";
$rec = json_encode($name, JSON_THROW_ON_ERROR);
$cases = [
    'missing field' => ['{"other":1}', ['fields' => [$name => 'int64']]],
    'null field' => ['{' . $rec . ':null}', ['fields' => [$name => 'int64']]],
    'type mismatch' => ['{' . $rec . ':"s"}', ['fields' => [$name => 'int64']]],
    'duplicate key' => ['{' . $rec . ':1,' . $rec . ':2}', ['fields' => [$name => 'int64']]],
    'where not scalar' => ['{"id":1,' . $rec . ':[]}', ['fields' => ['id' => 'int64'], 'where' => [$name => 1]]],
];
foreach ($cases as $label => [$record, $spec]) {
    $in = jf_mem($record . "\n");
    try {
        Runner::process($in, jf_out(), jf_plan($spec));
        echo str_pad($label, 20), "no exception\n";
    } catch (RecordException $e) {
        echo str_pad($label, 20), $e->getReason(), ' ', var_export($e->getField() === $name, true), "\n";
    }
}
?>
--EXPECT--
-- every wrong kind is type_mismatch
int64 vs true                               type_mismatch "v" at=1/1/0
int64 vs 1.5                                type_mismatch "v" at=1/1/0
int64 vs "x"                                type_mismatch "v" at=1/1/0
int64 vs [1]                                type_mismatch "v" at=1/1/0
int64 vs {}                                 type_mismatch "v" at=1/1/0
int64 vs 1e400                              type_mismatch "v" at=1/1/0
int64 vs 18446744073709551616               type_mismatch "v" at=1/1/0
int64 vs 9223372036854775808                type_mismatch "v" at=1/1/0
int64 vs 18446744073709551615               type_mismatch "v" at=1/1/0
int64 vs -9223372036854775809               type_mismatch "v" at=1/1/0
int64? vs "x"                               type_mismatch "v" at=1/1/0
int64? vs -1e400                            type_mismatch "v" at=1/1/0
float64 vs true                             type_mismatch "v" at=1/1/0
float64 vs "x"                              type_mismatch "v" at=1/1/0
float64 vs []                               type_mismatch "v" at=1/1/0
float64 vs {}                               type_mismatch "v" at=1/1/0
float64 vs 1e400                            type_mismatch "v" at=1/1/0
float64 vs -1e400                           type_mismatch "v" at=1/1/0
float64 vs 999999999999...                  type_mismatch "v" at=1/1/0
float64? vs false                           type_mismatch "v" at=1/1/0
float64? vs 1e400                           type_mismatch "v" at=1/1/0
bool vs 1                                   type_mismatch "v" at=1/1/0
bool vs -1                                  type_mismatch "v" at=1/1/0
bool vs 18446744073709551615                type_mismatch "v" at=1/1/0
bool vs 1.5                                 type_mismatch "v" at=1/1/0
bool vs "x"                                 type_mismatch "v" at=1/1/0
bool vs []                                  type_mismatch "v" at=1/1/0
bool vs {}                                  type_mismatch "v" at=1/1/0
bool vs 1e400                               type_mismatch "v" at=1/1/0
bool vs 18446744073709551616                type_mismatch "v" at=1/1/0
bool? vs 0                                  type_mismatch "v" at=1/1/0
string vs 1                                 type_mismatch "v" at=1/1/0
string vs 1.5                               type_mismatch "v" at=1/1/0
string vs true                              type_mismatch "v" at=1/1/0
string vs []                                type_mismatch "v" at=1/1/0
string vs {}                                type_mismatch "v" at=1/1/0
string vs 1e400                             type_mismatch "v" at=1/1/0
string? vs false                            type_mismatch "v" at=1/1/0
-- null and missing
int64 vs null                               null_field "v" at=1/1/0
int64? vs missing                           missing_field "v" at=1/1/0
float64 vs null                             null_field "v" at=1/1/0
float64? vs missing                         missing_field "v" at=1/1/0
bool vs null                                null_field "v" at=1/1/0
bool? vs missing                            missing_field "v" at=1/1/0
string vs null                              null_field "v" at=1/1/0
string? vs missing                          missing_field "v" at=1/1/0
-- getField() returns the plan's own name bytes, also when the record escapes the key
missing field       missing_field true
null field          null_field true
type mismatch       type_mismatch true
duplicate key       duplicate_key true
where not scalar    where_not_scalar true
