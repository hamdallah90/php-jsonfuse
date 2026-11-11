--TEST--
jsonfuse 1.0: 20,000 seeded runs of plans with '=', 'in', number and string range entries and where paths, plus every plan fault injected alone, agree with the PHP reference on compile errors, output bytes, Stats, reason, getters and progress
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf10_helper.inc';
require_once __DIR__ . '/jf_reference.inc';
require_once __DIR__ . '/jf_where_diff.inc';

/*
 * The generator, the comparison and the counts are in tests/jf_where_diff.inc; JfRef
 * (tests/jf_reference.inc) decides every case without the extension. The same run is
 * `php -d extension=$PWD/modules/jsonfuse.so tests/jf_where_diff.inc`, and
 * `php tests/jf_where_diff.inc expect` prints this EXPECT from the reference alone.
 */
[$text] = jf_where_diff('all', JF_WHERE_SEED, JF_WHERE_RUNS);
echo $text;
?>
--EXPECT--
mode all, seed 20261119
plans: 42396 (6052 with an injected fault), compile errors: 21256, runs: 21140 (with an array-form where entry 20000; accepted 9984, rejected 11156)
records: 42309, matched: 5356, output lines: 5356
plan errors by reason: duplicate_name=2524, invalid_name=1134, invalid_option=513, invalid_path=13672, invalid_value=2144, missing_option=38, too_many=755, unknown_option=476
injected faults:
  duplicate_name equal where paths: 329
  invalid_name where name: 369
  invalid_name where segment: 340
  invalid_option fields: 115
  invalid_option maxRecordBytes: 134
  invalid_option output: 75
  invalid_option skipEmptyLines: 71
  invalid_option where: 118
  invalid_path where path form: 383
  invalid_path where path prefix: 705
  invalid_value '<' operand: 166
  invalid_value '<=' operand: 181
  invalid_value '=' operand: 203
  invalid_value '>' operand: 176
  invalid_value '>=' operand: 171
  invalid_value 'in' operand: 169
  invalid_value no operator: 348
  invalid_value number and string ranges: 379
  invalid_value scalar: 351
  missing_option fields: 38
  too_many 257 in members: 337
  too_many fields: 39
  too_many where (17 entries): 379
  unknown_option entry key: 360
  unknown_option top-level key: 116
where forms in compiled runs: eq=7067, in=6874, in256=212, mixed=4355, nested path=9214, num=11186, one-segment path=3958, scalar=4611, shared with a field=5327, str=8404
operator evaluations: '='=7902, 'in'=4207, float vs int=877, float vs real=4257, float vs uint64=42, int vs int=2086, int vs real=1209, int vs uint64=29, number range, RAW=71, number range, other kind=2636, string range=6527, string range, other kind=1546
record errors by reason: blank_line=840, duplicate_key=1690, invalid_json=228, missing_field=1997, not_object=88, null_field=93, path_not_object=984, record_too_large=434, type_mismatch=2743, where_not_scalar=2059
injector disagreements with the reference: 0
disagreements with the reference: 0
