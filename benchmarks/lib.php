<?php
// Shared by the benchmark entry points: workload definitions, engine-state assertions and baselines.
declare(strict_types=1);

const JF_MAX_RECORD = 8388608;
const JF_FLUSH_AT = 65536;

/** The Plan each shape is measured with. Baselines implement the same plan by hand. */
function jf_plan_spec(string $shape): array
{
    return match ($shape) {
        'narrow' => ['fields' => ['user_id' => 'int64', 'amount' => 'float64'], 'where' => ['event' => 'purchase']],
        'wide' => ['fields' => ['id' => 'int64', 'amount' => 'float64', 'tag' => 'string?'], 'where' => ['event' => 'purchase']],
        'oversized-bound' => [
            'fields' => ['id' => 'int64', 'amount' => 'float64'], 'where' => ['event' => 'purchase'], 'maxRecordBytes' => 1048576,
        ],
        'oversized-line' => ['fields' => ['id' => 'int64'], 'maxRecordBytes' => 1048576],
        default => throw new InvalidArgumentException("unknown shape $shape"),
    };
}

/** Reads the engine state from inside PHP. Ini files are not evidence. */
function jf_engine_state(): array
{
    $st = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
    $jit = is_array($st) ? ($st['jit'] ?? []) : [];
    return [
        'opcache' => is_array($st) && !empty($st['opcache_enabled']),
        'jit_enabled' => !empty($jit['enabled']),
        'jit_on' => !empty($jit['on']),
        'jit_ini' => (string)ini_get('opcache.jit'),
        'jit_buffer' => (int)($jit['buffer_size'] ?? 0),
        'jit_free' => (int)($jit['buffer_free'] ?? 0),
        'zts' => PHP_ZTS === 1,
        'php_version' => PHP_VERSION,
    ];
}

/** Exits 3 unless the actual engine state is the state this run claims to measure. */
function jf_assert_engine(string $mode): array
{
    $s = jf_engine_state();
    $ok = match ($mode) {
        'off' => !$s['opcache'] && !$s['jit_on'],
        'nojit' => $s['opcache'] && !$s['jit_on'],
        'jit' => $s['opcache'] && $s['jit_enabled'] && $s['jit_on'] && $s['jit_ini'] === 'tracing' && $s['jit_buffer'] > 0,
        default => false,
    };
    if (!$ok) {
        fwrite(STDERR, "ENGINE ASSERT FAILED: claimed mode=$mode, actual=" . json_encode($s) . "\n");
        exit(3);
    }
    return $s;
}

/**
 * Emits the loop a competent developer would hand-write for this exact schema: direct key access,
 * no per-field interpreter, in an include file so opcache and the JIT see it. Interpreting the plan at
 * runtime would make the baseline slower than what anyone would really write.
 * Returns [function name, source file].
 */
function jf_baseline(array $fields, array $where, string $impl): array
{
    $id = substr(sha1(serialize([$fields, $where, $impl])), 0, 16);
    $fn = "jf_baseline_$id";
    $file = sys_get_temp_dir() . "/$fn.php";
    if (!is_file($file)) {
        file_put_contents($file, jf_baseline_source($fn, $fields, $where, $impl), LOCK_EX);
    }
    require_once $file;
    return [$fn, $file];
}

function jf_err(int $read, string $msg): RuntimeException
{
    return new RuntimeException("baseline: record $read: $msg");
}

function jf_write_all($out, string $data): int
{
    $len = strlen($data);
    $off = 0;
    while ($off < $len) {
        $n = fwrite($out, $off === 0 ? $data : substr($data, $off));
        if ($n === false || $n === 0) {
            throw new RuntimeException('baseline: output write failed');
        }
        $off += $n;
    }
    return $len;
}

/** Turns NDJSON into one JSON array, line by line, for JSON Machine, which reads one document. */
function jf_ndjson_as_array($in, int &$bytes): Generator
{
    yield '[';
    $first = true;
    while (($line = fgets($in)) !== false) {
        $bytes += strlen($line);
        yield $first ? $line : ',' . $line;
        $first = false;
    }
    if (!feof($in)) {
        throw new RuntimeException('baseline: input read failed');
    }
    yield ']';
}

function jf_field_check(string $var, string $key, string $spec): string
{
    $nullable = str_ends_with($spec, '?');
    $type = rtrim($spec, '?');
    $label = var_export($key, true);
    $bad = "throw jf_err(\$read, 'bad type in ' . $label);";
    $test = match ($type) {
        'int64' => "if (!is_int($var)) { $bad }",
        'bool' => "if (!is_bool($var)) { $bad }",
        'string' => "if (!is_string($var)) { $bad }",
        'float64' => "if (is_int($var)) { $var = (float)$var; } elseif (!is_float($var) || !is_finite($var)) { $bad }",
        default => throw new RuntimeException("bad type for $key"),
    };
    if (!$nullable) {
        return "            $var = \$r[$label] ?? null;\n            $test\n";
    }
    return "            $var = \$r[$label] ?? null;\n"
        . "            if ($var === null) { if (!array_key_exists($label, \$r)) { throw jf_err(\$read, 'missing ' . $label); } }\n"
        . "            else { $test }\n";
}

