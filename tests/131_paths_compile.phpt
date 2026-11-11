--TEST--
jsonfuse 1.0: a path is a list of 1 to 8 strings; int segments, 9 segments and non-lists are invalid_path, bad segment bytes are invalid_name, equal paths are duplicate_name and strict prefixes are invalid_path
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';

function p($path): array
{
    return ['type' => 'int64', 'path' => $path];
}
function c(string $label, array $fields): void
{
    echo str_pad($label, 36), jf10_compile(['fields' => ['id' => 'int64'] + $fields]), "\n";
}

echo "-- shape of a path\n";
c('1 segment', ['v' => p(['a'])]);
c('8 segments', ['v' => p(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])]);
c('9 segments', ['v' => p(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i'])]);
c('empty list', ['v' => p([])]);
c('int segment 0 (no array indexing)', ['v' => p(['items', 0])]);
c('int segment alone', ['v' => p([7])]);
c('null segment', ['v' => p(['a', null])]);
c('float segment', ['v' => p(['a', 1.5])]);
c('bool segment', ['v' => p([true])]);
c('array segment', ['v' => p(['a', ['b']])]);
c('a string, not a list', ['v' => p('a.b')]);
c('null path', ['v' => p(null)]);
c('string keys', ['v' => p(['x' => 'a'])]);
c('list with a hole', ['v' => p([1 => 'a'])]);
c('"0" and empty segments', ['v' => p(['0', ''])]);

echo "-- segment bytes: getField() is null for invalid_name\n";
c('segment not UTF-8', ['v' => p(['a', "\xff"])]);
c('segment 1025 bytes', ['v' => p([str_repeat('s', 1025)])]);
c('segment 1024 bytes', ['v' => p([str_repeat('s', 1024)])]);

echo "-- equal paths: duplicate_name names the later entry\n";
c('two nested fields, one path', ['x' => p(['a', 'b']), 'y' => p(['a', 'b'])]);
c('a name and a path', ['a' => 'int64', 'b' => p(['a'])]);
c('the default path [id] again', ['v' => p(['id'])]);

echo "-- strict prefixes: invalid_path names the later entry\n";
c('shorter first', ['x' => p(['a']), 'y' => p(['a', 'b'])]);
c('longer first', ['y' => p(['a', 'b', 'c']), 'x' => p(['a', 'b'])]);
c('a top-level field over a nested one', ['v' => p(['id', 'x'])]);
c('8 segments under 7', ['x' => p(['a', 'b', 'c', 'd', 'e', 'f', 'g']), 'y' => p(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'])]);

echo "-- not conflicts\n";
c('siblings', ['x' => p(['a', 'b']), 'y' => p(['a', 'c'])]);
c('dotted key beside a path', ['x' => p(['a.b']), 'y' => p(['a', 'b'])]);
c('swapped segments', ['x' => p(['a', 'b']), 'y' => p(['b', 'a'])]);
c('same leaf name at other depths', ['x' => p(['b']), 'y' => p(['a', 'b']), 'z' => p(['c', 'a', 'b'])]);
?>
--EXPECT--
-- shape of a path
1 segment                           ok
8 segments                          ok
9 segments                          PlanException invalid_path option="fields" field="v"
empty list                          PlanException invalid_path option="fields" field="v"
int segment 0 (no array indexing)   PlanException invalid_path option="fields" field="v"
int segment alone                   PlanException invalid_path option="fields" field="v"
null segment                        PlanException invalid_path option="fields" field="v"
float segment                       PlanException invalid_path option="fields" field="v"
bool segment                        PlanException invalid_path option="fields" field="v"
array segment                       PlanException invalid_path option="fields" field="v"
a string, not a list                PlanException invalid_path option="fields" field="v"
null path                           PlanException invalid_path option="fields" field="v"
string keys                         PlanException invalid_path option="fields" field="v"
list with a hole                    PlanException invalid_path option="fields" field="v"
"0" and empty segments              ok
-- segment bytes: getField() is null for invalid_name
segment not UTF-8                   PlanException invalid_name option="fields" field=null
segment 1025 bytes                  PlanException invalid_name option="fields" field=null
segment 1024 bytes                  ok
-- equal paths: duplicate_name names the later entry
two nested fields, one path         PlanException duplicate_name option="fields" field="y"
a name and a path                   PlanException duplicate_name option="fields" field="b"
the default path [id] again         PlanException duplicate_name option="fields" field="v"
-- strict prefixes: invalid_path names the later entry
shorter first                       PlanException invalid_path option="fields" field="y"
longer first                        PlanException invalid_path option="fields" field="x"
a top-level field over a nested one PlanException invalid_path option="fields" field="v"
8 segments under 7                  PlanException invalid_path option="fields" field="y"
-- not conflicts
siblings                            ok
dotted key beside a path            ok
swapped segments                    ok
same leaf name at other depths      ok
