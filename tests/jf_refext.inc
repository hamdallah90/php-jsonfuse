<?php
/*
 * JfRef (jf_reference.inc) behind the extension's PHP API: JsonFuse\Plan, Runner, Stats and the
 * exception classes, written in plain PHP. Not a test itself.
 *
 * It lets a PHPT written for the extension run on the reference alone, with the extension NOT
 * loaded (php -d auto_prepend_file=tests/jf_refext.inc), which is how jf_where_diff.inc checks the
 * reference against tests/14[0-9]_*.phpt. It models what the reference models:
 * plan compilation, the record rules, output bytes, Stats, flush before throw (every matched
 * record before the failing one is written) and the RecordException getters and progress. It does
 * not model stream failures, fibers, memory limits, the writer's byte clamping, or any float
 * text the spec leaves open (JfRef::floatText() null); such output is written in an approximate
 * form and JsonFuse\Runner::$approximated is set.
 */

namespace JsonFuse;

if (\extension_loaded('jsonfuse')) {
    throw new \LogicException('jf_refext.inc stands in for the jsonfuse extension; load one or the other');
}

require_once __DIR__ . '/jf_reference.inc';

class JsonFuseException extends \RuntimeException
{
    private string $jfReason = '';
    private ?string $jfField = null;

    public function getReason(): string
    {
        return $this->jfReason;
    }

    public function getField(): ?string
    {
        return $this->jfField;
    }
}

class PlanException extends JsonFuseException
{
    private ?string $jfOption = null;

    public function getOption(): ?string
    {
        return $this->jfOption;
    }
}

trait JfRefPosition
{
    private int $jfLine = 0;
    private int $jfRecord = 0;
    private int $jfOffset = 0;
    private ?Stats $jfProgress = null;

    public function getInputLine(): int
    {
        return $this->jfLine;
    }

    public function getRecordNumber(): int
    {
        return $this->jfRecord;
    }

    public function getByteOffset(): int
    {
        return $this->jfOffset;
    }

    public function getProgress(): Stats
    {
        return $this->jfProgress ?? jf_refext_stats(0, 0, 0, 0, 0);
    }
}

class RecordException extends JsonFuseException
{
    use JfRefPosition;
}

class StreamException extends JsonFuseException
{
    use JfRefPosition;
}

final class Stats
{
    public readonly int $recordsRead;
    public readonly int $recordsMatched;
    public readonly int $recordsEmitted;
    public readonly int $bytesRead;
    public readonly int $bytesWritten;

    private function __construct()
    {
    }
}

final class Plan
{
    private array $jfSpec = [];

    private function __construct()
    {
    }

    public static function compile(array $spec): Plan
    {
        $err = \JfRef::compileError($spec);
        if ($err !== null) {
            [$reason, $option, $field] = $err;
            $e = new PlanException($reason);
            jf_refext_set($e, JsonFuseException::class, ['jfReason' => $reason, 'jfField' => $field]);
            jf_refext_set($e, PlanException::class, ['jfOption' => $option]);
            throw $e;
        }
        $p = new Plan();
        $p->jfSpec = $spec;
        return $p;
    }
}

final class Runner
{
    /** True once any run wrote a float whose text the spec leaves open. */
    public static bool $approximated = false;

    private function __construct()
    {
    }

    /** @param resource $input @param resource $output */
    public static function process($input, $output, Plan $plan): Stats
    {
        $spec = (fn () => $this->jfSpec)->call($plan);
        $r = \JfRef::run((string)stream_get_contents($input), $spec);
        $out = '';
        foreach ($r['rows'] as $i => $row) {
            $out .= $r['lines'][$i] ?? self::approximate($row);
        }
        if ($out !== '') {
            fwrite($output, $out);
        }
        if ($r['ok']) {
            return jf_refext_stats($r['read'], $r['matched'], $r['matched'], $r['bytesRead'], strlen($out));
        }
        $e = new RecordException(sprintf('line %d (record %d, offset %d): %s', $r['line'], $r['record'], $r['offset'], $r['reason']));
        jf_refext_set($e, JsonFuseException::class, ['jfReason' => $r['reason'], 'jfField' => $r['field']]);
        jf_refext_set($e, RecordException::class, ['jfLine' => $r['line'], 'jfRecord' => $r['record'], 'jfOffset' => $r['offset'],
            'jfProgress' => jf_refext_stats($r['record'] - 1, $r['matched'], count($r['rows']), $r['offset'], strlen($out))]);
        throw $e;
    }

    private static function approximate(array $row): string
    {
        self::$approximated = true;
        $parts = [];
        foreach ($row as [$name, $kind, $v]) {
            $text = match ($kind) {
                'null' => 'null',
                'bool' => $v ? 'true' : 'false',
                'int' => (string)$v,
                'string' => \JfRef::esc($v),
                'float' => \JfRef::floatText($v) ?? str_replace(',', '.', sprintf('%.17e', $v)),
            };
            $parts[] = \JfRef::esc((string)$name) . ':' . $text;
        }
        return '{' . implode(',', $parts) . "}\n";
    }
}

/** Assign private properties declared by $scope on $obj. */
function jf_refext_set(object $obj, string $scope, array $props): void
{
    \Closure::bind(function () use ($props) {
        foreach ($props as $k => $v) {
            $this->$k = $v;
        }
    }, $obj, $scope)();
}

function jf_refext_stats(int $read, int $matched, int $emitted, int $bytesRead, int $bytesWritten): Stats
{
    $s = (new \ReflectionClass(Stats::class))->newInstanceWithoutConstructor();
    jf_refext_set($s, Stats::class, ['recordsRead' => $read, 'recordsMatched' => $matched, 'recordsEmitted' => $emitted,
        'bytesRead' => $bytesRead, 'bytesWritten' => $bytesWritten]);
    return $s;
}
