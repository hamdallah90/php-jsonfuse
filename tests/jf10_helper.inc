<?php
/*
 * Helpers for the JsonFuse 1.0 PHPT files. Derived from SPEC.md only.
 * Not a test itself.
 *
 * Every Record or Stream exception is printed with its reason, getField(), position
 * (line/record/offset) and progress, and the exception invariants are checked on the
 * spot: progress recordsRead == getRecordNumber() - 1 and bytesRead == getByteOffset().
 */
require_once __DIR__ . '/jf_helper.inc';
require_once __DIR__ . '/jf_outwrap.inc';

use JsonFuse\JsonFuseException;
use JsonFuse\Plan;
use JsonFuse\PlanException;
use JsonFuse\RecordException;
use JsonFuse\Runner;
use JsonFuse\Stats;
use JsonFuse\StreamException;

/** A nullable string getter value, printed unambiguously. */
function jf10_q(?string $v): string
{
    return $v === null ? 'null' : '"' . addcslashes($v, "\0..\37\"\\\177..\377") . '"';
}

/** One line for any Throwable: JsonFuse exceptions with reason and getters, others by class. */
function jf10_exc(Throwable $e): string
{
    if (!$e instanceof JsonFuseException) {
        return get_class($e);
    }
    $s = (new ReflectionClass($e))->getShortName() . ' ' . $e->getReason();
    if ($e instanceof PlanException) {
        return $s . ' option=' . jf10_q($e->getOption()) . ' field=' . jf10_q($e->getField());
    }
    $s .= ' field=' . jf10_q($e->getField());
    if ($e instanceof RecordException || $e instanceof StreamException) {
        $p = $e->getProgress();
        $s .= sprintf(' at=%d/%d/%d [%s]', $e->getInputLine(), $e->getRecordNumber(), $e->getByteOffset(), jf_stats($p));
        // A run always reports record 1 or later; an instance made with new reports 0.
        if ($e->getRecordNumber() > 0 && $p->recordsRead !== $e->getRecordNumber() - 1) {
            $s .= ' BAD-recordsRead-invariant';
        }
        if ($e->getRecordNumber() > 0 && $p->bytesRead !== $e->getByteOffset()) {
            $s .= ' BAD-bytesRead-invariant';
        }
    }
    return $s;
}

/** The exception and its getPrevious() chain, one jf10_exc() per element, joined by " <- ". */
function jf10_chain(Throwable $e): string
{
    $parts = [];
    for ($x = $e; $x !== null; $x = $x->getPrevious()) {
        $parts[] = jf10_exc($x);
    }
    return implode(' <- ', $parts);
}

/** Compile a spec: "ok" or the exception line. */
function jf10_compile(array $spec): string
{
    try {
        Plan::compile($spec);
        return 'ok';
    } catch (Throwable $e) {
        return jf10_exc($e);
    }
}

/**
 * Run $input through $spec. $out is a php://memory stream unless given.
 * Returns [output bytes, "read=... " stats line or exception chain].
 */
function jf10_run(string $input, array $spec, $out = null): array
{
    $in = jf_mem($input);
    $own = $out === null;
    $out ??= jf_out();
    try {
        $r = jf_stats(Runner::process($in, $out, Plan::compile($spec)));
    } catch (Throwable $e) {
        $r = jf10_chain($e);
    }
    $o = $own ? jf_out_bytes($out) : '';
    fclose($in);
    if ($own) {
        fclose($out);
    }
    return [$o, $r];
}

/** Print each output line ("> ...", "[no LF]" on a partial last line), then "= " and the result. */
function jf10_print(string $label, string $output, string $result): void
{
    echo $label, "\n";
    foreach (jf_lines($output) as $l) {
        echo '  > ', rtrim($l, "\n"), str_ends_with($l, "\n") ? '' : ' [no LF]', "\n";
    }
    echo '  = ', $result, "\n";
}

/** Run and print. */
function jf10_show(string $label, string $input, array $spec): void
{
    [$o, $r] = jf10_run($input, $spec);
    jf10_print($label, $o, $r);
}

