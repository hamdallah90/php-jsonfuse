--TEST--
jsonfuse: NDJSON framing - LF/CRLF, EOF without LF, blank lines, bare CR, BOM, NUL, counters and error context
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

function t(string $name, string $data, array $planOver = []): void
{
    echo str_pad($name, 40), jf_run_str($data, $planOver), "\n";
}

// Every record must satisfy the default plan (an int64 field `id`); with no `where` every
// record matches, so matched = read. emitted = matched and bytesWritten is the length of the
// projected output, one `{"id":N}` line per record.
echo "-- success shapes\n";
t('empty input', '');
t('one record, LF', "{\"id\":1}\n");
t('one record, no LF', '{"id":1}');
t('one record, CRLF', "{\"id\":1}\r\n");
t('two records LF', "{\"id\":1,\"a\":1}\n{\"id\":2,\"b\":2}\n");
t('two records CRLF', "{\"id\":1,\"a\":1}\r\n{\"id\":2,\"b\":2}\r\n");
t('final record without LF', "{\"id\":1,\"a\":1}\n{\"id\":2,\"b\":2}");
t('final record CRLF no extra', "{\"id\":1}\r\n{\"id\":1}");
t('mixed LF and CRLF', "{\"id\":1}\n{\"id\":1}\r\n{\"id\":1}\n{\"id\":1}");
t('CR at EOF is trailing', "{\"id\":1}\r");
t('{} CR CR LF (CR is whitespace)', "{\"id\":1}\r\r\n");
t('CR between tokens', "{\"id\":\r1}\n");
t('CR inside whitespace run', "{ \r \"id\" \r : 1 \r }\r\n");
t('leading/trailing JSON whitespace', " \t{\"id\":1}\t \n");
t('NUL escape inside string', "{\"id\":1,\"a\":\"x\\u0000y\"}\n");
t('NUL escape in key', "{\"id\":1,\"\\u0000\":1}\n");

echo "-- many records\n";
$many = '';
for ($i = 0; $i < 5000; $i++) {
    $many .= "{\"n\":$i,\"s\":\"caf\u{e9} \u{1F600}\",\"id\":1}\n";
}
t('5000 records', $many);
t('5000 records, no final LF', substr($many, 0, -1));
t('5000 records CRLF', str_replace("\n", "\r\n", $many));

echo "-- blank lines\n";
t('lone LF', "\n");
t('lone CRLF', "\r\n");
t('blank between records', "{\"id\":1}\n\n{\"id\":1}\n");
t('blank at EOF', "{\"id\":1}\n\n");
t('blank first', "\n{}\n");
t('blank (CRLF) between', "{\"id\":1}\r\n\r\n{\"id\":1}\r\n");
t('skip: empty input', '', ['skipEmptyLines' => true]);
t('skip: lone LF', "\n", ['skipEmptyLines' => true]);
t('skip: lone CRLF', "\r\n", ['skipEmptyLines' => true]);
t('skip: blank between', "{\"id\":1}\n\n{\"id\":1}\n", ['skipEmptyLines' => true]);
t('skip: blanks everywhere', "\n\r\n{\"id\":1}\n\n\n{\"id\":1}\r\n\n", ['skipEmptyLines' => true]);
t('skip: blank at EOF', "{\"id\":1}\n\n", ['skipEmptyLines' => true]);
t('skip: line numbers count blanks', "{\"id\":1}\n\n[]\n", ['skipEmptyLines' => true]);
t('skip: record numbers skip blanks', "\n{\"id\":1}\n\n\n[]\n", ['skipEmptyLines' => true]);
t('skipEmptyLines false explicit', "{\"id\":1}\n\n{\"id\":1}\n", ['skipEmptyLines' => false]);

echo "-- whitespace-only lines are always invalid\n";
t('space line', " \n");
t('tab line', "\t\n");
t('space then CR LF', " \r\n");
t('CR only content then LF', "\r\r\n");
t('whitespace line after record', "{\"id\":1}\n \n{\"id\":1}\n");
t('skip: space line', " \n", ['skipEmptyLines' => true]);
t('skip: whitespace line after record', "{\"id\":1}\n\n \n", ['skipEmptyLines' => true]);

echo "-- bare CR\n";
t('CR-only file, two records', "{}\r{}\r");
t('CR-only file, no trailing', "{}\r{}");
t('CR inside string', "{\"a\":\"x\ry\"}\n");
t('CR inside key', "{\"a\ry\":1}\n");
t('CR then garbage', "{}\rx\n");

