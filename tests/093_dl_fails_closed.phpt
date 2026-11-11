--TEST--
jsonfuse: loaded by dl(), which registers no fiber-switch observer, every run leaves both stream flags set (fail closed)
--EXTENSIONS--
jsonfuse
--SKIPIF--
<?php
if (!function_exists('dl')) die('skip dl() is not available');
if (getenv('TEST_PHP_EXECUTABLE') === false) die('skip TEST_PHP_EXECUTABLE is not set');
?>
--FILE--
<?php
// This process loaded jsonfuse at startup; a child without -d extension loads it with dl().
$child = <<<'PHP'
var_dump(extension_loaded('jsonfuse'));
var_dump(dl('jsonfuse.' . PHP_SHLIB_SUFFIX));
$in = fopen('php://memory', 'w+b');
fwrite($in, "{\"id\":1}\n{\"id\":2}\n");
rewind($in);
$out = fopen('php://memory', 'w+b');
$s = JsonFuse\Runner::process($in, $out, JsonFuse\Plan::compile(['fields' => ['id' => 'int64']]));
echo 'emitted ', $s->recordsEmitted, "\n";
// No fiber ran, but without the observer the run cannot know that.
var_dump(@fclose($in), @fclose($out));
echo "done\n";
PHP;
$cmd = escapeshellarg(getenv('TEST_PHP_EXECUTABLE')) . ' -n -d enable_dl=1'
    . ' -d extension_dir=' . escapeshellarg(ini_get('extension_dir'))
    . ' -r ' . escapeshellarg($child) . ' 2>&1';
passthru($cmd, $status);
echo 'exit ', $status, "\n";
?>
--EXPECT--
bool(false)
bool(true)
emitted 2
bool(false)
bool(false)
done
exit 0
