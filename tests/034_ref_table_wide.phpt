--TEST--
jsonfuse: referenced keys resolve correctly at the plan limits and when names share a hash slot
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_reference.inc';

use JsonFuse\RecordException;
use JsonFuse\Runner;

function message(string $record, array $spec): string
{
    $in = jf_mem($record . "\n");
    $out = jf_out();
    try {
        return jf_stats(Runner::process($in, $out, jf_plan($spec)));
    } catch (RecordException $e) {
        // Position checked here; reason and the plan's own name (raw bytes) are what callers compare.
        $at = sprintf('%d/%d/%d', $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset());
        return ($at === '1/1/0' ? '' : "at=$at ") . $e->getReason() . ' ' . json_encode($e->getField(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } finally {
        fclose($in);
        fclose($out);
    }
}

// ---- 144 keys: a table sized for the plan limits cannot avoid shared slots -------------
$fields = [];
$pairs = [];
for ($i = 1; $i <= 128; $i++) {
    $k = sprintf('f%03d', $i);
    $fields[$k] = 'int64';
    $pairs[$k] = $i;
}
$where = [];
for ($i = 1; $i <= 16; $i++) {
    $k = sprintf('w%03d', $i);
    $where[$k] = $i * 7;
    $pairs[$k] = $i * 7;
}
$spec = ['fields' => $fields, 'where' => $where];

function render(array $pairs, array $drop = [], ?string $dup = null): string
{
    $parts = [];
    foreach ($pairs as $k => $v) {
        if (!in_array($k, $drop, true)) {
            $parts[] = "\"$k\":$v";
        }
    }
    if ($dup !== null) {
        $parts[] = "\"$dup\":0";
    }
    return '{' . implode(',', $parts) . '}';
}

echo "all keys present and matching: ", jf_v(render($pairs), $spec), "\n";
echo "reversed record order: ", jf_v(render(array_reverse($pairs, true)), $spec), "\n";

$bad = 0;
foreach ($pairs as $k => $_) {
    $isWhere = $k[0] === 'w';

    $got = message(render($pairs, [$k]), $spec);
    $want = $isWhere ? 'read=1 matched=0 emitted=0' : "missing_field \"$k\"";
    if (!str_contains($got, $want)) {
        echo "dropping $k: $got\n";
        $bad++;
    }

    $got = message(render($pairs, [], $k), $spec);
    if (!str_contains($got, "duplicate_key \"$k\"")) {
        echo "duplicating $k: $got\n";
        $bad++;
    }

    if ($isWhere) {
        $changed = $pairs;
        $changed[$k] += 1;
        $got = message(render($changed), $spec);
        if (!str_contains($got, 'read=1 matched=0')) {
            echo "changing $k: $got\n";
            $bad++;
        }
    }
}
echo "per-key checks failed: $bad\n";

// ---- forced collisions -----------------------------------------------------------------
// Mirrors jf_key_hash() and jf_table_size() in src/jf_plan.h and src/jf_plan.c: a top-level key
// hashes as plain FNV-1a, and a plan of N one-segment names has the smallest power-of-two table
// of at least max(512, 3 * N) slots. If either changes, update this copy, or these
// names stop sharing a slot and the section below loses its point. $mask is set per plan below.
$mask = 0;
function slot(string $s): int
{
    global $mask;
    $h = 2166136261;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $h = (($h ^ ord($s[$i])) * 16777619) & 0xFFFFFFFF;
    }
    return $h & $mask;
}

/** The slot mask of a plan with $names distinct one-segment names. */
function mask_for(int $names): int
{
    for ($size = 512; $size < 3 * $names; $size *= 2) {
    }
    return $size - 1;
}

/** $prefix followed by digits, landing in slot $target and different from every name in $not. */
function collide(string $prefix, int $target, array $not = []): string
{
    for ($i = 0; ; $i++) {
        $k = $prefix . $i;
        if (slot($k) === $target && !in_array($k, $not, true)) {
            return $k;
        }
    }
}

/** $prefix followed by exactly 4 digits, same contract as collide(). */
function collide4(string $prefix, int $target, array $not = []): string
{
    for ($i = 0; $i < 10000; $i++) {
        $k = $prefix . sprintf('%04d', $i);
        if (slot($k) === $target && !in_array($k, $not, true)) {
            return $k;
        }
    }
    throw new LogicException('no 4-digit collision');
}

function row(string $label, string $record, array $spec, string $want): void
{
    $got = message($record, $spec);
    if (str_starts_with($got, 'read=')) {
        $got = str_contains($got, 'matched=1') ? 'match' : 'nomatch';
    } else {
    }
    echo str_pad($label, 62), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
}

echo "-- two field names, same length, one slot\n";
$mask = mask_for(2);
$a = 'fa0000';
$b = collide4('fb', slot($a));
$two = ['fields' => [$a => 'int64', $b => 'string']];
row('both present', "{\"$a\":1,\"$b\":\"s\"}", $two, 'match');
row('both present, record order swapped', "{\"$b\":\"s\",\"$a\":1}", $two, 'match');
row('first field wrong type', "{\"$a\":\"x\",\"$b\":\"s\"}", $two, "type_mismatch \"$a\"");
row('second field wrong type', "{\"$a\":1,\"$b\":2}", $two, "type_mismatch \"$b\"");
row('first field missing', "{\"$b\":\"s\"}", $two, "missing_field \"$a\"");
row('second field missing', "{\"$a\":1}", $two, "missing_field \"$b\"");
row('first field duplicated', "{\"$a\":1,\"$a\":2,\"$b\":\"s\"}", $two, "duplicate_key \"$a\"");
row('second field duplicated', "{\"$a\":1,\"$b\":\"s\",\"$b\":\"t\"}", $two, "duplicate_key \"$b\"");

echo "-- an unreferenced record key in the slot of a plan name\n";
$mask = mask_for(1);
$c = collide4('fc', slot($a));
$one = ['fields' => [$a => 'int64']];
row('same length, not the field', "{\"$c\":2}", $one, "missing_field \"$a\"");
row('same length, beside the field', "{\"$a\":1,\"$c\":2}", $one, 'match');

$nul = "a\0b";
$other = collide("a\0", slot($nul), [$nul]);
$oj = str_replace("\0", '\\u0000', $other);
$nj = 'a\\u0000b';
row('NUL sibling beside the field', "{\"$nj\":1,\"$oj\":2}", ['fields' => [$nul => 'int64']], 'match');
row('NUL sibling alone', "{\"$oj\":2}", ['fields' => [$nul => 'int64']], 'missing_field "a\\u0000b"');

$longName = collide('p', slot('p'), ['p']);
row('key is a strict prefix of the field', '{"p":1}', ['fields' => [$longName => 'int64']], "missing_field \"$longName\"");
row('prefix key beside the field', "{\"$longName\":1,\"p\":2}", ['fields' => [$longName => 'int64']], 'match');
$longKey = collide('q', slot('q'), ['q']);
row('field is a strict prefix of the key', "{\"q\":1,\"$longKey\":2}", ['fields' => ['q' => 'int64']], 'match');

echo "-- plan names that differ only past a NUL or by length, one slot\n";
$mask = mask_for(2);
$x = "x\0y";
$y = collide("x\0", slot($x), [$x]);
$xj = 'x\\u0000y';
$yj = str_replace("\0", '\\u0000', $y);
row('NUL siblings are two fields', "{\"$xj\":1,\"$yj\":\"s\"}", ['fields' => [$x => 'int64', $y => 'string']], 'match');
$long = collide('r', slot('r'), ['r']);
row('a name and its prefix are two fields', "{\"$long\":\"s\",\"r\":1}", ['fields' => [$long => 'string', 'r' => 'int64']], 'match');

echo "-- names in the last slots and a colliding name that wraps to slot 0\n";
// Layout (5 names, 16 slots): last1 at 15, last2 wraps to 0, prev1 at 14, prev2 runs 14, 15, 0 to 1,
// first (home slot 0) runs 0, 1 to 2. Every name needs the wrap to be stored or found.
$mask = mask_for(5);
$last1 = collide('wa', $mask);
$last2 = collide('wb', $mask, [$last1]);
$prev1 = collide('wc', $mask - 1);
$prev2 = collide('wd', $mask - 1, [$prev1]);
$first = collide('we', 0);
$wrap = [$last1, $last2, $prev1, $prev2, $first];
$wrapSpec = ['fields' => array_fill_keys($wrap, 'int64')];
$members = array_map(fn($n) => "\"$n\":1", $wrap);
$record = '{' . implode(',', $members) . '}';
row('all five present', $record, $wrapSpec, 'match');
row('all five present, reversed', '{' . implode(',', array_reverse($members)) . '}', $wrapSpec, 'match');
foreach ($wrap as $i => $n) {
    $without = $members;
    unset($without[$i]);
    row("name $i missing", '{' . implode(',', $without) . '}', $wrapSpec, "missing_field \"$n\"");
    $typed = $members;
    $typed[$i] = "\"$n\":\"x\"";
    row("name $i wrong type", '{' . implode(',', $typed) . '}', $wrapSpec, "type_mismatch \"$n\"");
    row("name $i duplicated", '{' . implode(',', $members) . ",\"$n\":2}", $wrapSpec, "duplicate_key \"$n\"");
}
// Not in the plan: their probe chains cross the wrap and must end at the empty slot, not match anything.
foreach (['home 15' => collide('xa', $mask), 'home 14' => collide('xb', $mask - 1), 'home 0' => collide('xc', 0)] as $label => $absent) {
    row("unreferenced key, $label", '{' . implode(',', $members) . ",\"$absent\":\"not a number\"}", $wrapSpec, 'match');
}
?>
--EXPECT--
all keys present and matching: match
reversed record order: match
per-key checks failed: 0
-- two field names, same length, one slot
both present                                                  ok
both present, record order swapped                            ok
first field wrong type                                        ok
second field wrong type                                       ok
first field missing                                           ok
second field missing                                          ok
first field duplicated                                        ok
second field duplicated                                       ok
-- an unreferenced record key in the slot of a plan name
same length, not the field                                    ok
same length, beside the field                                 ok
NUL sibling beside the field                                  ok
NUL sibling alone                                             ok
key is a strict prefix of the field                           ok
prefix key beside the field                                   ok
field is a strict prefix of the key                           ok
-- plan names that differ only past a NUL or by length, one slot
NUL siblings are two fields                                   ok
a name and its prefix are two fields                          ok
-- names in the last slots and a colliding name that wraps to slot 0
all five present                                              ok
all five present, reversed                                    ok
name 0 missing                                                ok
name 0 wrong type                                             ok
name 0 duplicated                                             ok
name 1 missing                                                ok
name 1 wrong type                                             ok
name 1 duplicated                                             ok
name 2 missing                                                ok
name 2 wrong type                                             ok
name 2 duplicated                                             ok
name 3 missing                                                ok
name 3 wrong type                                             ok
name 3 duplicated                                             ok
name 4 missing                                                ok
name 4 wrong type                                             ok
name 4 duplicated                                             ok
unreferenced key, home 15                                     ok
unreferenced key, home 14                                     ok
unreferenced key, home 0                                      ok
