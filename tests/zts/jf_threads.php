<?php
// Workloads for jf_threads.c. Each function returns a string the C side compares; nothing here
// decides pass or fail on its own except where a check needs PHP state (warnings, ini values).

use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\Stats;

const JFH_SYNC_INPUT = "{\"id\":1}\n{\"id\":2}\n";
// Thread 0 runs at memory_limit=2M, one Zend MM chunk: every input here is small enough to share it.
const JFH_BIG_BYTES = 200000;

/** Deterministic plan specs and inputs; the same seed gives the same bytes in every thread. */
function jfh_workloads(): array
{
    mt_srand(20261110);
    $w = [];

    // 0: flat plan, scalar where.
    $in = '';
    for ($i = 0; $i < 700; $i++) {
        $in .= json_encode(['id' => $i, 'kind' => ['a', 'b', 'c'][mt_rand(0, 2)], 'v' => mt_rand(-1000000, 1000000) / 7,
            'ok' => (bool)mt_rand(0, 1), 'name' => str_repeat(chr(mt_rand(97, 122)), mt_rand(0, 60)) . "\u{e9}\t"]) . "\n";
    }
    $w[] = [['fields' => ['id' => 'int64', 'name' => 'string', 'v' => 'float64', 'ok' => 'bool'], 'where' => ['kind' => 'b']], $in];

    // 1: nested paths and defaults, some keys missing.
    $in = '';
    for ($i = 0; $i < 600; $i++) {
        $r = ['id' => $i, 'a' => ['b' => ['c' => $i * 3]]];
        if ($i % 3) {
            $r['a']['t'] = 'tier' . ($i % 5);
        }
        if ($i % 4) {
            $r['x'] = ['y' => $i / 4];
        }
        $in .= json_encode($r) . "\n";
    }
    $w[] = [['fields' => [
        'id' => 'int64',
        'c' => ['type' => 'int64', 'path' => ['a', 'b', 'c']],
        't' => ['type' => 'string', 'path' => ['a', 't'], 'default' => 'free'],
        'y' => ['type' => 'float64', 'path' => ['x', 'y'], 'default' => 0],
    ]], $in];

    // 2: a record error after good records: flush before throw, progress and reason.
    $in = '';
    for ($i = 0; $i < 500; $i++) {
        $in .= $i === 400 ? "{\"id\":\"seven hundred\"}\n" : "{\"id\":$i,\"s\":\"" . str_repeat('q', $i % 90) . "\"}\n";
    }
    $w[] = [['fields' => ['id' => 'int64', 's' => ['type' => 'string?', 'default' => null]]], $in];

    // 3: a duplicate key on a nested path, blank lines skipped.
    $in = '';
    for ($i = 0; $i < 400; $i++) {
        $in .= ($i % 50 === 0 ? "\n" : '') . ($i === 333 ? "{\"id\":$i,\"p\":{\"q\":1,\"q\":2}}\n" : "{\"id\":$i,\"p\":{\"q\":$i}}\n");
    }
    $w[] = [['fields' => ['id' => 'int64', 'q' => ['type' => 'int64', 'path' => ['p', 'q']]], 'skipEmptyLines' => true], $in];

    // 4: a wide plan of 100 fields, two records in three filtered out.
    $fields = [];
    for ($f = 0; $f < 100; $f++) {
        $fields["f$f"] = ['int64', 'string', 'bool?', 'float64'][$f % 4];
    }
    $in = '';
    for ($i = 0; $i < 60; $i++) {
        $r = ['g' => $i % 3];
        for ($f = 0; $f < 100; $f++) {
            $r["f$f"] = [$i * $f, "s$i-$f", $f % 8 === 2 ? null : (bool)($i & 1), $i + $f / 8][$f % 4];
        }
        $in .= json_encode($r) . "\n";
    }
    $w[] = [['fields' => $fields, 'where' => ['g' => 1]], $in];

    // 5: invalid JSON midway, and a small maxRecordBytes that every other record fits.
    $in = '';
    for ($i = 0; $i < 500; $i++) {
        $in .= $i === 450 ? "{\"id\":$i,\n" : "{\"id\":$i}\n";
    }
    $w[] = [['fields' => ['id' => 'int64'], 'maxRecordBytes' => 64], $in];

    return $w;
}

function jfh_mem(string $data)
{
    $s = fopen('php://memory', 'w+b');
    fwrite($s, $data);
    rewind($s);
    return $s;
}

function jfh_stats(Stats $s): string
{
    return "$s->recordsRead/$s->recordsMatched/$s->recordsEmitted/$s->bytesRead/$s->bytesWritten";
}

/** Compiles the plan, runs it on fresh php://memory streams, and hashes output and outcome. */
function jfh_run(array $spec, string $input): string
{
    $plan = Plan::compile($spec);
    $in = jfh_mem($input);
    $out = fopen('php://memory', 'w+b');
    try {
        $outcome = 'ok ' . jfh_stats(Runner::process($in, $out, $plan));
    } catch (JsonFuse\JsonFuseException $e) {
        $outcome = get_class($e) . ' ' . $e->getReason() . ' ' . $e->getField() . ' ' . $e->getInputLine() . ' '
            . $e->getRecordNumber() . ' ' . $e->getByteOffset() . ' ' . jfh_stats($e->getProgress());
    }
    rewind($out);
    $bytes = stream_get_contents($out);
    fclose($in);
    fclose($out);
    return hash('sha256', $outcome . "\n" . $bytes);
}

