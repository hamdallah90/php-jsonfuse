--TEST--
jsonfuse: extension is loaded, reports itself and the pinned yyjson version in phpinfo
--EXTENSIONS--
jsonfuse
--FILE--
<?php
var_dump(extension_loaded('jsonfuse'));

$v = phpversion('jsonfuse');
var_dump(is_string($v) && $v !== '');

$ext = new ReflectionExtension('jsonfuse');
var_dump($ext->getName());

$classes = $ext->getClassNames();
foreach ([
    'JsonFuse\Plan', 'JsonFuse\Runner', 'JsonFuse\Stats',
    'JsonFuse\JsonFuseException', 'JsonFuse\PlanException',
    'JsonFuse\RecordException', 'JsonFuse\StreamException',
] as $c) {
    echo $c, ': ', in_array($c, $classes, true) ? 'registered' : 'MISSING', "\n";
}

// Same text as `php --ri jsonfuse`.
ob_start();
$ext->info();
$ri = ob_get_clean();
var_dump(str_contains($ri, 'jsonfuse'));
var_dump(preg_match('/^.*yyjson.*\b0\.13\.\d+\b.*$/mi', $ri) === 1);
?>
--EXPECT--
bool(true)
bool(true)
string(8) "jsonfuse"
JsonFuse\Plan: registered
JsonFuse\Runner: registered
JsonFuse\Stats: registered
JsonFuse\JsonFuseException: registered
JsonFuse\PlanException: registered
JsonFuse\RecordException: registered
JsonFuse\StreamException: registered
bool(true)
bool(true)
