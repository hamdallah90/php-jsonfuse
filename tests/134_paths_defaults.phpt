--TEST--
jsonfuse 1.0: nested paths with defaults; a missing key at any level emits the default, an explicit JSON null leaf is never replaced, a null intermediate is path_not_object
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

$spec = ['fields' => [
    'id' => 'int64',
    'city' => ['type' => 'string?', 'path' => ['address', 'city'], 'default' => 'none'],
    'zip' => ['type' => 'int64', 'path' => ['address', 'zip'], 'default' => 0],
]];
foreach ([
    'address missing' => '{"id":1}',
    'address empty' => '{"id":2,"address":{}}',
    'city null, zip present' => '{"id":3,"address":{"city":null,"zip":10115}}',
    'both present' => '{"id":4,"address":{"zip":1,"city":"Paris"}}',
    'zip null in a non-nullable field' => '{"id":5,"address":{"zip":null}}',
    'address null' => '{"id":6,"address":null}',
    'address an array' => '{"id":7,"address":[{"city":"x"}]}',
] as $label => $rec) {
    [$o, $r] = jf10_run($rec . "\n", $spec);
    echo str_pad($label, 34), str_starts_with($r, 'read=') ? rtrim($o, "\n") : preg_replace('/ \[.*\]$/', '', $r), "\n";
}
?>
--EXPECT--
address missing                   {"id":1,"city":"none","zip":0}
address empty                     {"id":2,"city":"none","zip":0}
city null, zip present            {"id":3,"city":null,"zip":10115}
both present                      {"id":4,"city":"Paris","zip":1}
zip null in a non-nullable field  RecordException null_field field="zip" at=1/1/0
address null                      RecordException path_not_object field="city" at=1/1/0
address an array                  RecordException path_not_object field="city" at=1/1/0