/** An invalid plan, compiled and dropped: the PlanException path in every thread. */
function jfh_bad_plan(): string
{
    try {
        Plan::compile(['fields' => ['a' => ['type' => 'int64', 'path' => ['a', 'b'], 'default' => 'x']]]);
        return 'compiled';
    } catch (JsonFuse\PlanException $e) {
        return $e->getReason();
    }
}

/** The record of the memory_limit check: one string of JFH_BIG_BYTES bytes, written in chunks. */
function jfh_big_input()
{
    $s = fopen('php://memory', 'w+b');
    fwrite($s, '{"id":1,"s":"');
    for ($i = 0; $i < JFH_BIG_BYTES; $i += 8000) {
        fwrite($s, str_repeat('m', 8000));
    }
    fwrite($s, "\"}\n");
    rewind($s);
    return $s;
}

/** memory_limit: the reason on a RecordException, else the hash of the Stats and the output. */
function jfh_big(): string
{
    $in = jfh_big_input();
    $out = fopen('php://memory', 'w+b');
    try {
        $outcome = 'ok ' . jfh_stats(Runner::process($in, $out, Plan::compile(['fields' => ['id' => 'int64', 's' => 'string']])));
    } catch (JsonFuse\RecordException $e) {
        return $e->getReason();
    }
    rewind($out);
    $h = hash('sha256', $outcome . "\n" . stream_get_contents($out));
    fclose($in);
    fclose($out);
    return $h;
}

/** Output wrapper of the fiber check: the first write waits for every thread twice (C barrier). */
final class JfhSync
{
    public static bool $fiber = false;
    public static bool $sync = true;
    public static int $switches = 0;
    public $context;
    private bool $met = false;

    public function stream_open($path, $mode, $options, &$opened): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        if (!$this->met && self::$sync) {
            $this->met = true;
            // Every thread is inside its run here. Between the two waits only the fiber thread switches.
            if (!jf_harness_barrier()) {
                throw new RuntimeException('another thread left its request');
            }
            if (self::$fiber) {
                $f = new Fiber(function (): void {
                    Fiber::suspend();
                });
                $f->start();
                $f->resume();
                self::$switches++;
            }
            if (!jf_harness_barrier()) {
                throw new RuntimeException('another thread left its request');
            }
        }
        return strlen($data);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}

/** One run through the wrapper, then fclose() of both streams: "flagged", "cleared" or what went wrong. */
function jfh_sync(bool $fiber): string
{
    JfhSync::$fiber = $fiber;
    $in = jfh_mem(JFH_SYNC_INPUT);
    $out = fopen('jfh://out', 'wb');
    $stats = jfh_stats(Runner::process($in, $out, Plan::compile(['fields' => ['id' => 'int64']])));
    if ($stats !== '2/2/2/18/18') {
        return "stats $stats";
    }
    $warnings = 0;
    set_error_handler(function (int $no, string $msg) use (&$warnings): bool {
        $warnings += ($no === E_WARNING && str_contains($msg, 'must not be manually closed')) ? 1 : 100;
        return true;
    });
    $closed = [fclose($in), fclose($out)];
    restore_error_handler();
    unset($in, $out);
    if ($closed === [false, false] && $warnings === 2) {
        return 'flagged';
    }
    if ($closed === [true, true] && $warnings === 0) {
        return 'cleared';
    }
    return 'fclose ' . json_encode($closed) . " warnings $warnings";
}

/** Single-threaded reference: the hash of each workload and of the memory_limit record. */
function jfh_reference(): string
{
    stream_wrapper_register('jfh', JfhSync::class);
    JfhSync::$sync = false;
    $r = [];
    foreach (jfh_workloads() as [$spec, $input]) {
        $r[] = jfh_run($spec, $input);
    }
    $r[] = jfh_big();
    $r[] = jfh_sync(false);
    $r[] = jfh_bad_plan();
    return implode(' ', $r);
}

/**
 * One thread's request. Thread 0 runs at memory_limit=2M. Iteration i: workload (tid + i) mod 6,
 * the wrapper run (thread i mod 8 switches fibers), the large record, an invalid plan.
 * Returns one line per iteration: "i w workload-hash sync big bad".
 */
function jfh_thread(int $tid, int $iterations, int $threads): string
{
    if ($tid === 0 && (ini_set('memory_limit', '2M') === false || ini_get('memory_limit') !== '2M')) {
        return 'memory_limit not set';
    }
    stream_wrapper_register('jfh', JfhSync::class);
    $workloads = jfh_workloads();
    $lines = [];
    for ($i = 0; $i < $iterations; $i++) {
        $w = ($tid + $i) % count($workloads);
        [$spec, $input] = $workloads[$w];
        $lines[] = "$i $w " . jfh_run($spec, $input) . ' ' . jfh_sync($i % $threads === $tid) . ' ' . jfh_big() . ' ' . jfh_bad_plan();
    }
    return implode("\n", $lines);
}
