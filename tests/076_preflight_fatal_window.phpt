--TEST--
jsonfuse: at memory_limit 2M and 3M, records in the former fatal window raise RecordException, catchable and never fatal
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
// memory_limit is not enforced when the Zend allocator is disabled (ASan runs).
if (getenv('USE_ZEND_ALLOC') === '0') die('skip memory_limit is not enforced with USE_ZEND_ALLOC=0');
?>
--INI--
memory_limit=128M
--FILE--
<?php
use JsonFuse\Plan;
use JsonFuse\RecordException;
use JsonFuse\Runner;

$plan = Plan::compile(['fields' => ['k' => 'string']]);
$path = __DIR__ . '/076_record.tmp';

// The pool needs a contiguous run of pages: these sizes used to pass the
// preflight and then end in an uncatchable memory_limit fatal.
foreach (['2M', '3M'] as $limit) {
    foreach ([40000, 105000, 110000, 113000, 124000, 129000, 140000, 160000, 174000] as $size) {
        file_put_contents($path, '{"k":"' . str_repeat('a', $size - 8) . "\"}\n");
        ini_set('memory_limit', $limit);
        $in = fopen($path, 'rb');
        $out = fopen('php://memory', 'w+b');
        try {
            Runner::process($in, $out, $plan);
            $result = 'accepted';
        } catch (RecordException $e) {
            $result = 'RecordException';
        }
        fclose($in);
        fclose($out);
        ini_set('memory_limit', '128M');
        echo str_pad("$limit $size", 14), $result, "\n";
    }
}
unlink($path);
echo "no fatal\n";
?>
--CLEAN--
<?php
@unlink(__DIR__ . '/076_record.tmp');
?>
--EXPECT--
2M 40000      accepted
2M 105000     RecordException
2M 110000     RecordException
2M 113000     RecordException
2M 124000     RecordException
2M 129000     RecordException
2M 140000     RecordException
2M 160000     RecordException
2M 174000     RecordException
3M 40000      accepted
3M 105000     RecordException
3M 110000     RecordException
3M 113000     RecordException
3M 124000     RecordException
3M 129000     RecordException
3M 140000     RecordException
3M 160000     RecordException
3M 174000     RecordException
no fatal
