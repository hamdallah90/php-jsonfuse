<?php
/*
 * Output-side helpers for the output PHPT tests. Derived from SPEC.md only. Not a test itself.
 *
 * Userland stream wrappers that act as the runner's $output:
 *   jfsink://   has stream_flush();   jfsinknf://   has no stream_flush() at all.
 * Config goes through the context option jfsink/cfg (see JfSinkCore).
 */

trait JfSinkCore
{
    /** @var resource|null */
    public $context;
    public static $last = null;

    public string $data = '';        // every byte accepted so far (when cfg keep is true)
    public int $accepted = 0;        // bytes accepted so far, whether kept or not
    public int $writes = 0;          // stream_write calls
    public int $flushes = 0;
    public int $afterError = 0;      // stream_write calls after the first call that failed
    public int $failures = 0;        // calls in which `mode` fired (PHP's stream layer may retry once: see 061)
    public int $flushAfterError = 0;
    public bool $errored = false;
    public array $events = [];       // 'w' per stream_write, 'f' per stream_flush, in call order
    public array $sizes = [];        // [offered, accepted] per stream_write
    private array $cfg = [];
    private $hash = null;

    /*
     * cfg:
     *   max     most bytes accepted per stream_write (default: all offered)
     *   mode    normal | zero (return 0) | false (return false) | throw (throw `exc`)
     *   after   accepted bytes after which `mode` triggers (default 0); up to `after` bytes are
     *           still accepted, so a failure can land in the middle of a record
     *   exc     Throwable for mode throw and flush `throw`
     *   flush   true (default) | false (return false) | throw      -- jfsink:// only
     *   keep    false: do not retain bytes, keep only a sha256 and the counters
     *   on_write  callable invoked at the start of every stream_write with the call number
     *   on_flush  callable invoked at the start of every stream_flush
     */
    public function stream_open($path, $mode, $options, &$opened)
    {
        $o = stream_context_get_options($this->context);
        $this->cfg = $o['jfsink']['cfg'] ?? [];
        if (($this->cfg['keep'] ?? true) === false) {
            $this->hash = hash_init('sha256');
        }
        self::$last = $this;
        return true;
    }

    public function stream_write($data)
    {
        $this->writes++;
        $this->events[] = 'w';
        if ($this->errored) {
            $this->afterError++;
        }
        if (isset($this->cfg['on_write'])) {
            ($this->cfg['on_write'])($this->writes);
        }
        $mode = $this->cfg['mode'] ?? 'normal';
        $room = PHP_INT_MAX;
        if ($mode !== 'normal') {
            $room = ($this->cfg['after'] ?? 0) - $this->accepted;
            if ($room <= 0) {
                $this->errored = true;
                $this->failures++;
                $this->sizes[] = [strlen($data), 0];
                if ($mode === 'throw') {
                    throw $this->cfg['exc'];
                }
                return $mode === 'zero' ? 0 : false;
            }
        }
        $n = min(strlen($data), $this->cfg['max'] ?? PHP_INT_MAX, $room);
        $this->accept(substr($data, 0, $n));
        $this->sizes[] = [strlen($data), $n];
        return $n;
    }

    private function accept(string $bytes): void
    {
        $this->accepted += strlen($bytes);
        if ($this->hash !== null) {
            hash_update($this->hash, $bytes);
        } else {
            $this->data .= $bytes;
        }
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    public function stream_close()
    {
    }

    public function stream_eof()
    {
        return true;
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }

    public function stream_stat()
    {
        return [];
    }

    private function flushCommon(): void
    {
        $this->flushes++;
        $this->events[] = 'f';
        if ($this->errored) {
            $this->flushAfterError++;
        }
        if (isset($this->cfg['on_flush'])) {
            ($this->cfg['on_flush'])();
        }
    }
}

final class JfSink
{
    use JfSinkCore;

    public function stream_flush()
    {
        $this->flushCommon();
        $f = $this->cfg['flush'] ?? true;
        if ($f === 'throw') {
            throw $this->cfg['exc'];
        }
        return $f;
    }
}

final class JfSinkNf
{
    use JfSinkCore;
}

stream_wrapper_register('jfsink', JfSink::class);
stream_wrapper_register('jfsinknf', JfSinkNf::class);

/** Open an output wrapper stream (with stream_flush). */
function jf_sink(array $cfg = [])
{
    return fopen('jfsink://out', 'wb', false, stream_context_create(['jfsink' => ['cfg' => $cfg]]));
}

/** Open an output wrapper stream that has no stream_flush method. */
function jf_sink_nf(array $cfg = [])
{
    return fopen('jfsinknf://out', 'wb', false, stream_context_create(['jfsink' => ['cfg' => $cfg]]));
}

/** Everything a php://memory output stream holds. */
function jf_out_bytes($out): string
{
    rewind($out);
    return (string)stream_get_contents($out);
}

/** Split output into lines, each keeping its LF; a trailing partial line is kept without one. */
function jf_lines(string $s): array
{
    return $s === '' ? [] : preg_split('/(?<=\n)/', $s, -1, PREG_SPLIT_NO_EMPTY);
}