/**
 * Which record literals match the where entry $entry, declared as where key "v".
 * Record N is $tpl with @N replaced by N and @V by the literal, so the entry's path
 * decides where the literal sits. Returns "[lit lit ...]" or the exception line.
 * With string keys, $literals maps a printable label to each literal and labels are printed.
 */
function jf10_match($entry, array $literals, string $tpl = '{"i":@N,"v":@V}', array $fields = ['i' => 'int64']): string
{
    $in = '';
    foreach (array_values($literals) as $n => $lit) {
        $in .= strtr($tpl, ['@N' => (string)$n, '@V' => $lit]) . "\n";
    }
    [$o, $r] = jf10_run($in, ['fields' => $fields, 'where' => ['v' => $entry]]);
    if (!str_starts_with($r, 'read=')) {
        return $r;
    }
    $hit = [];
    foreach (jf_lines($o) as $l) {
        $n = json_decode($l, true, 512, JSON_THROW_ON_ERROR)['i'];
        $hit[] = array_is_list($literals) ? $literals[$n] : array_keys($literals)[$n];
    }
    return '[' . implode(' ', $hit) . ']';
}

/** fclose() with any warning captured: "fclose=false warning=E_WARNING" or "fclose=true warning=none". */
function jf10_fclose($stream): string
{
    $w = 'none';
    set_error_handler(function (int $no) use (&$w) {
        $w = $no === E_WARNING ? 'E_WARNING' : (string)$no;
        return true;
    });
    try {
        $r = fclose($stream);
    } finally {
        restore_error_handler();
    }
    return 'fclose=' . var_export($r, true) . ' warning=' . $w;
}

/**
 * Userland stream "jfstr://<tag>" over JfStr::$data[<tag>]: readable, seekable, and writable
 * (writes are accepted and dropped after JfStr::$onWrite[<tag>] runs). Its stream_close
 * prints "closed <tag>", so a test sees when PHP frees the stream.
 */
final class JfStr
{
    /** @var resource|null */
    public $context;
    public static array $data = [];
    public static array $onWrite = [];
    private string $tag = '';
    private string $buf = '';
    private int $pos = 0;

    public function stream_open($path, $mode, $options, &$opened)
    {
        $this->tag = substr($path, strlen('jfstr://'));
        $this->buf = self::$data[$this->tag] ?? '';
        return true;
    }

    public function stream_read($count)
    {
        $r = (string)substr($this->buf, $this->pos, $count);
        $this->pos += strlen($r);
        return $r;
    }

    public function stream_write($data)
    {
        if (isset(self::$onWrite[$this->tag])) {
            (self::$onWrite[$this->tag])();
        }
        return strlen($data);
    }

    public function stream_eof()
    {
        return $this->pos >= strlen($this->buf);
    }

    public function stream_seek($offset, $whence)
    {
        if ($whence !== SEEK_SET || $offset < 0) {
            return false;
        }
        $this->pos = $offset;
        return true;
    }

    public function stream_tell()
    {
        return $this->pos;
    }

    public function stream_flush()
    {
        return true;
    }

    public function stream_close()
    {
        echo "  closed {$this->tag}\n";
    }

    public function stream_stat()
    {
        return [];
    }

    public function stream_set_option($option, $arg1, $arg2)
    {
        return false;
    }
}

stream_wrapper_register('jfstr', JfStr::class);

/** Print "label: [matches]" for each [label, entry] row against one literal list. */
function jf10_match_rows(array $rows, array $literals, string $tpl = '{"i":@N,"v":@V}'): void
{
    foreach ($rows as [$label, $entry]) {
        echo str_pad($label, 34), ' ', jf10_match($entry, $literals, $tpl), "\n";
    }
}

/** Records the validating scan accepted so far in this process; null unless the build has -DJF_SCAN_STATS. */
function jf10_scan_accepted(): ?int
{
    ob_start();
    (new ReflectionExtension('jsonfuse'))->info();
    return preg_match('/^validating scan accepted => (\d+)$/m', ob_get_clean(), $m) ? (int)$m[1] : null;
}
