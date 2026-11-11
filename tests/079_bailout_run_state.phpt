--TEST--
jsonfuse 1.0: a bailout inside the flush-before-throw write, after the per-run buffers have grown, still releases the run (flags cleared, no leak; regression test for the zend_try moved out of Runner::process)
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * Everything the catch path of the guarded run releases changes after its setjmp:
 * the reader buffer grows (erealloc), the parser pool and the writer buffer are
 * replaced, a failure message is allocated and moved aside for the flush, and the
 * flags are set. The bailout then comes from the output wrapper during that flush.
 * The catch path must see the run as it is at the longjmp: the shutdown function
 * closes both streams (flags cleared), and the leak checks of the debug and ASan
 * lanes see every buffer freed. The zend_try now lives outside Runner::process
 * because a local changed between setjmp and longjmp is indeterminate (C11 7.13.2.1).
 */
$big = '{"s":"' . str_repeat('b', 300000) . "\"}\n";
// The writer grows to fit $big exactly, so the bad record that follows is the next thing it would write after.
$in = jf_wrap('{"s":"a"}' . "\n" . $big . '{"s":1}' . "\n", 65536);
$out = jf_sink(['on_write' => function (int $n) {
    // Write 1 drains the small record before the writer grows; write 2 is the flush after the type error.
    echo "write $n\n";
    if ($n === 2) {
        eval('function jf_dup_fn79() {} function jf_dup_fn79() {}');
    }
}]);

/*
 * The indeterminate state is not observable with the gcc and clang builds this suite
 * runs on, because run's address escapes and its fields stay in memory, so the
 * structure that moved the zend_try is also checked directly in the source.
 */
$src = file_get_contents(__DIR__ . '/../src/jf_runner.c');
preg_match('/^ZEND_METHOD\(JsonFuse_Runner, process\)\n\{\n(.*?)\n\}\n/ms', $src, $process);
preg_match('/^static void jf_run_guarded\(jf_run \*run\)\n\{\n(.*?)\n\}\n/ms', $src, $guarded);
echo 'process() has no setjmp of its own: ', var_export(isset($process[1]) && !str_contains($process[1], 'zend_try'), true), "\n";
echo 'process() runs through jf_run_guarded(&run): ', var_export(isset($process[1]) && str_contains($process[1], 'jf_run_guarded(&run);'), true), "\n";
echo 'jf_run_guarded() holds the zend_try and releases through its pointer: ',
    var_export(isset($guarded[1]) && str_contains($guarded[1], 'zend_try') && str_contains($guarded[1], 'jf_run_release(run);'), true), "\n";

register_shutdown_function(function () use ($in, $out) {
    echo 'writes: ', JfSink::$last->writes, "\n";
    var_dump(fclose($in), fclose($out));
    echo "shutdown done\n";
});

Runner::process($in, $out, Plan::compile(['fields' => ['s' => 'string']]));
echo "not reached\n";
?>
--EXPECTF--
process() has no setjmp of its own: true
process() runs through jf_run_guarded(&run): true
jf_run_guarded() holds the zend_try and releases through its pointer: true
write 1
write 2

Fatal error: Cannot redeclare %s in %s on line %d
writes: 2
bool(true)
bool(true)
shutdown done
