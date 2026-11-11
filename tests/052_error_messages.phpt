--TEST--
jsonfuse 1.0: messages are informational but never contain record bytes or raw control bytes, and plan messages never echo plan values
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\JsonFuseException;
use JsonFuse\Plan;
use JsonFuse\Runner;

const SECRET = 'S3cr3tZq';

function verdict(Throwable $e, array $forbid): string
{
    if (!$e instanceof JsonFuseException) {
        return 'FAIL ' . get_class($e);
    }
    $m = $e->getMessage();
    foreach ($forbid as $f) {
        if (str_contains($m, $f)) {
            return $e->getReason() . ' FAIL message contains ' . json_encode($f);
        }
    }
    if (!preg_match('//u', $m)) {
        return $e->getReason() . ' FAIL message is not UTF-8';
    }
    if (preg_match('/[\x00-\x1F]/', $m)) {
        return $e->getReason() . ' FAIL message holds a raw control byte';
    }
    return $e->getReason() . ' ok';
}

function rec(string $record, array $spec = [], array $forbid = [SECRET]): void
{
    $in = jf_mem($record . "\n");
    try {
        Runner::process($in, jf_out(), Plan::compile($spec + ['fields' => ['id' => 'int64']]));
        echo "no exception\n";
    } catch (Throwable $e) {
        echo verdict($e, $forbid), "\n";
    }
}

function plan(array $spec, array $forbid): void
{
    try {
        Plan::compile($spec);
        echo "no exception\n";
    } catch (Throwable $e) {
        echo verdict($e, $forbid), "\n";
    }
}

$s = SECRET;
$w = ['where' => ['w' => 'x']];
echo "-- record messages carry no record bytes\n";
rec("{\"id\":1,\"$s\":}");
rec("{\"id\":1,\"id\":\"$s\"}");
rec("{\"$s\":1}");
rec("{\"id\":null,\"$s\":1}");
rec("{\"id\":\"$s\"}");
rec("{\"id\":1,\"w\":[\"$s\"]}", $w);
rec("[\"$s\"]");
rec('{"id":1,"k":"' . str_repeat($s, 200) . '"}', ['maxRecordBytes' => 64]);
rec("{\"id\":1,\"k\":\"$s\xff\"}");
rec("{\"id\":$s}");

echo "-- names are escaped, so control bytes from plan names never reach a message\n";
$name = "n\n\r\x01\x1b[2J\x7F";
rec('{"x":1}', ['fields' => [$name => 'int64']], []);
rec('{"id":1,' . json_encode($name) . ':{}}', ['where' => [$name => 1]], []);

echo "-- plan messages never echo plan values\n";
plan(['fields' => ['id' => 'int64'], 'where' => ['w' => "$s\xff"]], [$s]);
plan(['fields' => ['id' => 'int64'], 'where' => ['w' => str_repeat($s, 200)]], [$s]);
plan(['fields' => ['id' => $s]], [$s]);
plan(['fields' => ['id' => 'int64'], 'output' => $s], [$s]);
plan(['fields' => ['id' => 'int64'], 'maxRecordBytes' => 98765432109], ['98765432109']);
plan(['fields' => ['id' => 'int64'], 'skipEmptyLines' => $s], [$s]);
?>
--EXPECT--
-- record messages carry no record bytes
invalid_json ok
duplicate_key ok
missing_field ok
null_field ok
type_mismatch ok
where_not_scalar ok
not_object ok
record_too_large ok
invalid_json ok
invalid_json ok
-- names are escaped, so control bytes from plan names never reach a message
missing_field ok
where_not_scalar ok
-- plan messages never echo plan values
invalid_value ok
invalid_value ok
invalid_type ok
invalid_option ok
invalid_option ok
invalid_option ok
