--TEST--
jsonfuse 1.0: two fields or two where entries named by the string key "1" and the int key 1 raise PlanException duplicate_name
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

use JsonFuse\Plan;
use JsonFuse\PlanException;
use JsonFuse\Runner;

/*
 * A PHP array can hold the string key "1" and the int key 1 at once: compact() and
 * get_defined_vars() keep a variable named "1" as a string key, and a later $a[1] adds
 * the int key. Both resolve to the name "1". A plan that accepted them would project one
 * value twice, so the record below is long enough that a second copy cannot fit in the
 * room reserved for one.
 */
function try_plan(string $title, array $spec, int $len = 100000): void
{
    echo "-- $title\n";
    try {
        $plan = Plan::compile($spec);
    } catch (PlanException $e) {
        echo jf10_exc($e), "\n";
        return;
    }
    $data = '{"1":"' . str_repeat('x', $len) . "\"}\n";
    $in = jf_mem($data);
    $out = jf_out();
    $s = Runner::process($in, $out, $plan);
    rewind($out);
    echo 'compiled, emitted ', $s->recordsEmitted, ', output equals input: ',
        var_export(stream_get_contents($out) === $data, true), "\n";
}

function where_keys(): array
{
    ${'1'} = 'x';
    $w = get_defined_vars();
    $w[1] = 'x';
    return $w;
}

${'1'} = 'string';
$fields = compact('1');
$fields[1] = 'string';
echo 'fields key types: ', implode(', ', array_map('gettype', array_keys($fields))), "\n";
try_plan('fields: string key "1" and int key 1', ['fields' => $fields]);

$where = where_keys();
echo 'where key types: ', implode(', ', array_map('gettype', array_keys($where))), "\n";
try_plan('where: string key "1" and int key 1', ['fields' => ['1' => 'string'], 'where' => $where]);

try_plan('fields: string key "1" alone', ['fields' => compact('1')]);
try_plan('fields: int key 1 alone', ['fields' => [1 => 'string']]);
try_plan('the same name in fields and where', ['fields' => compact('1'), 'where' => [1 => str_repeat('x', 1000)]], 1000);
?>
--EXPECT--
fields key types: string, integer
-- fields: string key "1" and int key 1
PlanException duplicate_name option="fields" field="1"
where key types: string, integer
-- where: string key "1" and int key 1
PlanException duplicate_name option="where" field="1"
-- fields: string key "1" alone
compiled, emitted 1, output equals input: true
-- fields: int key 1 alone
compiled, emitted 1, output equals input: true
-- the same name in fields and where
compiled, emitted 1, output equals input: true
