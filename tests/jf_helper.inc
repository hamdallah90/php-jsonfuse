<?php
/*
 * Shared helpers for the JsonFuse PHPT suite. Derived from SPEC.md only.
 * Not a test itself (no .phpt extension).
 */

use JsonFuse\Plan;
use JsonFuse\Runner;
use JsonFuse\Stats;

/**
 * Userland stream wrapper "jfwrap" serving lazily generated input.
 *
 * Config is passed per stream through the context option jfwrap/cfg:
 *   segments   list of [string, repeat] pieces served in order (lazy)
 *   max        max bytes returned per stream_read (default: as requested)
 *   mode       normal | throw_read | throw_eof | false_read | empty_read
 *   after      bytes that must have been served before `mode` triggers
 *   exc        Throwable thrown by throw_* modes
 *   on_read    callable invoked at the start of every stream_read
 *   total      total bytes of the segments (computed)
 */
final class JfWrap
{
    /** @var resource|null */
    public $context;
    public static ?JfWrap $last = null;

    public int $reads = 0;
    public int $served = 0;
    public int $maxChunk = 0;

    private array $cfg = [];
    private int $seg = 0;
    private int $segPos = 0;
    private int $total = 0;

    public function stream_open($path, $mode, $options, &$opened)
    {
        $o = stream_context_get_options($this->context);
        $this->cfg = $o['jfwrap']['cfg'] ?? [];
        foreach ($this->cfg['segments'] ?? [] as [$s, $n]) {
            $this->total += strlen($s) * $n;
        }
        self::$last = $this;
        return true;
    }

    private function triggered(): bool
    {
        return $this->served >= ($this->cfg['after'] ?? 0);
    }

    public function stream_read($count)
    {
        $this->reads++;
        if (isset($this->cfg['on_read'])) {
            ($this->cfg['on_read'])($this->reads);
        }
        $mode = $this->cfg['mode'] ?? 'normal';
        if ($this->triggered()) {
            if ($mode === 'throw_read') {
                throw $this->cfg['exc'];
            }
            if ($mode === 'false_read') {
                return false;
            }
            if ($mode === 'empty_read') {
                return '';
            }
        }
        $want = min($count, $this->cfg['max'] ?? $count);
        $out = '';
        while (strlen($out) < $want && $this->seg < count($this->cfg['segments'])) {
            [$s, $n] = $this->cfg['segments'][$this->seg];
            $len = strlen($s);
            $remaining = $len * $n - $this->segPos;
            if ($remaining <= 0 || $len === 0) {
                $this->seg++;
                $this->segPos = 0;
                continue;
            }
            $take = min($want - strlen($out), $remaining);
            $off = $this->segPos % $len;
            $chunk = substr(str_repeat($s, intdiv($off + $take, $len) + 2), $off, $take);
            $out .= $chunk;
            $this->segPos += $take;
            if ($this->segPos >= $len * $n) {
                $this->seg++;
                $this->segPos = 0;
            }
        }
        $this->served += strlen($out);
        $this->maxChunk = max($this->maxChunk, strlen($out));
        return $out;
    }

