--TEST--
jsonfuse: Plan::compile accepts every valid spec form from SPEC.md section 5
--EXTENSIONS--
jsonfuse
--FILE--
<?php
use JsonFuse\Plan;

function ok(string $name, array $spec): void
{
    $before = serialize($spec);
    try {
        $p = Plan::compile($spec);
        echo $name, ': ', get_class($p), ' unchanged=', var_export($before === serialize($spec), true), "\n";
    } catch (Throwable $e) {
        echo $name, ': FAIL ', get_class($e), "\n";
    }
}

// minimal and full
ok('minimal', ['fields' => ['id' => 'int64']]);
ok('full', [
    'fields' => ['id' => 'int64', 'name' => 'string', 'active' => 'bool?'],
    'where' => ['kind' => 'customer'],
    'output' => 'ndjson',
    'maxRecordBytes' => 4_194_304,
    'skipEmptyLines' => false,
]);

// all type strings, nullable variants
foreach (['int64', 'float64', 'bool', 'string'] as $t) {
    ok("type $t", ['fields' => ['a' => $t]]);
    ok("type $t?", ['fields' => ['a' => $t . '?']]);
}
ok('all types ordered', ['fields' => ['z' => 'int64', 'y' => 'float64?', 'x' => 'bool', 'w' => 'string?']]);

// special names
ok('__proto__ field', ['fields' => ['__proto__' => 'string']]);
ok('__proto__ where', ['fields' => ['a' => 'int64'], 'where' => ['__proto__' => 'x']]);
ok('empty-name field', ['fields' => ['' => 'string']]);
ok('utf-8 name', ['fields' => ["caf\u{e9}\u{1F600}" => 'string'], 'where' => ["\u{e9}" => "\u{e9}"]]);
ok('name with NUL and quote', ['fields' => ["a\0b" => 'int64', 'q"uote\\' => 'string', "n\nl" => 'bool']]);

// numeric-string and integer keys
ok('int keys 123, -5', ['fields' => [123 => 'int64', -5 => 'string']]);
ok('numeric-string keys', ['fields' => ['123' => 'int64', '-5' => 'string', '0123' => 'bool', '-0' => 'int64', '1.5' => 'float64']]);
ok('list-style fields', ['fields' => ['int64', 'string?']]);
ok('PHP_INT_MAX/MIN keys', ['fields' => [PHP_INT_MAX => 'int64', PHP_INT_MIN => 'int64']]);
ok('int keys in where', ['fields' => ['a' => 'int64'], 'where' => [7 => 'x', -2 => 1, '0123' => true]]);
ok('int key 0 and string -0 are distinct', ['fields' => [0 => 'int64', '-0' => 'int64']]);

// limits at the boundary
ok('128 fields', ['fields' => array_fill_keys(array_map(fn($i) => "f$i", range(1, 128)), 'int64')]);
ok('name of 1024 bytes', ['fields' => [str_repeat('n', 1024) => 'int64']]);
ok('where string of 1024 bytes', ['fields' => ['a' => 'int64'], 'where' => ['k' => str_repeat('v', 1024)]]);
ok('where name of 1024 bytes', ['fields' => ['a' => 'int64'], 'where' => [str_repeat('k', 1024) => 1]]);
ok('name of 1024 multibyte bytes', ['fields' => [str_repeat("\u{e9}", 512) => 'int64']]);
ok('16 where keys', ['fields' => ['a' => 'int64'], 'where' => array_fill_keys(array_map(fn($i) => "w$i", range(1, 16)), 1)]);
ok('maxRecordBytes 1', ['fields' => ['a' => 'int64'], 'maxRecordBytes' => 1]);
ok('maxRecordBytes 67108864', ['fields' => ['a' => 'int64'], 'maxRecordBytes' => 67108864]);
ok('skipEmptyLines true', ['fields' => ['a' => 'int64'], 'skipEmptyLines' => true]);

// where value kinds
ok('where scalars', ['fields' => ['a' => 'int64'], 'where' => [
    's' => 'text', 'e' => '', 'i' => 5, 'neg' => -5, 'max' => PHP_INT_MAX, 'min' => PHP_INT_MIN,
    't' => true, 'f' => false, 'n' => null, 'fl' => 1.5, 'z' => -0.0, 'big' => 1e300,
]]);
ok('where empty array', ['fields' => ['a' => 'int64'], 'where' => []]);
ok('where equals a field name', ['fields' => ['a' => 'int64'], 'where' => ['a' => 5]]);

// PHP references inside the spec are dereferenced
$t = 'int64?';
$w = 'x';
ok('references', ['fields' => ['a' => &$t], 'where' => ['k' => &$w]]);

// compile does not return the same object twice
$a = Plan::compile(['fields' => ['id' => 'int64']]);
$b = Plan::compile(['fields' => ['id' => 'int64']]);
var_dump($a === $b);
?>
--EXPECT--
minimal: JsonFuse\Plan unchanged=true
full: JsonFuse\Plan unchanged=true
type int64: JsonFuse\Plan unchanged=true
type int64?: JsonFuse\Plan unchanged=true
type float64: JsonFuse\Plan unchanged=true
type float64?: JsonFuse\Plan unchanged=true
type bool: JsonFuse\Plan unchanged=true
type bool?: JsonFuse\Plan unchanged=true
type string: JsonFuse\Plan unchanged=true
type string?: JsonFuse\Plan unchanged=true
all types ordered: JsonFuse\Plan unchanged=true
__proto__ field: JsonFuse\Plan unchanged=true
__proto__ where: JsonFuse\Plan unchanged=true
empty-name field: JsonFuse\Plan unchanged=true
utf-8 name: JsonFuse\Plan unchanged=true
name with NUL and quote: JsonFuse\Plan unchanged=true
int keys 123, -5: JsonFuse\Plan unchanged=true
numeric-string keys: JsonFuse\Plan unchanged=true
list-style fields: JsonFuse\Plan unchanged=true
PHP_INT_MAX/MIN keys: JsonFuse\Plan unchanged=true
int keys in where: JsonFuse\Plan unchanged=true
int key 0 and string -0 are distinct: JsonFuse\Plan unchanged=true
128 fields: JsonFuse\Plan unchanged=true
name of 1024 bytes: JsonFuse\Plan unchanged=true
where string of 1024 bytes: JsonFuse\Plan unchanged=true
where name of 1024 bytes: JsonFuse\Plan unchanged=true
name of 1024 multibyte bytes: JsonFuse\Plan unchanged=true
16 where keys: JsonFuse\Plan unchanged=true
maxRecordBytes 1: JsonFuse\Plan unchanged=true
maxRecordBytes 67108864: JsonFuse\Plan unchanged=true
skipEmptyLines true: JsonFuse\Plan unchanged=true
where scalars: JsonFuse\Plan unchanged=true
where empty array: JsonFuse\Plan unchanged=true
where equals a field name: JsonFuse\Plan unchanged=true
references: JsonFuse\Plan unchanged=true
bool(false)
