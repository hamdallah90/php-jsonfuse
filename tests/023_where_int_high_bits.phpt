--TEST--
jsonfuse: an int where value and a record integer that differ only above bit 31 do not match
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';

use JsonFuse\Runner;

/*
 * SPEC.md section 5: an int matches only an integer literal of equal value. Each pair below has equal low
 * 32 bits, so a comparison that narrows to 32 bits would call them equal. yyjson tags a non-negative
 * literal UINT and a negative one SINT, and both reach a different comparison.
 */
function matched(int $where, string $literal): int
{
    $in = jf_mem('{"id":1,"v":' . $literal . "}\n");
    $out = jf_out();
    $s = Runner::process($in, $out, jf_plan(['where' => ['v' => $where]]));
    fclose($in);
    fclose($out);
    return $s->recordsMatched;
}

const TWO32 = 4294967296;

foreach ([
    // where high, record low
    [TWO32 + 5, '5', 0],
    [TWO32, '0', 0],
    [-TWO32 + 5, '5', 0],
    [-TWO32, '0', 0],
    [-TWO32 - 5, '-5', 0],
    [(1 << 40) + 5, '5', 0],
    // where low, record high
    [5, '4294967301', 0],
    [0, '4294967296', 0],
    [5, '-4294967291', 0],
    [0, '-4294967296', 0],
    [-5, '-4294967301', 0],
    [5, '1099511627781', 0],
    // equal in every bit
    [TWO32 + 5, '4294967301', 1],
    [-TWO32 + 5, '-4294967291', 1],
    [-TWO32 - 5, '-4294967301', 1],
    [(1 << 40) + 5, '1099511627781', 1],
] as [$where, $literal, $want]) {
    $got = matched($where, $literal);
    echo str_pad("where $where against $literal", 52), $got === $want ? 'ok' : "FAIL got $got", "\n";
}
?>
--EXPECT--
where 4294967301 against 5                          ok
where 4294967296 against 0                          ok
where -4294967291 against 5                         ok
where -4294967296 against 0                         ok
where -4294967301 against -5                        ok
where 1099511627781 against 5                       ok
where 5 against 4294967301                          ok
where 0 against 4294967296                          ok
where 5 against -4294967291                         ok
where 0 against -4294967296                         ok
where -5 against -4294967301                        ok
where 5 against 1099511627781                       ok
where 4294967301 against 4294967301                 ok
where -4294967291 against -4294967291               ok
where -4294967301 against -4294967301               ok
where 1099511627781 against 1099511627781           ok
