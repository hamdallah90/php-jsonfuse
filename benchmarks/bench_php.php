<?php
// bench_php.php <php|simdjson|fastjson|jsonmachine> <mode> <shape> <fixture> <output>
// One timed process running one hand-written PHP baseline. Prints one result line.
declare(strict_types=1);
require __DIR__ . '/lib.php';

[, $impl, $mode, $shape, $fixture, $output] = $argv;

jf_assert_engine($mode);
$plan = jf_plan_spec($shape);

if ($impl === 'jsonmachine') {
    spl_autoload_register(function (string $class): void {
        if (str_starts_with($class, 'JsonMachine\\')) {
            require getenv('JF_JSON_MACHINE_SRC') . '/' . strtr(substr($class, 12), '\\', '/') . '.php';
        }
    });
}

[$fn, $file] = jf_baseline($plan['fields'], $plan['where'], $impl);

// Warm-ups run the full job in this process first, so the JIT is warm for the timed run. With WARMUPS>0 only
// `ns` is meaningful for time, and RSS must come from a WARMUPS=0 run.
for ($i = (int)(getenv('WARMUPS') ?: 0); $i > 0; $i--) {
    $in = fopen($fixture, 'rb');
    $out = fopen($output, 'wb');
    stream_set_chunk_size($in, 65536);
    $fn($in, $out);
    fclose($in);
    fclose($out);
}

$in = fopen($fixture, 'rb');
$out = fopen($output, 'wb');
// Same read granularity on both sides: 64 KiB, matching the native reader.
stream_set_chunk_size($in, 65536);

$t0 = hrtime(true);
$r = $fn($in, $out);
$ns = hrtime(true) - $t0;
fclose($in);
fclose($out);

$s = jf_engine_state();
if ($mode !== 'off' && !opcache_is_script_cached($file)) {
    fwrite(STDERR, "ENGINE ASSERT FAILED: baseline source was not served by opcache\n");
    exit(3);
}
$jitUsed = $s['jit_buffer'] - $s['jit_free'];
if ($mode === 'jit' && $jitUsed <= 0) {
    fwrite(STDERR, "ENGINE ASSERT FAILED: JIT on but nothing compiled\n");
    exit(3);
}

printf(
    "ns=%d read=%d matched=%d emitted=%d bytes_in=%d bytes_out=%d peak_php=%d opcache=%d jit_on=%d jit_used=%d\n",
    $ns, $r['records_read'], $r['records_matched'], $r['records_emitted'], $r['bytes_read'], $r['bytes_written'],
    memory_get_peak_usage(true), $s['opcache'] ? 1 : 0, $s['jit_on'] ? 1 : 0, $jitUsed
);
