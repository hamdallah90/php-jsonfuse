--TEST--
jsonfuse: a php_user_filter on $output cannot inflate bytesWritten by over-claiming, and its exceptions and failures surface unchanged
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\StreamException;

/* SPEC.md section 6: filters run PHP during php_stream_write and php_stream_flush. */
final class JfOutFilter extends php_user_filter
{
    public static string $mode = 'honest';
    public static int $failOn = 1;       // data call on which a failure mode fires
    public static int $dataCalls = 0;
    public static int $emptyCalls = 0;
    public static array $taken = [];     // bytes really taken per data call
    public static ?Throwable $thrown = null;

    public static function reset(string $mode, int $failOn = 1): void
    {
        self::$mode = $mode;
        self::$failOn = $failOn;
        self::$dataCalls = self::$emptyCalls = 0;
        self::$taken = [];
        self::$thrown = null;
    }

    private static function boom(): void
    {
        self::$thrown = new JfBoom('filter boom ' . self::$mode);
        self::$mode = 'honest';
        throw self::$thrown;
    }

    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $data = '';
        while (($bucket = stream_bucket_make_writeable($in)) !== null) {
            $data .= $bucket->data;
        }
        if ($data === '') {
            self::$emptyCalls++;
            if (self::$mode === 'throw_flush' && !$closing) {
                self::boom();
            }
            return PSFS_FEED_ME;
        }
        self::$dataCalls++;
        $bytes = $take = strlen($data);
        $fire = self::$dataCalls === self::$failOn;
        switch (self::$mode) {
            case 'over_small':
                $consumed += $bytes + 1000;
                break;
            case 'over_huge':
                $consumed = PHP_INT_MAX;
                break;
            case 'short_then_over':
                if ($fire) {
                    $take = intdiv($bytes, 2);
                    $consumed += $take;
                } else {
                    $consumed += $bytes + 1000;
                }
                break;
            case 'negative':
                $consumed = -1;
                break;
            case 'zero':
                break;
            case 'fatal':
                if ($fire) {
                    return PSFS_ERR_FATAL;
                }
                $consumed += $bytes;
                break;
            case 'throw_write':
                if ($fire) {
                    self::boom();
                }
                $consumed += $bytes;
                break;
            default:
                $consumed += $bytes;
        }
        self::$taken[] = $take;
        stream_bucket_append($out, stream_bucket_new($this->stream, strtoupper(substr($data, 0, $take))));
        return PSFS_PASS_ON;
    }
}

stream_filter_register('jfoutfilter', JfOutFilter::class);

function records(int $n): string
{
    $s = '';
    for ($i = 1; $i <= $n; $i++) {
        $s .= sprintf('{"id":%d,"s":"row-%05d","skip":[1,2,3]}' . "\n", $i, $i);
    }
    return $s;
}

function expected(int $n): string
{
    $s = '';
    for ($i = 1; $i <= $n; $i++) {
        $s .= sprintf('{"id":%d,"s":"row-%05d"}' . "\n", $i, $i);
    }
    return $s;
}

function report(string $label, string $mode, int $records, int $failOn = 1): void
{
    static $plan = null;
    $plan ??= Plan::compile(['fields' => ['id' => 'int64', 's' => 'string']]);
    JfOutFilter::reset($mode, $failOn);
    $in = jf_mem(records($records));
    $out = jf_out();
    $filter = stream_filter_append($out, 'jfoutfilter', STREAM_FILTER_WRITE);
    $want = strtoupper(expected($records));
    echo "-- $label\n";
    try {
        $s = Runner::process($in, $out, $plan);
        $bytes = jf_out_bytes($out);
        echo 'stats: ', jf_stats($s), "\n";
        echo 'bytesWritten equals the bytes the filter took: ', var_export($s->bytesWritten === array_sum(JfOutFilter::$taken), true), "\n";
        echo 'bytesWritten equals the output length: ', var_export($s->bytesWritten === strlen($want), true), "\n";
        echo 'output is exact: ', var_export($bytes === $want, true), "\n";
        echo 'flush reached the filter: ', var_export(JfOutFilter::$emptyCalls > 0, true), "\n";
    } catch (Throwable $e) {
        $bytes = jf_out_bytes($out);
        echo get_class($e), ': ', $e instanceof JsonFuse\RecordException || $e instanceof JsonFuse\StreamException
            ? sprintf('%s at=%d/%d/%d', $e->getReason(), $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset()) : $e->getMessage(), "\n";
        echo 'same object the filter threw: ', var_export($e === JfOutFilter::$thrown, true), "\n";
        echo 'previous: ', var_export($e->getPrevious() === null ? null : get_class($e->getPrevious()), true), "\n";
        echo 'data calls: ', JfOutFilter::$dataCalls, "\n";
        echo 'output is a whole-line prefix: ', var_export(str_starts_with($want, $bytes) && ($bytes === '' || $bytes[-1] === "\n"), true), "\n";
    }
    JfOutFilter::reset('honest');
    stream_filter_remove($filter);
    echo 'streams still open: ', var_export(is_resource($in) && is_resource($out), true), "\n";
    /* The run released both streams: a further run on them works and PHP may close them. */
    ftruncate($out, 0);
    rewind($out);
    rewind($in);
    $again = Runner::process($in, $out, $plan);
    echo 'a later run on the same streams: ', jf_stats($again), "\n";
    echo 'fclose: ', var_export(fclose($in) && fclose($out), true), "\n";
}

