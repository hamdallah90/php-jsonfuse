--TEST--
jsonfuse 1.0: PIE metadata declares ZTS support and the linux and darwin OS families, and keeps the PHP bound below 8.7
--EXTENSIONS--
jsonfuse
--FILE--
<?php
$c = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true, 512, JSON_THROW_ON_ERROR);
var_dump($c['type'], $c['php-ext']['extension-name'], $c['php-ext']['support-zts']);
$os = $c['php-ext']['os-families'];
sort($os);
echo implode(',', $os), "\n";
echo $c['require']['php'], "\n";
?>
--EXPECT--
string(7) "php-ext"
string(8) "jsonfuse"
bool(true)
darwin,linux
>=8.3 <8.7
