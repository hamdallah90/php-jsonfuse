--TEST--
jsonfuse: the reader keeps reading at exactly maxRecordBytes + 2 buffered bytes without LF, and rejects at + 3
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

const CAP = 10;

/** Serves $served bytes with no LF, then the next stream_read throws. */
function probe(int $served): string
{
    $in = jf_wrap(str_repeat('a', 200000), $served, [
        'mode' => 'throw_read', 'after' => $served, 'exc' => new DomainException('next read reached'),
    ]);
    $out = jf_out();
    try {
        jf_stats(Runner::process($in, $out, jf_plan(['maxRecordBytes' => CAP])));
        $r = 'no exception';
    } catch (Throwable $e) {
        $r = get_class($e) . ' ' . jf_ctx($e->getMessage());
    }
    $r .= ' reads=' . JfWrap::$last->reads;
    fclose($in);
    fclose($out);
    return $r;
}

echo 'cap+2 buffered: ', probe(CAP + 2), "\n";
echo 'cap+3 buffered: ', probe(CAP + 3), "\n";
echo 'large single read: ', probe(CAP + 70000), "\n";
?>
--EXPECT--
cap+2 buffered: DomainException no-context reads=2
cap+3 buffered: JsonFuse\RecordException line=1 record=1 offset=0 reads=1
large single read: JsonFuse\RecordException line=1 record=1 offset=0 reads=1
