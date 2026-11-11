--TEST--
jsonfuse 1.0: invalid plans raise PlanException with getOption() naming the option (null for an unknown top-level key) and messages that never echo plan values
--EXTENSIONS--
jsonfuse
--FILE--
<?php
use JsonFuse\Plan;
use JsonFuse\PlanException;

const MARK = 'LEAKMARK-7f3a91';

/**
 * Expect PlanException whose message names $option and contains none of $forbid.
 * $option === null skips the naming check (unknown key: no option to name).
 */
function bad(string $name, array $spec, ?string $option, array $forbid = [MARK]): void
{
    try {
        Plan::compile($spec);
        echo $name, ": FAIL no exception\n";
        return;
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (get_class($e) !== PlanException::class) {
            echo $name, ': FAIL class ', get_class($e), "\n";
            return;
        }
        if ($e->getOption() !== $option) {
            echo $name, ': FAIL getOption() is ', var_export($e->getOption(), true), "\n";
            return;
        }
        foreach ($forbid as $f) {
            if (str_contains($m, $f)) {
                echo $name, ': FAIL message echoes ', $f, "\n";
                return;
            }
        }
        echo $name, ": ok\n";
    }
}

$f = ['id' => 'int64'];

// unknown top-level key (the value must not be echoed)
bad('unknown key', ['fields' => $f, 'bogus' => MARK], null);
bad('unknown key, int key', ['fields' => $f, 5 => MARK], null);
bad('unknown key, case differs', ['fields' => $f, 'MaxRecordBytes' => 100], null);

// fields: missing / empty / non-array
bad('no fields', [], 'fields');
bad('only where', ['where' => ['a' => MARK]], 'fields');
bad('empty fields', ['fields' => []], 'fields');
bad('fields string', ['fields' => MARK], 'fields');
bad('fields int', ['fields' => 5], 'fields');
bad('fields null', ['fields' => null], 'fields');
bad('fields bool', ['fields' => true], 'fields');

// type strings
bad('type INT64', ['fields' => ['a' => 'INT64' . MARK]], 'fields');
bad('type INT64 plain', ['fields' => ['a' => 'INT64']], 'fields');
bad('type Int64', ['fields' => ['a' => 'Int64']], 'fields');
bad('type String', ['fields' => ['a' => 'String']], 'fields');
bad('type int64??', ['fields' => ['a' => 'int64??']], 'fields');
bad('type empty', ['fields' => ['a' => '']], 'fields');
bad('type only ?', ['fields' => ['a' => '?']], 'fields');
bad('type leading ?', ['fields' => ['a' => '?int64']], 'fields');
bad('type int', ['fields' => ['a' => 'int']], 'fields');
bad('type integer', ['fields' => ['a' => 'integer' . MARK]], 'fields');
bad('type float', ['fields' => ['a' => 'float']], 'fields');
bad('type boolean', ['fields' => ['a' => 'boolean']], 'fields');
bad('type trailing space', ['fields' => ['a' => 'int64 ']], 'fields');
bad('type leading space', ['fields' => ['a' => ' int64']], 'fields');
bad('type with NUL', ['fields' => ['a' => "int64\0"]], 'fields');
bad('type int value', ['fields' => ['a' => 5]], 'fields');
bad('type null', ['fields' => ['a' => null]], 'fields');
bad('type bool', ['fields' => ['a' => true]], 'fields');
bad('type array', ['fields' => ['a' => ['int64', MARK]]], 'fields');
bad('type later field bad', ['fields' => ['a' => 'int64', 'b' => 'string?', 'c' => 'nope' . MARK]], 'fields');