function jf_baseline_source(string $fn, array $fields, array $where, string $impl): string
{
    $decode = match ($impl) {
        'php' => 'json_decode($line, true, 512, JSON_THROW_ON_ERROR)',
        'simdjson' => 'simdjson_decode($line, true)',
        'fastjson' => 'fastjson_decode($line, true, 512, JSON_THROW_ON_ERROR)',
        default => null,
    };
    $encode = $impl === 'fastjson' ? 'fastjson_encode' : 'json_encode';

    $checks = '';
    $outItems = [];
    $i = 0;
    foreach ($fields as $name => $spec) {
        $name = (string)$name;
        $var = '$v' . $i++;
        $checks .= jf_field_check($var, $name, $spec);
        $outItems[] = var_export($name, true) . ' => ' . $var;
    }

    $filters = '';
    $conds = [];
    $j = 0;
    foreach ($where as $name => $value) {
        $name = (string)$name;
        $q = var_export($name, true);
        $var = '$f' . $j++;
        $filters .= "            $var = \$r[$q] ?? null;\n"
            . "            if (is_array($var)) { throw jf_err(\$read, 'non-scalar filter ' . $q); }\n";
        $conds[] = $value === null
            ? "($var === null && array_key_exists($q, \$r))"
            : "$var === " . var_export($value, true);
    }
    $skip = $conds === [] ? '' : '            if (!(' . implode(' && ', $conds) . ")) { continue; }\n";
    $outArray = '[' . implode(', ', $outItems) . ']';
    $maxLen = JF_MAX_RECORD;
    $maxWithLf = JF_MAX_RECORD + 1;
    $flushAt = JF_FLUSH_AT;

    if ($impl === 'jsonmachine') {
        // JSON Machine reads one document, so it sees the file as one array. It cannot apply the per-line
        // record cap: a line is not a unit it knows about, and it cannot refuse a line before reading it.
        return <<<PHP
<?php
declare(strict_types=1);

use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

function $fn(\$in, \$out): array
{
    \$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    \$read = \$matched = \$emitted = \$bytesIn = \$bytesOut = 0;
    \$buf = '';
    try {
        foreach (Items::fromIterable(jf_ndjson_as_array(\$in, \$bytesIn), ['decoder' => new ExtJsonDecoder(true)]) as \$r) {
            ++\$read;
            if (!is_array(\$r)) { throw jf_err(\$read, 'root is not an object'); }
$checks$filters$skip            ++\$matched;
            \$buf .= $encode($outArray, \$flags) . "\\n";
            ++\$emitted;
            if (strlen(\$buf) >= $flushAt) { \$bytesOut += jf_write_all(\$out, \$buf); \$buf = ''; }
        }
    } catch (\\JsonMachine\\Exception\\JsonMachineException | \\JsonException \$e) {
        throw jf_err(\$read, 'invalid JSON: ' . \$e->getMessage());
    }
    if (\$buf !== '') { \$bytesOut += jf_write_all(\$out, \$buf); }
    return ['records_read' => \$read, 'records_matched' => \$matched, 'records_emitted' => \$emitted, 'bytes_read' => \$bytesIn, 'bytes_written' => \$bytesOut];
}

PHP;
    }

    $fgets = <<<'FG'
// fgets() with no length: passing the cap as a length allocates a buffer that size for every line.
FG;
    return <<<PHP
<?php
declare(strict_types=1);

function $fn(\$in, \$out): array
{
    \$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;
    \$read = \$matched = \$emitted = \$bytesIn = \$bytesOut = 0;
    \$buf = '';
    try {
        $fgets
        while ((\$line = fgets(\$in)) !== false) {
            ++\$read;
            \$len = strlen(\$line);
            \$bytesIn += \$len;
            if (\$len > $maxLen && (\$len > $maxWithLf || \$line[\$len - 1] !== "\\n")) { throw jf_err(\$read, 'over record cap'); }
            if (\$line[0] !== '{' && (ltrim(\$line, " \\t\\r\\n")[0] ?? '') !== '{') { throw jf_err(\$read, 'root is not an object'); }
            \$r = $decode;
            if (!is_array(\$r)) { throw jf_err(\$read, 'root is not an object'); }
$checks$filters$skip            ++\$matched;
            \$buf .= $encode($outArray, \$flags) . "\\n";
            ++\$emitted;
            if (strlen(\$buf) >= $flushAt) { \$bytesOut += jf_write_all(\$out, \$buf); \$buf = ''; }
        }
    } catch (\\JsonException | \\SimdJsonException \$e) {
        throw jf_err(\$read, 'invalid JSON: ' . \$e->getMessage());
    }
    if (!feof(\$in)) { throw new RuntimeException('baseline: input read failed'); }
    if (\$buf !== '') { \$bytesOut += jf_write_all(\$out, \$buf); }
    return ['records_read' => \$read, 'records_matched' => \$matched, 'records_emitted' => \$emitted, 'bytes_read' => \$bytesIn, 'bytes_written' => \$bytesOut];
}

PHP;
}
