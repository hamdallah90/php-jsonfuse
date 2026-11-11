--TEST--
jsonfuse: unreferenced numbers of any magnitude, nesting depth 511 accepted / 512 rejected, duplicate unreferenced keys accepted
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\RecordException;

const M = 'SECRETMARK';

function plan(): array
{
    return ['fields' => ['keep' => 'int64']];
}

function run(string $name, string $line): void
{
    echo str_pad($name, 46), jf_run_str($line . "\n", plan()), "\n";
}

function run_bad(string $name, string $line, string $ctx = 'line=1 record=1 offset=0'): void
{
    echo str_pad($name, 46),
        jf_expect_error($line . "\n", RecordException::class, $ctx, [M], plan()), "\n";
}

$d400 = str_repeat('9', 400);

echo "-- numbers that overflow every native type are fine when unreferenced\n";
run('1e400', '{"keep":1,"a":1e400}');
run('-1e400', '{"keep":1,"a":-1e400}');
run('1E400', '{"keep":1,"a":1E400}');
run('1e+400', '{"keep":1,"a":1e+400}');
run('1.5e999999999', '{"keep":1,"a":1.5e999999999}');
run('1e-400', '{"keep":1,"a":1e-400}');
run('-1e-400', '{"keep":1,"a":-1e-400}');
run('400-digit integer', '{"keep":1,"a":' . $d400 . '}');
run('negative 400-digit integer', '{"keep":1,"a":-' . $d400 . '}');
run('400-digit fraction', '{"keep":1,"a":0.' . $d400 . '}');
run('400-digit integer with fraction', '{"keep":1,"a":' . $d400 . '.5}');
run('uint64 max + 1', '{"keep":1,"a":18446744073709551616}');
run('int64 min - 1', '{"keep":1,"a":-9223372036854775809}');
run('int64 max + 1', '{"keep":1,"a":9223372036854775808}');
run('uint64 max', '{"keep":1,"a":18446744073709551615}');
run('negative zero forms', '{"keep":1,"a":[-0,-0.0,-0e5,0e0,0.0e-0]}');
run('big numbers in nested containers', '{"keep":1,"a":{"b":[1e400,-1e400,' . $d400 . ',{"c":1e-400}]}}');
run('big numbers as unreferenced keys values', '{"x":1e400,"keep":1,"y":-' . $d400 . ',"z":1e-400}');
run('big number before the field', '{"a":1e400,"keep":1}');
run('big numbers CRLF', '{"keep":1,"a":1e400}' . "\r");

echo "-- nesting depth: root object is level 1\n";
function arrays(int $inner): string
{
    return '{"keep":1,"a":' . str_repeat('[', $inner) . '1' . str_repeat(']', $inner) . '}';
}
function objects(int $inner): string
{
    return '{"keep":1,"a":' . str_repeat('{"a":', $inner) . '1' . str_repeat('}', $inner) . '}';
}
function mixed(int $pairs, bool $extraArray): string
{
    $open = str_repeat('[{"a":', $pairs) . ($extraArray ? '[' : '') . '1' . ($extraArray ? ']' : '');
    return '{"keep":1,"a":' . $open . str_repeat('}]', $pairs) . '}';
}
run('511 levels: arrays', arrays(510));
run('511 levels: objects', objects(510));
run('511 levels: mixed', mixed(255, false));
run('510 levels: arrays', arrays(509));
run('1 level', '{"keep":1}');
run('2 levels', arrays(1));
run_bad('512 levels: arrays', arrays(511));
run_bad('512 levels: objects', objects(511));
run_bad('512 levels: mixed', mixed(255, true));
run_bad('513 levels: arrays', arrays(512));
run_bad('1000 levels: arrays', arrays(999));
run_bad('100000 levels: arrays', arrays(99999));
run_bad('100000 levels: objects', objects(99999));
run_bad('512 levels with marker at the bottom', '{"keep":1,"a":' . str_repeat('[', 511) . '"' . M . '"' . str_repeat(']', 511) . '}');
run_bad('512 levels, unterminated', '{"keep":1,"a":' . str_repeat('[', 511));
echo str_pad('511 levels on line 2 then 512 on line 3', 46),
    jf_expect_error(arrays(510) . "\n" . arrays(511) . "\n", RecordException::class,
        'line=2 record=2 offset=' . (strlen(arrays(510)) + 1), [M], plan()), "\n";
echo str_pad('511 levels, CRLF', 46), jf_run_str(arrays(510) . "\r\n", plan()), "\n";