// field count and names
bad('129 fields', ['fields' => array_fill_keys(array_map(fn($i) => "f$i", range(1, 129)), 'int64')], 'fields');
bad('name 1025 bytes', ['fields' => [str_repeat('n', 1025) => 'int64']], 'fields');
bad('name 1026 multibyte bytes', ['fields' => [str_repeat("\u{e9}", 513) => 'int64']], 'fields');
bad('name invalid UTF-8 (C0 80)', ['fields' => ["a\xC0\x80" => 'int64']], 'fields');
bad('name invalid UTF-8 (lone continuation)', ['fields' => ["\x80" => 'int64']], 'fields');
bad('name invalid UTF-8 (surrogate)', ['fields' => ["\xED\xA0\x80" => 'int64']], 'fields');
bad('name invalid UTF-8 (truncated)', ['fields' => ["ab\xE2\x82" => 'int64']], 'fields');
bad('name invalid UTF-8 (> U+10FFFF)', ['fields' => ["\xF4\x90\x80\x80" => 'int64']], 'fields');

// where
bad('where string', ['fields' => $f, 'where' => MARK], 'where');
bad('where int', ['fields' => $f, 'where' => 1], 'where');
bad('where true', ['fields' => $f, 'where' => true], 'where');
bad('where value array', ['fields' => $f, 'where' => ['k' => [MARK]]], 'where');
bad('where value empty array', ['fields' => $f, 'where' => ['k' => []]], 'where');
bad('where value object', ['fields' => $f, 'where' => ['k' => (object)['p' => MARK]]], 'where');
bad('where value NAN', ['fields' => $f, 'where' => ['k' => NAN]], 'where');
bad('where value INF', ['fields' => $f, 'where' => ['k' => INF]], 'where');
bad('where value -INF', ['fields' => $f, 'where' => ['k' => -INF]], 'where');
bad('where later value bad', ['fields' => $f, 'where' => ['a' => 1, 'b' => 'x', 'c' => NAN]], 'where');
bad('17 where keys', ['fields' => $f, 'where' => array_fill_keys(array_map(fn($i) => "w$i", range(1, 17)), 1)], 'where');
bad('where string 1025 bytes', ['fields' => $f, 'where' => ['k' => str_repeat('v', 1025)]], 'where', [str_repeat('v', 64)]);
bad('where name 1025 bytes', ['fields' => $f, 'where' => [str_repeat('k', 1025) => 1]], 'where');
bad('where value invalid UTF-8 (C0 80)', ['fields' => $f, 'where' => ['k' => "x\xC0\x80" . MARK]], 'where');
bad('where value invalid UTF-8 (surrogate)', ['fields' => $f, 'where' => ['k' => "\xED\xA0\x80"]], 'where');
bad('where value invalid UTF-8 (truncated)', ['fields' => $f, 'where' => ['k' => "ab\xF0\x9F\x98"]], 'where');
bad('where value invalid UTF-8 (FF)', ['fields' => $f, 'where' => ['k' => "\xFF"]], 'where');
bad('where name invalid UTF-8', ['fields' => $f, 'where' => ["\xC0\x80" => 1]], 'where');

// output
bad('output json', ['fields' => $f, 'output' => 'json'], 'output');
bad('output empty', ['fields' => $f, 'output' => ''], 'output');
bad('output marker', ['fields' => $f, 'output' => MARK], 'output');
bad('output int', ['fields' => $f, 'output' => 5], 'output');
bad('output array', ['fields' => $f, 'output' => ['ndjson']], 'output');

// maxRecordBytes
bad('maxRecordBytes 0', ['fields' => $f, 'maxRecordBytes' => 0], 'maxRecordBytes');
bad('maxRecordBytes -1', ['fields' => $f, 'maxRecordBytes' => -1], 'maxRecordBytes');
bad('maxRecordBytes PHP_INT_MIN', ['fields' => $f, 'maxRecordBytes' => PHP_INT_MIN], 'maxRecordBytes');
bad('maxRecordBytes 67108865', ['fields' => $f, 'maxRecordBytes' => 67108865], 'maxRecordBytes', ['67108865']);
bad('maxRecordBytes PHP_INT_MAX', ['fields' => $f, 'maxRecordBytes' => PHP_INT_MAX], 'maxRecordBytes', [(string)PHP_INT_MAX]);
bad("maxRecordBytes '1'", ['fields' => $f, 'maxRecordBytes' => '1'], 'maxRecordBytes');
bad("maxRecordBytes '7654321'", ['fields' => $f, 'maxRecordBytes' => '7654321'], 'maxRecordBytes', ['7654321']);
bad('maxRecordBytes 1.0', ['fields' => $f, 'maxRecordBytes' => 1.0], 'maxRecordBytes');
bad('maxRecordBytes 4096.0', ['fields' => $f, 'maxRecordBytes' => 4096.0], 'maxRecordBytes');
bad('maxRecordBytes true', ['fields' => $f, 'maxRecordBytes' => true], 'maxRecordBytes');
bad('maxRecordBytes array', ['fields' => $f, 'maxRecordBytes' => [1]], 'maxRecordBytes');