    public function stream_eof()
    {
        $mode = $this->cfg['mode'] ?? 'normal';
        if ($this->triggered()) {
            if ($mode === 'throw_eof') {
                throw $this->cfg['exc'];
            }
            if ($mode === 'empty_read') {
                return false;
            }
        }
        return $this->served >= $this->total;
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_close()
    {
    }
}

stream_wrapper_register('jfwrap', JfWrap::class);

/** Exception class used to prove identity is preserved through the runner. */
final class JfBoom extends Exception
{
}

/** Open a lazily generated input stream. */
function jf_gen(array $segments, array $cfg = [])
{
    $cfg['segments'] = $segments;
    $ctx = stream_context_create(['jfwrap' => ['cfg' => $cfg]]);
    return fopen('jfwrap://in', 'rb', false, $ctx);
}

/** Open a wrapper stream serving $data, at most $max bytes per read. */
function jf_wrap(string $data, int $max = 7, array $cfg = [])
{
    $cfg['max'] = $max;
    return jf_gen([[$data, 1]], $cfg);
}

/** Memory input stream positioned at 0 holding $data. */
function jf_mem(string $data)
{
    $f = fopen('php://memory', 'w+b');
    fwrite($f, $data);
    rewind($f);
    return $f;
}

function jf_out()
{
    return fopen('php://memory', 'w+b');
}

function jf_plan(array $over = []): Plan
{
    return Plan::compile($over + ['fields' => ['id' => 'int64']]);
}

/** Stable one-line rendering of a Stats object. */
function jf_stats(Stats $s): string
{
    return sprintf(
        'read=%d matched=%d emitted=%d bytesRead=%d bytesWritten=%d',
        $s->recordsRead, $s->recordsMatched, $s->recordsEmitted, $s->bytesRead, $s->bytesWritten
    );
}

/** Run process() over a string; returns the rendered stats or "Class: ctx". */
function jf_run_str(string $data, array $planOver = []): string
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        return jf_stats(Runner::process($in, $out, jf_plan($planOver)));
    } catch (Throwable $e) {
        return get_class($e) . ' ' . jf_ctx($e->getMessage());
    } finally {
        fclose($in);
        fclose($out);
    }
}

/** Parse the leading "line N (record R, offset O): reason" of a message. */
function jf_ctx(string $msg): string
{
    if (preg_match('/^line (\d+) \(record (\d+), offset (\d+)\): ./', $msg, $m)) {
        return "line=$m[1] record=$m[2] offset=$m[3]";
    }
    return 'no-context';
}

/**
 * Assert the run raises $class with the given context and that the message
 * leaks none of $forbid and is valid UTF-8. Returns "ok" or a diagnosis.
 */
function jf_expect_error(string $data, string $class, string $ctx, array $forbid = [], array $planOver = []): string
{
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $s = Runner::process($in, $out, jf_plan($planOver));
        return 'FAIL no exception, ' . jf_stats($s);
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (get_class($e) !== $class) {
            return 'FAIL class ' . get_class($e);
        }
        if (jf_ctx($m) !== $ctx) {
            return 'FAIL ctx ' . jf_ctx($m) . ' want ' . $ctx;
        }
        foreach ($forbid as $f) {
            if ($f !== '' && str_contains($m, $f)) {
                return 'FAIL message leaks ' . bin2hex($f);
            }
        }
        if (!preg_match('//u', $m)) {
            return 'FAIL message not valid UTF-8';
        }
        if (strlen($data) > 4 && str_contains($m, rtrim(substr($data, 0, 40), "\r\n"))) {
            return 'FAIL message contains record prefix';
        }
        return 'ok';
    } finally {
        fclose($in);
        fclose($out);
    }
}

/** Run a callable, return [class, message] of the thrown Throwable or null. */
function jf_try(callable $fn): ?array
{
    try {
        $fn();
        return null;
    } catch (Throwable $e) {
        return [get_class($e), $e->getMessage()];
    }
}

function jf_tmp(string $tag): string
{
    $p = tempnam(sys_get_temp_dir(), 'jsonfuse_' . $tag . '_');
    if ($p === false) {
        throw new RuntimeException('tempnam failed');
    }
    return $p;
}

/** Remove leftovers of a test's temp files (used by --CLEAN--). */
function jf_clean(string $tag): void
{
    foreach (glob(sys_get_temp_dir() . '/jsonfuse_' . $tag . '_*') ?: [] as $f) {
        @unlink($f);
    }
}

/** A JSON object text of exactly $n bytes (n >= 8). */
function jf_rec(int $n): string
{
    return '{"k":"' . str_repeat('a', $n - 8) . '"}';
}