echo "-- duplicate keys that are not referenced are accepted, never inspected\n";
run('duplicate unreferenced key', '{"keep":1,"a":1,"a":2}');
run('duplicate unreferenced key, different types', '{"keep":1,"a":1,"a":"x","a":null,"a":[1],"a":{}}');
run('duplicate unreferenced key, many', '{"keep":1' . str_repeat(',"a":1', 1000) . '}');
run('duplicate via escape: a vs \\u0061', '{"keep":1,"a":1,"\\u0061":2}');
run('duplicate via escape, both escaped', '{"keep":1,"\\u0061\\u0062":1,"\\u0061b":2,"ab":3}');
run('duplicate empty keys', '{"keep":1,"":1,"":2}');
run('duplicate keys in nested object', '{"keep":1,"o":{"a":1,"a":2,"b":{"c":1,"c":2}}}');
run('duplicate keys in array of objects', '{"keep":1,"l":[{"a":1,"a":2},{"b":1,"b":2}]}');
run('duplicate keys with a nested big number', '{"keep":1,"a":1e400,"a":' . $d400 . '}');
run('duplicate keys non-adjacent', '{"a":1,"keep":1,"b":2,"a":3,"b":4}');
run('duplicate keys differing only in case', '{"keep":1,"a":1,"A":2}');
run('duplicate unreferenced key with UTF-8', "{\"keep\":1,\"caf\xC3\xA9\":1,\"caf\\u00e9\":2}");
run('same unreferenced key on two records', "{\"keep\":1,\"a\":1}\n{\"keep\":2,\"a\":2}");
?>
--EXPECT--
-- numbers that overflow every native type are fine when unreferenced
1e400                                         read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=11
-1e400                                        read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=11
1E400                                         read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=11
1e+400                                        read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=11
1.5e999999999                                 read=1 matched=1 emitted=1 bytesRead=29 bytesWritten=11
1e-400                                        read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=11
-1e-400                                       read=1 matched=1 emitted=1 bytesRead=23 bytesWritten=11
400-digit integer                             read=1 matched=1 emitted=1 bytesRead=416 bytesWritten=11
negative 400-digit integer                    read=1 matched=1 emitted=1 bytesRead=417 bytesWritten=11
400-digit fraction                            read=1 matched=1 emitted=1 bytesRead=418 bytesWritten=11
400-digit integer with fraction               read=1 matched=1 emitted=1 bytesRead=418 bytesWritten=11
uint64 max + 1                                read=1 matched=1 emitted=1 bytesRead=36 bytesWritten=11
int64 min - 1                                 read=1 matched=1 emitted=1 bytesRead=36 bytesWritten=11
int64 max + 1                                 read=1 matched=1 emitted=1 bytesRead=35 bytesWritten=11
uint64 max                                    read=1 matched=1 emitted=1 bytesRead=36 bytesWritten=11
negative zero forms                           read=1 matched=1 emitted=1 bytesRead=41 bytesWritten=11
big numbers in nested containers              read=1 matched=1 emitted=1 bytesRead=450 bytesWritten=11
big numbers as unreferenced keys values       read=1 matched=1 emitted=1 bytesRead=438 bytesWritten=11
big number before the field                   read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=11
big numbers CRLF                              read=1 matched=1 emitted=1 bytesRead=22 bytesWritten=11
-- nesting depth: root object is level 1
511 levels: arrays                            read=1 matched=1 emitted=1 bytesRead=1037 bytesWritten=11
511 levels: objects                           read=1 matched=1 emitted=1 bytesRead=3077 bytesWritten=11
511 levels: mixed                             read=1 matched=1 emitted=1 bytesRead=2057 bytesWritten=11
510 levels: arrays                            read=1 matched=1 emitted=1 bytesRead=1035 bytesWritten=11
1 level                                       read=1 matched=1 emitted=1 bytesRead=11 bytesWritten=11
2 levels                                      read=1 matched=1 emitted=1 bytesRead=19 bytesWritten=11
512 levels: arrays                            ok
512 levels: objects                           ok
512 levels: mixed                             ok
513 levels: arrays                            ok
1000 levels: arrays                           ok
100000 levels: arrays                         ok
100000 levels: objects                        ok
512 levels with marker at the bottom          ok
512 levels, unterminated                      ok
511 levels on line 2 then 512 on line 3       ok
511 levels, CRLF                              read=1 matched=1 emitted=1 bytesRead=1038 bytesWritten=11
-- duplicate keys that are not referenced are accepted, never inspected
duplicate unreferenced key                    read=1 matched=1 emitted=1 bytesRead=23 bytesWritten=11
duplicate unreferenced key, different types   read=1 matched=1 emitted=1 bytesRead=49 bytesWritten=11
duplicate unreferenced key, many              read=1 matched=1 emitted=1 bytesRead=6011 bytesWritten=11
duplicate via escape: a vs \u0061             read=1 matched=1 emitted=1 bytesRead=28 bytesWritten=11
duplicate via escape, both escaped            read=1 matched=1 emitted=1 bytesRead=47 bytesWritten=11
duplicate empty keys                          read=1 matched=1 emitted=1 bytesRead=21 bytesWritten=11
duplicate keys in nested object               read=1 matched=1 emitted=1 bytesRead=47 bytesWritten=11
duplicate keys in array of objects            read=1 matched=1 emitted=1 bytesRead=45 bytesWritten=11
duplicate keys with a nested big number       read=1 matched=1 emitted=1 bytesRead=426 bytesWritten=11
duplicate keys non-adjacent                   read=1 matched=1 emitted=1 bytesRead=35 bytesWritten=11
duplicate keys differing only in case         read=1 matched=1 emitted=1 bytesRead=23 bytesWritten=11
duplicate unreferenced key with UTF-8         read=1 matched=1 emitted=1 bytesRead=35 bytesWritten=11
same unreferenced key on two records          read=2 matched=2 emitted=2 bytesRead=34 bytesWritten=22