// skipEmptyLines
bad('skipEmptyLines 1', ['fields' => $f, 'skipEmptyLines' => 1], 'skipEmptyLines');
bad('skipEmptyLines 0', ['fields' => $f, 'skipEmptyLines' => 0], 'skipEmptyLines');
bad('skipEmptyLines "true"', ['fields' => $f, 'skipEmptyLines' => 'true'], 'skipEmptyLines');
bad('skipEmptyLines marker', ['fields' => $f, 'skipEmptyLines' => MARK], 'skipEmptyLines');
bad('skipEmptyLines 1.0', ['fields' => $f, 'skipEmptyLines' => 1.0], 'skipEmptyLines');
bad('skipEmptyLines array', ['fields' => $f, 'skipEmptyLines' => []], 'skipEmptyLines');

// a failed compile leaves later compiles unaffected
$p = Plan::compile(['fields' => $f]);
echo get_class($p), "\n";
?>
--EXPECT--
unknown key: ok
unknown key, int key: ok
unknown key, case differs: ok
no fields: ok
only where: ok
empty fields: ok
fields string: ok
fields int: ok
fields null: ok
fields bool: ok
type INT64: ok
type INT64 plain: ok
type Int64: ok
type String: ok
type int64??: ok
type empty: ok
type only ?: ok
type leading ?: ok
type int: ok
type integer: ok
type float: ok
type boolean: ok
type trailing space: ok
type leading space: ok
type with NUL: ok
type int value: ok
type null: ok
type bool: ok
type array: ok
type later field bad: ok
129 fields: ok
name 1025 bytes: ok
name 1026 multibyte bytes: ok
name invalid UTF-8 (C0 80): ok
name invalid UTF-8 (lone continuation): ok
name invalid UTF-8 (surrogate): ok
name invalid UTF-8 (truncated): ok
name invalid UTF-8 (> U+10FFFF): ok
where string: ok
where int: ok
where true: ok
where value array: ok
where value empty array: ok
where value object: ok
where value NAN: ok
where value INF: ok
where value -INF: ok
where later value bad: ok
17 where keys: ok
where string 1025 bytes: ok
where name 1025 bytes: ok
where value invalid UTF-8 (C0 80): ok
where value invalid UTF-8 (surrogate): ok
where value invalid UTF-8 (truncated): ok
where value invalid UTF-8 (FF): ok
where name invalid UTF-8: ok
output json: ok
output empty: ok
output marker: ok
output int: ok
output array: ok
maxRecordBytes 0: ok
maxRecordBytes -1: ok
maxRecordBytes PHP_INT_MIN: ok
maxRecordBytes 67108865: ok
maxRecordBytes PHP_INT_MAX: ok
maxRecordBytes '1': ok
maxRecordBytes '7654321': ok
maxRecordBytes 1.0: ok
maxRecordBytes 4096.0: ok
maxRecordBytes true: ok
maxRecordBytes array: ok
skipEmptyLines 1: ok
skipEmptyLines 0: ok
skipEmptyLines "true": ok
skipEmptyLines marker: ok
skipEmptyLines 1.0: ok
skipEmptyLines array: ok
JsonFuse\Plan
