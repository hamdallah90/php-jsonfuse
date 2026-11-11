--TEST--
jsonfuse: a directory handle is refused deliberately, as output or input, and its handle is left untouched
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

$plan = jf_plan();
$dir = opendir(__DIR__);

foreach (['$output' => fn() => Runner::process(jf_mem("{}\n"), $dir, $plan),
          '$input' => fn() => Runner::process($dir, jf_out(), $plan)] as $name => $call) {
    try {
        $call();
        echo "$name: no exception\n";
    } catch (Throwable $e) {
        echo "$name: ", get_class($e), ': ', $e instanceof JsonFuse\JsonFuseException ? $e->getReason() : $e->getMessage(), "\n";
    }
}

// The guard runs before any flag is touched: PHP still refuses fclose() on the handle.
var_dump(@fclose($dir));
var_dump(is_string(readdir($dir)));
closedir($dir);
?>
--EXPECT--
$output: JsonFuse\StreamException: stream_in_use
$input: JsonFuse\StreamException: stream_in_use
bool(false)
bool(true)