echo "-- BOM and NUL\n";
t('BOM on line 1', "\xEF\xBB\xBF{}\n");
t('BOM on line 2', "{\"id\":1}\n\xEF\xBB\xBF{}\n");
t('BOM alone', "\xEF\xBB\xBF\n");
t('BOM inside string is valid UTF-8', "{\"id\":1,\"a\":\"\xEF\xBB\xBF\"}\n");
t('NUL inside object', "{\0}\n");
t('NUL after record', "{}\0\n");
t('NUL after record, no LF', "{}\0");
t('NUL then more input', "{}\0{}\n");
t('NUL line only', "\0\n");
t('raw NUL inside string', "{\"a\":\"x\0y\"}\n");
t('raw NUL inside key', "{\"a\0\":1}\n");
t('NUL on line 2', "{\"id\":1}\n{\"a\":\"\0\"}\n");

echo "-- positions (line/record/offset) of the first failing line\n";
t('error on line 1', "[]\n");
t('error on line 3', "{\"id\":1}\n{\"id\":1,\"a\":1}\n[]\n");
t('error on line 3, CRLF offsets', "{\"id\":1}\r\n{\"id\":1,\"a\":1}\r\n[]\r\n");
t('error after blank, no skip', "{\"id\":1}\n\n[]\n");
t('error after long first line', str_repeat("{\"id\":1,\"k\":\"vvvvvvvv\"}\n", 3) . "{\"k\":\n");
t('error on last line without LF', "{\"id\":1}\n{\"id\":1}\n{\"a\"");

echo "-- chunk boundaries (multi-byte sequences straddling 8 KiB and 64 KiB)\n";
foreach ([8192, 65536] as $b) {
    foreach (range(0, 8) as $s) {
        // 4-, 3-, 2- and 4-byte characters placed so that one of them crosses byte $b
        $rec = '{"p":"' . str_repeat('x', $b - 10 + $s) . "\u{1F600}\u{20ac}\u{e9}\u{10FFFF}" . "\",\"id\":1}\n";
        $data = $rec . "{\"z\":1,\"id\":1}";
        echo str_pad("straddle $b shift $s", 40), jf_run_str($data), ' len=', strlen($data), "\n";
    }
}
// one big string full of 3-byte characters, so a character straddles every boundary
for ($shift = 0; $shift < 3; $shift++) {
    $rec = '{"s":"' . str_repeat('x', $shift) . str_repeat("\u{20ac}", 30000) . "\",\"id\":1}\n";
    echo str_pad("euro run shift $shift", 40), jf_run_str($rec), ' len=', strlen($rec), "\n";
}

echo "-- input position at call start\n";
$in = jf_mem("XXXXX{\"id\":1}\n{\"id\":1,\"a\":1}\n");
fseek($in, 5);
echo str_pad('offset 5 start', 40), jf_stats(Runner::process($in, jf_out(), jf_plan())), "\n";
$in = jf_mem("XXXXX{\"id\":1}\n[]\n");
fseek($in, 5);
try {
    Runner::process($in, jf_out(), jf_plan());
} catch (Throwable $e) {
    echo str_pad('offset 5 start, error', 40), get_class($e), ' ', jf_ctx($e->getMessage()), "\n";
}