report('a filter that transforms and counts honestly', 'honest', 3);
report('over-claiming by 1000 on a single write', 'over_small', 3);
report('over-claiming across many batched writes', 'over_small', 4000);
report('claiming PHP_INT_MAX on every write', 'over_huge', 4000);
report('over-claiming the rest after a short write', 'short_then_over', 3);
report('a negative consumed count is a failed write', 'negative', 3);
report('a filter that never sets consumed is a failed write', 'zero', 3);
report('PSFS_ERR_FATAL with no exception', 'fatal', 3);
report('an exception on the first write', 'throw_write', 3);
report('an exception on a later batch keeps only earlier whole records', 'throw_write', 4000, 2);
report('an exception during the final flush', 'throw_flush', 3);
?>
--EXPECT--
-- a filter that transforms and counts honestly
stats: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
bytesWritten equals the bytes the filter took: true
bytesWritten equals the output length: true
output is exact: true
flush reached the filter: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- over-claiming by 1000 on a single write
stats: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
bytesWritten equals the bytes the filter took: true
bytesWritten equals the output length: true
output is exact: true
flush reached the filter: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- over-claiming across many batched writes
stats: read=4000 matched=4000 emitted=4000 bytesRead=170893 bytesWritten=110893
bytesWritten equals the bytes the filter took: true
bytesWritten equals the output length: true
output is exact: true
flush reached the filter: true
streams still open: true
a later run on the same streams: read=4000 matched=4000 emitted=4000 bytesRead=170893 bytesWritten=110893
fclose: true
-- claiming PHP_INT_MAX on every write
stats: read=4000 matched=4000 emitted=4000 bytesRead=170893 bytesWritten=110893
bytesWritten equals the bytes the filter took: true
bytesWritten equals the output length: true
output is exact: true
flush reached the filter: true
streams still open: true
a later run on the same streams: read=4000 matched=4000 emitted=4000 bytesRead=170893 bytesWritten=110893
fclose: true
-- over-claiming the rest after a short write
stats: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
bytesWritten equals the bytes the filter took: true
bytesWritten equals the output length: true
output is exact: true
flush reached the filter: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- a negative consumed count is a failed write
JsonFuse\StreamException: write_failed at=4/4/120
same object the filter threw: false
previous: NULL
data calls: 1
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- a filter that never sets consumed is a failed write
JsonFuse\StreamException: write_failed at=4/4/120
same object the filter threw: false
previous: NULL
data calls: 1
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- PSFS_ERR_FATAL with no exception
JsonFuse\StreamException: write_failed at=4/4/120
same object the filter threw: false
previous: NULL
data calls: 1
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- an exception on the first write
JfBoom: filter boom throw_write
same object the filter threw: true
previous: NULL
data calls: 1
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
-- an exception on a later batch keeps only earlier whole records
JfBoom: filter boom throw_write
same object the filter threw: true
previous: NULL
data calls: 2
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=4000 matched=4000 emitted=4000 bytesRead=170893 bytesWritten=110893
fclose: true
-- an exception during the final flush
JfBoom: filter boom throw_flush
same object the filter threw: true
previous: NULL
data calls: 1
output is a whole-line prefix: true
streams still open: true
a later run on the same streams: read=3 matched=3 emitted=3 bytesRead=120 bytesWritten=75
fclose: true
