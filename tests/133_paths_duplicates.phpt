--TEST--
jsonfuse 1.0: the walk raises duplicate_key for a repeated path segment or leaf at any referenced level, reports the second occurrence with the lowest byte position, and accepts unreferenced duplicates
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

function d(string $label, string $record, array $fields): void
{
    [$o, $r] = jf10_run($record . "\n", ['fields' => $fields]);
    echo str_pad($label, 50), str_starts_with($r, 'read=') ? 'ok ' . rtrim($o, "\n") : preg_replace('/ \[.*\]$/', '', $r), "\n";
}

$ab = ['ab' => ['type' => 'int64', 'path' => ['a', 'b']], 'c' => 'int64'];
echo "-- levels\n";
d('leaf repeated in a nested object', '{"a":{"b":1,"b":2},"c":1}', $ab);
d('leaf repeated, spelled with an escape', '{"a":{"b":1,"\\u0062":2},"c":1}', $ab);
d('intermediate repeated at the root', '{"a":{"b":1},"a":{"b":2},"c":1}', $ab);
d('intermediate repeated, the second not an object', '{"a":{"b":1},"a":5,"c":1}', $ab);
d('repeat at depth 8', '{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":1,"h":2}}}}}}}}',
    ['v' => ['type' => 'int64', 'path' => ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']]]);

echo "-- the second occurrence with the lowest byte position\n";
d('nested repeat first', '{"a":{"b":1,"b":2},"c":1,"c":2}', $ab);
d('root repeat first', '{"c":1,"c":2,"a":{"b":1,"b":2}}', $ab);
d('nested repeat between the root pair', '{"c":1,"a":{"b":1,"b":2},"c":2}', $ab);
d('the walk does not stop once all keys are found', '{"c":1,"a":{"b":1},"z":0,"c":2}', $ab);

echo "-- unreferenced duplicates are accepted\n";
d('in a referenced object', '{"a":{"b":1,"z":1,"z":2},"c":1}', $ab);
d('in an unreferenced object', '{"a":{"b":1},"c":1,"y":{"q":1,"q":2},"y":0}', $ab);
d('inside a referenced object, off the path', '{"a":{"b":1},"c":1,"o":{"k":{"k":1,"k":2}}}', $ab + ['o' => ['type' => 'int64?', 'path' => ['o', 'p'], 'default' => null]]);
d('a path key under a node not on that path', '{"a":{"x":1},"x":{"x":1,"x":2},"c":1}', ['ax' => ['type' => 'int64', 'path' => ['a', 'x']], 'c' => 'int64']);

echo "-- getField(): the first fields entry whose path passes through the key\n";
$pq = ['z' => 'int64', 'p' => ['type' => 'int64', 'path' => ['a', 'x']], 'q' => ['type' => 'int64', 'path' => ['a', 'y']]];
d('shared intermediate repeated', '{"z":0,"a":{"x":1,"y":2},"a":{"x":1,"y":2}}', $pq);
d('second sibling repeated', '{"z":0,"a":{"x":1,"y":2,"y":3}}', $pq);
?>
--EXPECT--
-- levels
leaf repeated in a nested object                  RecordException duplicate_key field="ab" at=1/1/0
leaf repeated, spelled with an escape             RecordException duplicate_key field="ab" at=1/1/0
intermediate repeated at the root                 RecordException duplicate_key field="ab" at=1/1/0
intermediate repeated, the second not an object   RecordException duplicate_key field="ab" at=1/1/0
repeat at depth 8                                 RecordException duplicate_key field="v" at=1/1/0
-- the second occurrence with the lowest byte position
nested repeat first                               RecordException duplicate_key field="ab" at=1/1/0
root repeat first                                 RecordException duplicate_key field="c" at=1/1/0
nested repeat between the root pair               RecordException duplicate_key field="ab" at=1/1/0
the walk does not stop once all keys are found    RecordException duplicate_key field="c" at=1/1/0
-- unreferenced duplicates are accepted
in a referenced object                            ok {"ab":1,"c":1}
in an unreferenced object                         ok {"ab":1,"c":1}
inside a referenced object, off the path          ok {"ab":1,"c":1,"o":null}
a path key under a node not on that path          ok {"ax":1,"c":1}
-- getField(): the first fields entry whose path passes through the key
shared intermediate repeated                      RecordException duplicate_key field="p" at=1/1/0
second sibling repeated                           RecordException duplicate_key field="q" at=1/1/0
