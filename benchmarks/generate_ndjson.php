<?php
// Deterministic NDJSON fixtures. The same arguments produce the same bytes (benchmarks/fixtures.sha256 pins them).
//
//   generate_ndjson.php <narrow|wide> <count> <match_pct> <seed> <outpath>
//   generate_ndjson.php oversized-bound <count> <record_bytes> <seed> <outpath>
//   generate_ndjson.php oversized-line <total_bytes> <seed> <outpath>
declare(strict_types=1);

$shape = $argv[1] ?? '';

/** Formats money without a binary float: PHP's own float printing must not shape the fixture. */
function money(int $cents): string
{
    return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
}

function flush_buffer($h, string &$buf): void
{
    if (strlen($buf) > 1048576) {
        fwrite($h, $buf);
        $buf = '';
    }
}

if ($shape === 'oversized-line') {
    [, , $total, $seed, $path] = $argv;
    mt_srand((int)$seed);
    $h = fopen($path, 'wb');
    fwrite($h, '{"id":1,"amount":1.5,"event":"purchase","pad":"');
    $remaining = (int)$total;
    $chunk = str_repeat('a', 1048576);
    while ($remaining > 0) {
        fwrite($h, $remaining >= 1048576 ? $chunk : substr($chunk, 0, $remaining));
        $remaining -= 1048576;
    }
    // Deliberately unterminated: the line must be refused at the cap, long before this point.
    fclose($h);
    exit(0);
}

if ($shape === 'oversized-bound') {
    [, , $count, $bytes, $seed, $path] = $argv;
    $count = (int)$count;
    $bytes = (int)$bytes;
    mt_srand((int)$seed);
    $h = fopen($path, 'wb');
    for ($i = 1; $i <= $count; $i++) {
        $head = '{"id":' . $i . ',"amount":' . money(mt_rand(1, 99999999)) . ',"event":"' . (mt_rand(0, 99) < 10 ? 'purchase' : 'view') . '","pad":"';
        fwrite($h, $head . str_repeat('x', $bytes - strlen($head) - 2) . "\"}\n");
    }
    fclose($h);
    exit(0);
}

[, $shape, $count, $pct, $seed, $path] = $argv;
$count = (int)$count;
$pct = (int)$pct;
mt_srand((int)$seed);

$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$words = ['alpha', 'bravo', 'order', 'customer', 'latency', 'checkout', 'mobile', 'desktop', 'region-eu', 'café', '東京', 'naïve', 'emoji 😀 ok'];
$pool = [];
for ($i = 0; $i < 2000; $i++) {
    $n = mt_rand(1, 6);
    $parts = [];
    for ($k = 0; $k < $n; $k++) {
        $parts[] = $words[mt_rand(0, count($words) - 1)];
    }
    $s = implode(' ', $parts);
    // Roughly 1 in 10 strings needs escaping, so escape handling is on the measured path.
    $roll = mt_rand(0, 19);
    if ($roll === 0) { $s .= ' "quoted"'; }
    if ($roll === 1) { $s .= " back\\slash"; }
    if ($roll === 2) { $s .= " line\nbreak"; }
    $pool[] = json_encode($s, $flags);
}
$poolMax = count($pool) - 1;
$others = ['"view"', '"click"', '"signup"', '"refund"', '"search"'];

$h = fopen($path, 'wb');
$buf = '';
if ($shape === 'narrow') {
    for ($i = 1; $i <= $count; $i++) {
        $ev = mt_rand(0, 99) < $pct ? '"purchase"' : $others[mt_rand(0, 4)];
        $buf .= '{"id":' . $i . ',"user_id":' . mt_rand(1, 5000000) . ',"event":' . $ev
            . ',"amount":' . money(mt_rand(1, 99999999))
            . ',"active":' . (mt_rand(0, 1) ? 'true' : 'false')
            . ',"note":' . $pool[mt_rand(0, $poolMax)] . "}\n";
        flush_buffer($h, $buf);
    }
} elseif ($shape === 'wide') {
    for ($i = 1; $i <= $count; $i++) {
        $ev = mt_rand(0, 99) < $pct ? '"purchase"' : $others[mt_rand(0, 4)];
        $tag = mt_rand(0, 9) === 0 ? 'null' : $pool[mt_rand(0, $poolMax)];
        $line = '{"id":' . $i . ',"event":' . $ev . ',"amount":' . money(mt_rand(1, 99999999)) . ',"tag":' . $tag;
        // 96 further scalar attributes: int, float, string, bool, null in rotation.
        for ($a = 0; $a < 96; $a++) {
            $line .= ',"attr_' . sprintf('%03d', $a) . '":';
            switch ($a % 5) {
                case 0: $line .= mt_rand(-1000000, 1000000000); break;
                case 1: $c = mt_rand(0, 9999999); $line .= sprintf('%d.%03d', intdiv($c, 1000), $c % 1000); break;
                case 2: $line .= $pool[mt_rand(0, $poolMax)]; break;
                case 3: $line .= mt_rand(0, 1) ? 'true' : 'false'; break;
                default: $line .= 'null'; break;
            }
        }
        $buf .= $line . "}\n";
        flush_buffer($h, $buf);
    }
} else {
    fwrite(STDERR, "unknown shape: $shape\n");
    exit(2);
}
fwrite($h, $buf);
fclose($h);