echo "-- Stats object\n";
$s = Runner::process(jf_mem("{\"id\":1}\n{\"id\":1}\n"), jf_out(), jf_plan());
var_dump($s instanceof JsonFuse\Stats);
$c = clone $s;
echo 'clone: ', jf_stats($c), "\n";
$u = unserialize(serialize($s));
echo 'unserialized: ', get_class($u), ' ', jf_stats($u), "\n";
foreach (['recordsRead', 'recordsMatched', 'recordsEmitted', 'bytesRead', 'bytesWritten'] as $p) {
    try {
        $s->$p = 99;
        echo "$p: writable\n";
    } catch (Error $e) {
        echo "$p: ", get_class($e), "\n";
    }
}
try {
    $s->extra = 1;
    echo "dynamic property allowed\n";
} catch (Error $e) {
    echo 'dynamic property: ', get_class($e), "\n";
}
echo 'unchanged: ', jf_stats($s), "\n";
?>
--EXPECT--
-- success shapes
empty input                             read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0
one record, LF                          read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
one record, no LF                       read=1 matched=1 emitted=1 bytesRead=8 bytesWritten=9
one record, CRLF                        read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=9
two records LF                          read=2 matched=2 emitted=2 bytesRead=30 bytesWritten=18
two records CRLF                        read=2 matched=2 emitted=2 bytesRead=32 bytesWritten=18
final record without LF                 read=2 matched=2 emitted=2 bytesRead=29 bytesWritten=18
final record CRLF no extra              read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
mixed LF and CRLF                       read=4 matched=4 emitted=4 bytesRead=36 bytesWritten=36
CR at EOF is trailing                   read=1 matched=1 emitted=1 bytesRead=9 bytesWritten=9
{} CR CR LF (CR is whitespace)          read=1 matched=1 emitted=1 bytesRead=11 bytesWritten=9
CR between tokens                       read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=9
CR inside whitespace run                read=1 matched=1 emitted=1 bytesRead=20 bytesWritten=9
leading/trailing JSON whitespace        read=1 matched=1 emitted=1 bytesRead=13 bytesWritten=9
NUL escape inside string                read=1 matched=1 emitted=1 bytesRead=24 bytesWritten=9
NUL escape in key                       read=1 matched=1 emitted=1 bytesRead=20 bytesWritten=9
-- many records
5000 records                            read=5000 matched=5000 emitted=5000 bytesRead=173890 bytesWritten=45000
5000 records, no final LF               read=5000 matched=5000 emitted=5000 bytesRead=173889 bytesWritten=45000
5000 records CRLF                       read=5000 matched=5000 emitted=5000 bytesRead=178890 bytesWritten=45000
-- blank lines
lone LF                                 JsonFuse\RecordException line=1 record=1 offset=0
lone CRLF                               JsonFuse\RecordException line=1 record=1 offset=0
blank between records                   JsonFuse\RecordException line=2 record=2 offset=9
blank at EOF                            JsonFuse\RecordException line=2 record=2 offset=9
blank first                             JsonFuse\RecordException line=1 record=1 offset=0
blank (CRLF) between                    JsonFuse\RecordException line=2 record=2 offset=10
skip: empty input                       read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0
skip: lone LF                           read=0 matched=0 emitted=0 bytesRead=1 bytesWritten=0
skip: lone CRLF                         read=0 matched=0 emitted=0 bytesRead=2 bytesWritten=0
skip: blank between                     read=2 matched=2 emitted=2 bytesRead=19 bytesWritten=18
skip: blanks everywhere                 read=2 matched=2 emitted=2 bytesRead=25 bytesWritten=18
skip: blank at EOF                      read=1 matched=1 emitted=1 bytesRead=10 bytesWritten=9
skip: line numbers count blanks         JsonFuse\RecordException line=3 record=2 offset=10
skip: record numbers skip blanks        JsonFuse\RecordException line=5 record=2 offset=12
skipEmptyLines false explicit           JsonFuse\RecordException line=2 record=2 offset=9
-- whitespace-only lines are always invalid
space line                              JsonFuse\RecordException line=1 record=1 offset=0
tab line                                JsonFuse\RecordException line=1 record=1 offset=0
space then CR LF                        JsonFuse\RecordException line=1 record=1 offset=0
CR only content then LF                 JsonFuse\RecordException line=1 record=1 offset=0
whitespace line after record            JsonFuse\RecordException line=2 record=2 offset=9
skip: space line                        JsonFuse\RecordException line=1 record=1 offset=0
skip: whitespace line after record      JsonFuse\RecordException line=3 record=2 offset=10
-- bare CR
CR-only file, two records               JsonFuse\RecordException line=1 record=1 offset=0
CR-only file, no trailing               JsonFuse\RecordException line=1 record=1 offset=0
CR inside string                        JsonFuse\RecordException line=1 record=1 offset=0
CR inside key                           JsonFuse\RecordException line=1 record=1 offset=0
CR then garbage                         JsonFuse\RecordException line=1 record=1 offset=0
-- BOM and NUL
BOM on line 1                           JsonFuse\RecordException line=1 record=1 offset=0
BOM on line 2                           JsonFuse\RecordException line=2 record=2 offset=9
BOM alone                               JsonFuse\RecordException line=1 record=1 offset=0
BOM inside string is valid UTF-8        read=1 matched=1 emitted=1 bytesRead=19 bytesWritten=9
NUL inside object                       JsonFuse\RecordException line=1 record=1 offset=0
NUL after record                        JsonFuse\RecordException line=1 record=1 offset=0
NUL after record, no LF                 JsonFuse\RecordException line=1 record=1 offset=0
NUL then more input                     JsonFuse\RecordException line=1 record=1 offset=0
NUL line only                           JsonFuse\RecordException line=1 record=1 offset=0
raw NUL inside string                   JsonFuse\RecordException line=1 record=1 offset=0
raw NUL inside key                      JsonFuse\RecordException line=1 record=1 offset=0
NUL on line 2                           JsonFuse\RecordException line=2 record=2 offset=9
-- positions (line/record/offset) of the first failing line
error on line 1                         JsonFuse\RecordException line=1 record=1 offset=0
error on line 3                         JsonFuse\RecordException line=3 record=3 offset=24
error on line 3, CRLF offsets           JsonFuse\RecordException line=3 record=3 offset=26
error after blank, no skip              JsonFuse\RecordException line=2 record=2 offset=9
error after long first line             JsonFuse\RecordException line=4 record=4 offset=72
error on last line without LF           JsonFuse\RecordException line=3 record=3 offset=18
-- chunk boundaries (multi-byte sequences straddling 8 KiB and 64 KiB)
straddle 8192 shift 0                   read=2 matched=2 emitted=2 bytesRead=8225 bytesWritten=18 len=8225
straddle 8192 shift 1                   read=2 matched=2 emitted=2 bytesRead=8226 bytesWritten=18 len=8226
straddle 8192 shift 2                   read=2 matched=2 emitted=2 bytesRead=8227 bytesWritten=18 len=8227
straddle 8192 shift 3                   read=2 matched=2 emitted=2 bytesRead=8228 bytesWritten=18 len=8228
straddle 8192 shift 4                   read=2 matched=2 emitted=2 bytesRead=8229 bytesWritten=18 len=8229
straddle 8192 shift 5                   read=2 matched=2 emitted=2 bytesRead=8230 bytesWritten=18 len=8230
straddle 8192 shift 6                   read=2 matched=2 emitted=2 bytesRead=8231 bytesWritten=18 len=8231
straddle 8192 shift 7                   read=2 matched=2 emitted=2 bytesRead=8232 bytesWritten=18 len=8232
straddle 8192 shift 8                   read=2 matched=2 emitted=2 bytesRead=8233 bytesWritten=18 len=8233
straddle 65536 shift 0                  read=2 matched=2 emitted=2 bytesRead=65569 bytesWritten=18 len=65569
straddle 65536 shift 1                  read=2 matched=2 emitted=2 bytesRead=65570 bytesWritten=18 len=65570
straddle 65536 shift 2                  read=2 matched=2 emitted=2 bytesRead=65571 bytesWritten=18 len=65571
straddle 65536 shift 3                  read=2 matched=2 emitted=2 bytesRead=65572 bytesWritten=18 len=65572
straddle 65536 shift 4                  read=2 matched=2 emitted=2 bytesRead=65573 bytesWritten=18 len=65573
straddle 65536 shift 5                  read=2 matched=2 emitted=2 bytesRead=65574 bytesWritten=18 len=65574
straddle 65536 shift 6                  read=2 matched=2 emitted=2 bytesRead=65575 bytesWritten=18 len=65575
straddle 65536 shift 7                  read=2 matched=2 emitted=2 bytesRead=65576 bytesWritten=18 len=65576
straddle 65536 shift 8                  read=2 matched=2 emitted=2 bytesRead=65577 bytesWritten=18 len=65577
euro run shift 0                        read=1 matched=1 emitted=1 bytesRead=90016 bytesWritten=9 len=90016
euro run shift 1                        read=1 matched=1 emitted=1 bytesRead=90017 bytesWritten=9 len=90017
euro run shift 2                        read=1 matched=1 emitted=1 bytesRead=90018 bytesWritten=9 len=90018
-- input position at call start
offset 5 start                          read=2 matched=2 emitted=2 bytesRead=24 bytesWritten=18
offset 5 start, error                   JsonFuse\RecordException line=2 record=2 offset=9
-- Stats object
bool(true)
clone: read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
unserialized: JsonFuse\Stats read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
recordsRead: Error
recordsMatched: Error
recordsEmitted: Error
bytesRead: Error
bytesWritten: Error
dynamic property: Error
unchanged: read=2 matched=2 emitted=2 bytesRead=18 bytesWritten=18
