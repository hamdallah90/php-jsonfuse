--TEST--
jsonfuse: records written larger than their input fit the reservation at tiny record caps
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * SPEC.md section 6: the writer reserves rec_len + prefixes + 40 * fields + 2 bytes. A record whose output is
 * longer than its JSON text must still fit when maxRecordBytes equals the record, keeping the writer as small
 * as the spec allows. 2,000 records make any overrun of the small buffer visible.
 */
const N = 2000;

function check(string $label, array $fields, string $rec, string $expect): void
{
    $data = str_repeat($rec . "\n", N);
    $out = jf_out();
    $in = jf_mem($data);
    try {
        $s = Runner::process($in, $out, Plan::compile(['fields' => $fields, 'maxRecordBytes' => strlen($rec)]));
        $bytes = jf_out_bytes($out);
        echo str_pad($label, 34), jf_stats($s), ' ', $bytes === str_repeat($expect . "\n", N) ? 'exact' : 'WRONG ' . substr($bytes, 0, 80), "\n";
    } catch (Throwable $e) {
        echo str_pad($label, 34), get_class($e), ' ', $e instanceof JsonFuse\JsonFuseException ? $e->getReason() : $e->getMessage(), "\n";
    }
    fclose($in);
    fclose($out);
}

check('1e20 grows from 4 to 23 bytes', ['a' => 'float64'], '{"a":1e20}', '{"a":100000000000000000000.0}');
check('-1e20', ['a' => 'float64'], '{"a":-1e20}', '{"a":-100000000000000000000.0}');
check('5 grows to 5.0', ['a' => 'float64'], '{"a":5}', '{"a":5.0}');
check('negative zero', ['a' => 'float64'], '{"a":-0}', '{"a":0.0}');
check('longest shortest form', ['a' => 'float64'], '{"a":-1.7976931348623157e308}', '{"a":-1.7976931348623157e308}');
check('tiny float', ['a' => 'float64'], '{"a":5e-324}', '{"a":5e-324}');
check('int64 max', ['a' => 'int64'], '{"a":9223372036854775807}', '{"a":9223372036854775807}');
check('int64 min', ['a' => 'int64'], '{"a":-9223372036854775808}', '{"a":-9223372036854775808}');
check('three numbers grow', ['a' => 'float64', 'b' => 'float64', 'c' => 'float64'], '{"a":1e20,"b":1e20,"c":1e20}', '{"a":100000000000000000000.0,"b":100000000000000000000.0,"c":100000000000000000000.0}');
check('true and false', ['a' => 'bool', 'b' => 'bool'], '{"a":true,"b":false}', '{"a":true,"b":false}');
check('null in a nullable field', ['a' => 'float64?'], '{"a":null}', '{"a":null}');
check('RAW integer in a float field', ['a' => 'float64'], '{"a":18446744073709551616}', '{"a":18446744073709552000.0}');
check('control escapes at the cap', ['a' => 'string'], '{"a":"\u0001\u0002"}', '{"a":"\u0001\u0002"}');
check('wide name, number', ["nameofafield" => 'float64'], '{"nameofafield":1e20}', '{"nameofafield":100000000000000000000.0}');
?>
--EXPECT--
1e20 grows from 4 to 23 bytes     read=2000 matched=2000 emitted=2000 bytesRead=22000 bytesWritten=60000 exact
-1e20                             read=2000 matched=2000 emitted=2000 bytesRead=24000 bytesWritten=62000 exact
5 grows to 5.0                    read=2000 matched=2000 emitted=2000 bytesRead=16000 bytesWritten=20000 exact
negative zero                     read=2000 matched=2000 emitted=2000 bytesRead=18000 bytesWritten=20000 exact
longest shortest form             read=2000 matched=2000 emitted=2000 bytesRead=60000 bytesWritten=60000 exact
tiny float                        read=2000 matched=2000 emitted=2000 bytesRead=26000 bytesWritten=26000 exact
int64 max                         read=2000 matched=2000 emitted=2000 bytesRead=52000 bytesWritten=52000 exact
int64 min                         read=2000 matched=2000 emitted=2000 bytesRead=54000 bytesWritten=54000 exact
three numbers grow                read=2000 matched=2000 emitted=2000 bytesRead=58000 bytesWritten=172000 exact
true and false                    read=2000 matched=2000 emitted=2000 bytesRead=42000 bytesWritten=42000 exact
null in a nullable field          read=2000 matched=2000 emitted=2000 bytesRead=22000 bytesWritten=22000 exact
RAW integer in a float field      read=2000 matched=2000 emitted=2000 bytesRead=54000 bytesWritten=58000 exact
control escapes at the cap        read=2000 matched=2000 emitted=2000 bytesRead=42000 bytesWritten=42000 exact
wide name, number                 read=2000 matched=2000 emitted=2000 bytesRead=44000 bytesWritten=82000 exact
