--TEST--
jsonfuse 1.0: a run that wrote nothing does not flush; a failing run never flushes; after a record error or read failure exactly the good records are written, after a wrapper exception nothing more
--EXTENSIONS--
jsonfuse
--FILE--
<?php
require __DIR__ . '/jf_helper.inc';
require __DIR__ . '/jf_outwrap.inc';

use JsonFuse\Plan;
use JsonFuse\Runner;

/*
 * SPEC.md section 6: "A run that wrote nothing, or that failed, does not flush." and "After any error no
 * further writes occur." A pending exception makes PHP skip userland callbacks, so the "no writes" part is
 * checked against plain php://memory output, which a write would really reach.
 */

function attempt($in, $out, Plan $plan): string
{
    try {
        return 'ok: ' . jf_stats(Runner::process($in, $out, $plan));
    } catch (Throwable $e) {
        return get_class($e);
    }
}

$plan = Plan::compile(['fields' => ['id' => 'int64']]);
const REC = 24;

/** One record of exactly REC bytes including the LF. */
function rec(int $i): string
{
    $s = '{"id":' . $i . ',"p":"';
    return $s . str_repeat('x', REC - strlen($s) - 3) . "\"}\n";
}

echo "-- a run that wrote nothing does not flush\n";
foreach ([
    'empty input' => ['', $plan],
    'zero matches' => [rec(1) . rec(2), Plan::compile(['fields' => ['id' => 'int64'], 'where' => ['id' => 99]])],
    'only blank lines' => ["\n\n", Plan::compile(['fields' => ['id' => 'int64'], 'skipEmptyLines' => true])],
] as $label => [$data, $p]) {
    $out = jf_sink();
    $r = attempt(jf_mem($data), $out, $p);
    echo str_pad($label, 20), $r, ' flushes=', JfSink::$last->flushes, ' writes=', JfSink::$last->writes, "\n";
    fclose($out);
}

echo "-- a run that failed does not flush\n";
$goods = '';
for ($i = 1; $i <= 40; $i++) {
    $goods .= rec($i);
}
foreach ([
    'record error' => [jf_mem($goods . "[]\n"), []],
    'input read failure' => [jf_wrap($goods, 997, ['mode' => 'false_read', 'after' => strlen($goods)]), []],
    'write fails at once' => [jf_mem($goods), ['mode' => 'false']],
    'write fails midway' => [jf_mem($goods), ['mode' => 'false', 'after' => 5]],
] as $label => [$in, $sink]) {
    $out = jf_sink($sink);
    $r = attempt($in, $out, $plan);
    echo str_pad($label, 20), $r, ' flushes=', JfSink::$last->flushes, "\n";
    fclose($out);
}

echo "-- a plain stream: flushing failures write exactly the good records, a wrapper exception writes nothing more\n";
// Every read serves exactly one record, so all earlier records are fully processed before the last read.
foreach ([1, 7, 40] as $n) {
    $goods = '';
    for ($i = 1; $i <= $n; $i++) {
        $goods .= rec($i);
    }
    $failing = $n + 1;
    $want = '';
    for ($i = 1; $i <= $n; $i++) {
        $want .= '{"id":' . $i . "}\n";
    }

    foreach ([
        'record error' => [$goods . "[]\n" . str_repeat(' ', REC - 3), ['mode' => 'normal']],
        'read throws' => [$goods, ['mode' => 'throw_read', 'after' => strlen($goods), 'exc' => new JfBoom('boom')]],
        'read false' => [$goods, ['mode' => 'false_read', 'after' => strlen($goods)]],
    ] as $label => [$data, $cfg]) {
        $out = jf_out();
        $snap = null;
        $cfg['on_read'] = function (int $call) use (&$snap, $failing, $out) {
            if ($call === $failing) {
                $snap = ftell($out);
            }
        };
        $in = jf_wrap($data, REC, $cfg);
        $r = attempt($in, $out, $plan);
        $check = $label === 'read throws'
            ? ' position unchanged since the failing read began: ' . var_export($snap === ftell($out), true)
            : ' output is exactly the good records: ' . var_export(jf_out_bytes($out) === $want, true);
        echo str_pad("$n records, $label", 28), $r, $check, "\n";
        fclose($in);
        fclose($out);
    }
}
?>
--EXPECT--
-- a run that wrote nothing does not flush
empty input         ok: read=0 matched=0 emitted=0 bytesRead=0 bytesWritten=0 flushes=0 writes=0
zero matches        ok: read=2 matched=0 emitted=0 bytesRead=48 bytesWritten=0 flushes=0 writes=0
only blank lines    ok: read=0 matched=0 emitted=0 bytesRead=2 bytesWritten=0 flushes=0 writes=0
-- a run that failed does not flush
record error        JsonFuse\RecordException flushes=0
input read failure  JsonFuse\StreamException flushes=0
write fails at once JsonFuse\StreamException flushes=0
write fails midway  JsonFuse\StreamException flushes=0
-- a plain stream: flushing failures write exactly the good records, a wrapper exception writes nothing more
1 records, record error     JsonFuse\RecordException output is exactly the good records: true
1 records, read throws      JfBoom position unchanged since the failing read began: true
1 records, read false       JsonFuse\StreamException output is exactly the good records: true
7 records, record error     JsonFuse\RecordException output is exactly the good records: true
7 records, read throws      JfBoom position unchanged since the failing read began: true
7 records, read false       JsonFuse\StreamException output is exactly the good records: true
40 records, record error    JsonFuse\RecordException output is exactly the good records: true
40 records, read throws     JfBoom position unchanged since the failing read began: true
40 records, read false      JsonFuse\StreamException output is exactly the good records: true
