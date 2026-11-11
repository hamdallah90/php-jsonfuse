--TEST--
jsonfuse: a fatal error (bailout) inside a stream callback restores NO_FCLOSE and frees per-run state
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

// A compile error longjmps through Runner::process (a memory_limit error can
// instead arrive as an UnwindExit exception, which is not a bailout).
$in = jf_wrap("{\"id\":1}\n{\"id\":2}\n", 7, ['on_read' => function (int $n) {
    if ($n === 2) {
        eval('function jf_dup_fn() {} function jf_dup_fn() {}');
    }
}]);
$out = jf_out();

register_shutdown_function(function () use ($in, $out) {
    var_dump(fclose($in), fclose($out));
    echo "shutdown done\n";
});

Runner::process($in, $out, jf_plan());
echo "not reached\n";
?>
--EXPECTF--

Fatal error: %s in %s on line %d
bool(true)
bool(true)
shutdown done
