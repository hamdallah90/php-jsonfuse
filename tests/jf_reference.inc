<?php
/*
 * Independent plain-PHP reference of the SPEC.md section 5 record semantics.
 * Derived from SPEC.md only; shares no code with the extension. Not a test itself.
 *
 * Where json_decode() can represent a distinction it is used as a second opinion by
 * 035_reference_differential.phpt (syntax verdict, int vs real literal). It cannot
 * observe the following rules, so this file inspects raw tokens instead:
 *   - duplicate keys (json_decode silently keeps the last value);
 *   - integer literal vs real literal for values it widens (`1e400` and a 400-digit
 *     integer both come back as INF, 2^63..2^64-1 and RAW integers both as float);
 *   - the SINT / UINT / RAW split of the number rules table;
 *   - key identity after \uXXXX decoding when two keys collapse into one array slot;
 *   - nesting limit: json_decode counts depth differently for scalars.
 *
 * Output: the projected output (JfRef::project / line / esc / floatText) and the
 * emitted/bytes columns of run() and render(). The output form assumed throughout is the
 * compact one, `{"a":1,"b":"x"}` + LF (no spaces), which is the only form json_encode and
 * the SPEC.md section 6 reserve formula (a per-field `"name":` prefix) suggest; SPEC.md does not
 * spell it out.
 *
 * Defaults: array field specs ['type' => ..., 'default' => ...] (plan()), the PlanException a
 * fields option raises (fieldsError(), including the int float64 default check by hand), and
 * defaults emitted for missing keys (record(), project()).
 *
 * Paths: field paths (plan(), a field's sixth element), their compile rules (compileError():
 * the path's shape, int and other non-string segments, segment bytes, equal paths and strict
 * prefixes across sections, each naming the later entry), the walk over every object reached
 * through a path node with duplicates at every referenced level (walk()), and path resolution
 * with path_not_object (resolve()).
 *
 * Where entries: both forms, a scalar (the same as ['=' => scalar]) and an array of an optional
 * path and one or more operators (plan()); their compile rules (whereEntryError(): unknown keys
 * before values, the path rules shared with fields, an entry without an operator, '=' and 'in'
 * operands, 257 'in' members as too_many, range operands and the one-kind rule), the top-level
 * options (topLevelError()); where paths in the walk and in step 6 (path_not_object,
 * where_not_scalar); and the operators (opHolds()): '=' and 'in' by the type-strict equality,
 * string ranges with strcmp(), number ranges by exact value with the int/real comparison done by
 * hand on the binary64 bits (cmpIntReal()), never by converting an integer to float.
 * JfRef::$onCompare, when set, sees
 * every operator evaluation (the where differential counts them).
 */

final class JfRefSyntax extends Exception
{
}

/** Deterministic xorshift32 so corpora do not depend on mt_rand() across PHP versions. */
final class JfRng
{
    private int $x;

    public function __construct(int $seed)
    {
        $this->x = ($seed & 0xFFFFFFFF) ?: 0x9E3779B9;
        for ($i = 0; $i < 8; $i++) {
            $this->next();
        }
    }

    public function next(): int
    {
        $x = $this->x;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= $x >> 17;
        $x ^= ($x << 5) & 0xFFFFFFFF;
        return $this->x = $x & 0xFFFFFFFF;
    }

    /** Integer in [0, $n). */
    public function int(int $n): int
    {
        return $this->next() % $n;
    }

    /** True with probability $percent / 100. */
    public function chance(int $percent): bool
    {
        return $this->int(100) < $percent;
    }

    public function pick(array $list)
    {
        return $list[$this->int(count($list))];
    }
}

final class JfRef
{
    private const INT_MAX = '9223372036854775807';
    private const INT_MIN_MAG = '9223372036854775808';
    private const UINT_MAX = '18446744073709551615';
    private const TOP_OPTIONS = ['fields', 'where', 'output', 'maxRecordBytes', 'skipEmptyLines'];
    private const WHERE_KEYS = ['path', '=', '>', '>=', '<', '<=', 'in'];

    /** Called as fn(string $op, $operand, array $value) for every operator evaluated by record(); null for none. */
    public static $onCompare = null;

    /**
     * Normalise a Plan::compile() spec that compiles into the reference's plan model. A field is
     * [name, type, nullable, hasDefault, default, path]; a where entry [name, ops, path] with ops a
     * list of [operator, operand] in declared order (a scalar entry v is [['=', v]]).
     */
    public static function plan(array $spec): array
    {
        $fields = [];
        foreach ($spec['fields'] as $name => $fs) {
            $type = is_array($fs) ? $fs['type'] : $fs;
            $nullable = str_ends_with($type, '?');
            $hasDefault = is_array($fs) && array_key_exists('default', $fs);
            $path = is_array($fs) && array_key_exists('path', $fs) ? $fs['path'] : [(string)$name];
            $fields[] = [(string)$name, $nullable ? substr($type, 0, -1) : $type, $nullable, $hasDefault, $hasDefault ? $fs['default'] : null, $path];
        }
        $where = [];
        foreach ($spec['where'] ?? [] as $k => $v) {
            $name = (string)$k;
            if (!is_array($v)) {
                $where[] = [$name, [['=', $v]], [$name]];
                continue;
            }
            $ops = [];
            foreach ($v as $op => $operand) {
                if ($op !== 'path') {
                    $ops[] = [(string)$op, $operand];
                }
            }
            $where[] = [$name, $ops, array_key_exists('path', $v) ? $v['path'] : [$name]];
        }
        return [
            'fields' => $fields,
            'where' => $where,
            'cap' => $spec['maxRecordBytes'] ?? 1048576,
            'skip' => $spec['skipEmptyLines'] ?? false,
        ];
    }

    private static function textOk($s): bool
    {
        return is_string($s) && strlen($s) <= 1024 && preg_match('//u', $s) === 1;
    }

    /** By hand: |i| = m * 2^k with m odd is exact in binary64 when m < 2^53. */
    public static function intIsBinary64(int $i): bool
    {
        if ($i === PHP_INT_MIN) {
            return true;
        }
        $m = abs($i);
        while ($m !== 0 && ($m & 1) === 0) {
            $m >>= 1;
        }
        return $m < 9007199254740992;
    }

    /** The default matches the declared type with no conversion; null only when nullable. */
    public static function defaultOk(string $type, bool $nullable, $d): bool
    {
        if ($d === null) {
            return $nullable;
        }
        return match ($type) {
            'int64' => is_int($d),
            'float64' => (is_float($d) && is_finite($d)) || (is_int($d) && self::intIsBinary64($d)),
            'bool' => is_bool($d),
            'string' => self::textOk($d),
        };
    }

    /** True when list $a is a strict prefix of list $b. */
    private static function strictPrefix(array $a, array $b): bool
    {
        return count($a) < count($b) && array_slice($b, 0, count($a)) === $a;
    }

    /** A scalar as '=', 'in' members and the scalar where form take it. */
    private static function scalarOk($v): bool
    {
        return is_int($v) || is_bool($v) || $v === null || (is_float($v) && is_finite($v)) || self::textOk($v);
    }

    /** A path := non-empty list of 1..8 strings: invalid_path for the shape or a non-string segment, invalid_name for a segment's bytes. */
    private static function pathError($path): ?string
    {
        if (!is_array($path) || $path === [] || count($path) > 8 || !array_is_list($path)) {
            return 'invalid_path';
        }
        foreach ($path as $seg) {
            if (!is_string($seg)) {
                return 'invalid_path';
            }
            if (!self::textOk($seg)) {
                return 'invalid_name';
            }
        }
        return null;
    }

    /**
     * The top-level PlanException [reason, option, null] of one part of the
     * fault order (the fixed sequence), or null. 'fields': unknown
     * keys, then a missing fields, then the fields option itself; 'where': the where option itself;
     * 'options': output, maxRecordBytes, skipEmptyLines. compileError() checks each part's entries
     * right after it, and the options after every entry but before cross-entry conflicts (149).
     */
    private static function topLevelError(array $spec, string $part): ?array
    {
        if ($part === 'fields') {
            foreach (array_keys($spec) as $k) {
                if (!in_array($k, self::TOP_OPTIONS, true)) {
                    return ['unknown_option', null, null];
                }
            }
            if (!array_key_exists('fields', $spec)) {
                return ['missing_option', 'fields', null];
            }
            if (!is_array($spec['fields']) || $spec['fields'] === []) {
                return ['invalid_option', 'fields', null];
            }
            return count($spec['fields']) > 128 ? ['too_many', 'fields', null] : null;
        }
        if ($part === 'where') {
            if (!array_key_exists('where', $spec)) {
                return null;
            }
            if (!is_array($spec['where'])) {
                return ['invalid_option', 'where', null];
            }
            return count($spec['where']) > 16 ? ['too_many', 'where', null] : null;
        }
        if (array_key_exists('output', $spec) && $spec['output'] !== 'ndjson') {
            return ['invalid_option', 'output', null];
        }
        if (array_key_exists('maxRecordBytes', $spec)) {
            $m = $spec['maxRecordBytes'];
            if (!is_int($m) || $m < 1 || $m > 67108864) {
                return ['invalid_option', 'maxRecordBytes', null];
            }
        }
        if (array_key_exists('skipEmptyLines', $spec) && !is_bool($spec['skipEmptyLines'])) {
            return ['invalid_option', 'skipEmptyLines', null];
        }
        return null;
    }

    /**
     * The reason an array-form where entry fails to compile, or null.
     * Keys before values: any key outside path, =, >, >=, <, <=, in is
     * unknown_option, int keys included. Then the path, then the operators: none at all, a bad
     * '=' operand, an 'in' operand that is not a non-empty list or holds a bad member, a range
     * operand that is neither a number (int, finite float) nor a string of valid UTF-8 up to 1024
     * bytes, or number and string range operands in one entry are invalid_value; more than 256
     * 'in' members is too_many. The order is: keys, path, operators in key
     * order (the 'in' count before its members), then no operator and the one-kind rule (149).
     */
    private static function whereEntryError(array $entry): ?string
    {
        foreach (array_keys($entry) as $k) {
            if (!in_array($k, self::WHERE_KEYS, true)) {
                return 'unknown_option';
            }
        }
        if (array_key_exists('path', $entry) && ($p = self::pathError($entry['path'])) !== null) {
            return $p;
        }
        $ops = 0;
        $kinds = [];
        foreach ($entry as $op => $operand) {
            if ($op === 'path') {
                continue;
            }
            $ops++;
            if ($op === '=') {
                if (!self::scalarOk($operand)) {
                    return 'invalid_value';
                }
            } elseif ($op === 'in') {
                if (!is_array($operand) || $operand === [] || !array_is_list($operand)) {
                    return 'invalid_value';
                }
                if (count($operand) > 256) {
                    return 'too_many';
                }
                foreach ($operand as $m) {
                    if (!self::scalarOk($m)) {
                        return 'invalid_value';
                    }
                }
            } elseif (is_int($operand) || (is_float($operand) && is_finite($operand))) {
                $kinds['number'] = true;
            } elseif (self::textOk($operand)) {
                $kinds['string'] = true;
            } else {
                return 'invalid_value';
            }
        }
        return $ops === 0 || count($kinds) > 1 ? 'invalid_value' : null;
    }

    /**
     * The PlanException [reason, option, field] Plan::compile() raises for $spec, or null if it
     * compiles, in the fault order: unknown keys, a missing fields and the fields
     * option (topLevelError()), then the fields entries (name, keys before values, type, path,
     * default), then the where option, then the where entries (name, then the scalar value or
     * whereEntryError()), then output, maxRecordBytes and skipEmptyLines, then entry by entry in
     * declared order (fields before where)
     * equal names, paths equal to an earlier path of the same section (duplicate_name) and paths
     * in a strict prefix relation with any earlier path (invalid_path), naming the later entry.
     * Within one section the first faulty entry in declared order is reported, and every
     * per-entry fault comes before every cross-entry fault (the extension agrees for fields).
     */
    public static function compileError(array $spec): ?array
    {
        if (($top = self::topLevelError($spec, 'fields')) !== null) {
            return $top;
        }
        $types =['int64', 'float64', 'bool', 'string', 'int64?', 'float64?', 'bool?', 'string?'];
        foreach ($spec['fields'] as $name => $fs) {
            $name = (string)$name;
            if (!self::textOk($name)) {
                return ['invalid_name', 'fields', null];
            }
            if (is_array($fs)) {
                foreach (array_keys($fs) as $k) {
                    if ($k !== 'type' && $k !== 'default' && $k !== 'path') {
                        return ['unknown_option', 'fields', $name];
                    }
                }
                $type = $fs['type'] ?? null;
            } else {
                $type = $fs;
            }
            if (!in_array($type, $types, true)) {
                return ['invalid_type', 'fields', $name];
            }
            if (is_array($fs) && array_key_exists('path', $fs) && ($p = self::pathError($fs['path'])) !== null) {
                return [$p, 'fields', $p === 'invalid_name' ? null : $name];
            }
            if (is_array($fs) && array_key_exists('default', $fs)
                    && !self::defaultOk(rtrim($type, '?'), str_ends_with($type, '?'), $fs['default'])) {
                return ['invalid_value', 'fields', $name];
            }
        }
        if (($top = self::topLevelError($spec, 'where')) !== null) {
            return $top;
        }
        foreach ($spec['where'] ?? [] as $name => $v) {
            $name = (string)$name;
            if (!self::textOk($name)) {
                return ['invalid_name', 'where', null];
            }
            $e = is_array($v) ? self::whereEntryError($v) : (self::scalarOk($v) ? null : 'invalid_value');
            if ($e !== null) {
                return [$e, 'where', $e === 'invalid_name' || $e === 'too_many' ? null : $name];
            }
        }
        if (($top = self::topLevelError($spec, 'options')) !== null) {
            return $top;
        }
        $plan = self::plan($spec);
        $earlier = [];
        foreach ([['fields', $plan['fields'], 5], ['where', $plan['where'], 2]] as [$option, $entries, $at]) {
            $names = [];
            foreach ($entries as $e) {
                [$name, $path] = [$e[0], $e[$at]];
                if (isset($names[$name])) {
                    return ['duplicate_name', $option, $name];
                }
                $names[$name] = true;
                foreach ($earlier as [$o, $p]) {
                    if (self::strictPrefix($p, $path) || self::strictPrefix($path, $p)) {
                        return ['invalid_path', $option, $name];
                    }
                    if ($o === $option && $p === $path) {
                        return ['duplicate_name', $option, $name];
                    }
                }
                $earlier[] = [$option, $path];
            }
        }
        return null;
    }

    /** A default as a projected row cell [kind, value] (see project()). */
    private static function defaultCell(string $type, $d): array
    {
        if ($d === null) {
            return ['null', null];
        }
        return match ($type) {
            'string' => ['string', $d],
            'bool' => ['bool', $d],
            'int64' => ['int', $d],
            default => ['float', (float)$d],
        };
    }

    // ---- number rules --------------------------------------------------------------

    private static function cmpDigits(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
    }

    /**
     * Classify a number token. Returns [isInteger, class, float|null] where class is
     * I64 (fits int64: yyjson SINT/small UINT), U64 (2^63..2^64-1), REAL (finite
     * real literal) or RAW (integer outside int64/uint64, or real overflowing binary64).
     */
    public static function number(string $raw): array
    {
        if (!preg_match('/[.eE]/', $raw)) {
            $neg = $raw[0] === '-';
            $mag = $neg ? substr($raw, 1) : $raw;
            if ($neg ? self::cmpDigits($mag, self::INT_MIN_MAG) <= 0 : self::cmpDigits($mag, self::INT_MAX) <= 0) {
                return [true, 'I64', null];
            }
            if (!$neg && self::cmpDigits($mag, self::UINT_MAX) <= 0) {
                return [true, 'U64', null];
            }
            return [true, 'RAW', (float)$raw];
        }
        $f = (float)$raw;
        return [false, is_infinite($f) ? 'RAW' : 'REAL', $f];
    }

    private static function fieldOk(string $type, array $v): bool
    {
        switch ($type) {
            case 'string':
                return $v['k'] === 'string';
            case 'bool':
                return $v['k'] === 'true' || $v['k'] === 'false';
            case 'int64':
                return $v['k'] === 'number' && self::number($v['raw'])[0] && self::number($v['raw'])[1] === 'I64';
            case 'float64':
                if ($v['k'] !== 'number') {
                    return false;
                }
                [$isInt, $class, $f] = self::number($v['raw']);
                if ($isInt) {
                    return $class !== 'RAW' || is_finite($f);
                }
                return $class === 'REAL';
        }
        throw new LogicException("unknown type $type");
    }

    private static function whereMatches($want, array $v): bool
    {
        if (is_string($want)) {
            return $v['k'] === 'string' && $v['s'] === $want;
        }
        if (is_bool($want)) {
            return $v['k'] === ($want ? 'true' : 'false');
        }
        if ($want === null) {
            return $v['k'] === 'null';
        }
        if ($v['k'] !== 'number') {
            return false;
        }
        [$isInt, $class, $f] = self::number($v['raw']);
        if (is_int($want)) {
            // an integer literal of equal value; "-0" is the integer 0
            if (!$isInt || $class !== 'I64') {
                return false;
            }
            return (string)$want === ($v['raw'] === '-0' ? '0' : $v['raw']);
        }
        // float: only a literal with a fraction or exponent, by IEEE equality; RAW never matches
        return !$isInt && $class === 'REAL' && $f == $want;
    }

    // ---- where operators -------------------------------------------------

    /** -1, 0 or 1 by the sign of $c. */
    private static function sign(int $c): int
    {
        return $c <=> 0;
    }

    /** An exact integer ['i', negative, decimal magnitude] (zero is never negative). */
    private static function exactInt(int $i): array
    {
        return $i < 0 ? ['i', true, substr((string)$i, 1)] : ['i', false, (string)$i];
    }

    /**
     * A record value as a number range compares it: ['i', negative, magnitude] for a JSON integer
     * in [-2^63, 2^64-1], ['r', float] for a REAL, null for anything else (a RAW number, a string,
     * a bool, null), which never matches and is not an error.
     */
    private static function rangeNumber(array $v): ?array
    {
        if ($v['k'] !== 'number') {
            return null;
        }
        [$isInt, $class, $f] = self::number($v['raw']);
        if ($class === 'RAW') {
            return null;
        }
        if (!$isInt) {
            return ['r', $f];
        }
        $neg = $v['raw'][0] === '-';
        $mag = $neg ? substr($v['raw'], 1) : $v['raw'];
        return ['i', $neg && $mag !== '0', $mag];
    }

    /** Two exact integers. */
    private static function cmpInt(array $a, array $b): int
    {
        if ($a[1] !== $b[1]) {
            return $a[1] ? -1 : 1;
        }
        $c = self::sign(strlen($a[2]) <=> strlen($b[2]) ?: strcmp($a[2], $b[2]));
        return $a[1] ? -$c : $c;
    }

    /** Decimal digits times two. */
    private static function twice(string $digits): string
    {
        $out = '';
        $carry = 0;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $x = 2 * (ord($digits[$i]) - 48) + $carry;
            $out = chr(48 + $x % 10) . $out;
            $carry = intdiv($x, 10);
        }
        return $carry ? '1' . $out : $out;
    }

    /**
     * The truncation of a finite $d with |$d| < 2^64, read off its binary64 bits:
     * [negative, decimal magnitude, has a non-zero fractional remainder].
     */
    private static function truncate(float $d): array
    {
        $b = pack('E', $d);
        $exp = ((ord($b[0]) & 0x7F) << 4) | (ord($b[1]) >> 4);
        $frac = ord($b[1]) & 0x0F;
        for ($i = 2; $i < 8; $i++) {
            $frac = ($frac << 8) | ord($b[$i]);
        }
        // |d| = m * 2^e with m < 2^53
        $m = $exp === 0 ? $frac : $frac | (1 << 52);
        $e = ($exp === 0 ? 1 : $exp) - 1075;
        if ($e >= 0) {
            $mag = (string)$m;
            for ($k = 0; $k < $e; $k++) {
                $mag = self::twice($mag);
            }
            $rem = false;
        } elseif (-$e > 53) {
            [$mag, $rem] = ['0', $m !== 0];
        } else {
            $mag = (string)($m >> -$e);
            $rem = ($m & ((1 << -$e) - 1)) !== 0;
        }
        return [(ord($b[0]) & 0x80) !== 0 && $mag !== '0', $mag, $rem];
    }

    /**
     * An integer against a real compares the integer with the real's truncation,
     * then the real's sign of remainder. The integer is never converted to float. Integers lie in
     * [-2^63, 2^64-1], so a real of magnitude 2^64 or more is decided by its sign alone.
     */
    private static function cmpIntReal(array $i, float $d): int
    {
        if ($d >= 18446744073709551616.0) {
            return -1;
        }
        if ($d <= -18446744073709551616.0) {
            return 1;
        }
        [$neg, $mag, $rem] = self::truncate($d);
        $c = self::cmpInt($i, ['i', $neg, $mag]);
        if ($c !== 0 || !$rem) {
            return $c;
        }
        return $d > 0 ? -1 : 1;
    }

    /** Exact order of two numbers ['i', ...] or ['r', float]: two reals by IEEE order (-0.0 equals 0.0). */
    private static function cmpExact(array $a, array $b): int
    {
        if ($a[0] === 'r' && $b[0] === 'r') {
            return $a[1] <=> $b[1];
        }
        if ($a[0] === 'i' && $b[0] === 'i') {
            return self::cmpInt($a, $b);
        }
        return $a[0] === 'i' ? self::cmpIntReal($a, $b[1]) : -self::cmpIntReal($b, $a[1]);
    }

    /** One operator of a compiled entry against a present scalar record value. */
    private static function opHolds(string $op, $operand, array $v): bool
    {
        if (self::$onCompare !== null) {
            (self::$onCompare)($op, $operand, $v);
        }
        if ($op === '=') {
            return self::whereMatches($operand, $v);
        }
        if ($op === 'in') {
            foreach ($operand as $m) {
                if (self::whereMatches($m, $v)) {
                    return true;
                }
            }
            return false;
        }
        if (is_string($operand)) {
            // bytewise as unsigned bytes after escape decoding, shorter prefix first
            if ($v['k'] !== 'string') {
                return false;
            }
            $c = self::sign(strcmp($v['s'], $operand));
        } else {
            $n = self::rangeNumber($v);
            if ($n === null) {
                return false;
            }
            $c = self::cmpExact($n, is_int($operand) ? self::exactInt($operand) : ['r', $operand]);
        }
        return match ($op) {
            '>' => $c > 0,
            '>=' => $c >= 0,
            '<' => $c < 0,
            '<=' => $c <= 0,
        };
    }

    // ---- strict JSON scanner -------------------------------------------------------

    private static function utf8(int $cp): string
    {
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
        }
        return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
    }

    private static function ws(string $t, int &$i): void
    {
        $n = strlen($t);
        while ($i < $n && ($t[$i] === ' ' || $t[$i] === "\t" || $t[$i] === "\n" || $t[$i] === "\r")) {
            $i++;
        }
    }

    /** Parse a string starting at the opening quote; returns the decoded bytes. */
    private static function str(string $t, int &$i): string
    {
        $n = strlen($t);
        $i++;
        $out = '';
        while (true) {
            preg_match('/\G[^"\\\\\x00-\x1f]*/', $t, $m, 0, $i);
            $out .= $m[0];
            $i += strlen($m[0]);
            if ($i >= $n) {
                throw new JfRefSyntax('unterminated string');
            }
            $c = $t[$i];
            if ($c === '"') {
                $i++;
                return $out;
            }
            if ($c !== '\\') {
                throw new JfRefSyntax('control character in string');
            }
            $e = $t[$i + 1] ?? '';
            $simple = ['"' => '"', '\\' => '\\', '/' => '/', 'b' => "\x08", 'f' => "\x0c", 'n' => "\n", 'r' => "\r", 't' => "\t"];
            if (isset($simple[$e])) {
                $out .= $simple[$e];
                $i += 2;
                continue;
            }
            if ($e !== 'u' || !preg_match('/\G[0-9a-fA-F]{4}/', $t, $h, 0, $i + 2)) {
                throw new JfRefSyntax('bad escape');
            }
            $cp = hexdec($h[0]);
            $i += 6;
            if ($cp >= 0xDC00 && $cp <= 0xDFFF) {
                throw new JfRefSyntax('lone low surrogate');
            }
            if ($cp >= 0xD800 && $cp <= 0xDBFF) {
                if (!preg_match('/\G\\\\u([dD][c-fC-F][0-9a-fA-F]{2})/', $t, $lo, 0, $i)) {
                    throw new JfRefSyntax('lone high surrogate');
                }
                $cp = 0x10000 + (($cp - 0xD800) << 10) + (hexdec($lo[1]) - 0xDC00);
                $i += 6;
            }
            $out .= self::utf8($cp);
        }
    }

    /**
     * Parse one value at $i. $level is the nesting level this value would occupy if it
     * is a container (root object = 1). $collect makes an object return its members.
     */
    private static function value(string $t, int &$i, int $level, bool $collect = false): array
    {
        $n = strlen($t);
        if ($i >= $n) {
            throw new JfRefSyntax('unexpected end');
        }
        $c = $t[$i];
        if ($c === '"') {
            return ['k' => 'string', 's' => self::str($t, $i)];
        }
        if ($c === '{' || $c === '[') {
            if ($level > 511) {
                throw new JfRefSyntax('too deep');
            }
            $close = $c === '{' ? '}' : ']';
            $members = [];
            $i++;
            self::ws($t, $i);
            if (($t[$i] ?? '') === $close) {
                $i++;
                return ['k' => $c === '{' ? 'object' : 'array', 'members' => $members];
            }
            while (true) {
                self::ws($t, $i);
                $key = null;
                if ($c === '{') {
                    if (($t[$i] ?? '') !== '"') {
                        throw new JfRefSyntax('expected key');
                    }
                    $key = self::str($t, $i);
                    self::ws($t, $i);
                    if (($t[$i] ?? '') !== ':') {
                        throw new JfRefSyntax('expected colon');
                    }
                    $i++;
                    self::ws($t, $i);
                }
                $v = self::value($t, $i, $level + 1, $collect);
                if ($collect) {
                    $members[] = [$key, $v];
                }
                self::ws($t, $i);
                $d = $t[$i] ?? '';
                $i++;
                if ($d === $close) {
                    return ['k' => $c === '{' ? 'object' : 'array', 'members' => $members];
                }
                if ($d !== ',') {
                    throw new JfRefSyntax('expected comma');
                }
            }
        }
        foreach (['true', 'false', 'null'] as $lit) {
            if (substr($t, $i, strlen($lit)) === $lit) {
                $i += strlen($lit);
                return ['k' => $lit];
            }
        }
        if (preg_match('/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $t, $m, 0, $i)) {
            $i += strlen($m[0]);
            return ['k' => 'number', 'raw' => $m[0]];
        }
        throw new JfRefSyntax('unexpected token');
    }

    /** Steps 2 and 3: syntax + UTF-8, then root is an object. Returns the root members. */
    private static function scan(string $text): array
    {
        if (preg_match('//u', $text) !== 1) {
            throw new JfRefSyntax('invalid UTF-8');
        }
        $i = 0;
        self::ws($text, $i);
        $root = self::value($text, $i, 1, true);
        self::ws($text, $i);
        if ($i !== strlen($text)) {
            throw new JfRefSyntax('trailing content');
        }
        if ($root['k'] !== 'object') {
            throw new JfRefSyntax('root is not an object', 3);
        }
        return $root['members'];
    }

    /** True when json_decode-independent scanning accepts the text as JSON (any root). */
    public static function syntaxOk(string $text): bool
    {
        try {
            if (preg_match('//u', $text) !== 1) {
                return false;
            }
            $i = 0;
            self::ws($text, $i);
            self::value($text, $i, 1);
            self::ws($text, $i);
            return $i === strlen($text);
        } catch (JfRefSyntax) {
            return false;
        }
    }

    /** Decoded keys of the root object, in order, duplicates included (null when not a valid object record). */
    public static function rootKeys(string $text): ?array
    {
        try {
            return array_map(static fn(array $m): string => $m[0], self::scan($text));
        } catch (JfRefSyntax) {
            return null;
        }
    }

    // ---- output (SPEC.md section 6, Native output writer) --------------------------

    /** Root members [[decoded key, node], ...] of a valid object record, duplicates included. */
    public static function members(string $text): array
    {
        return self::scan($text);
    }

    /** The SPEC.md section 6 escape table: `"` `\\` `\b` `\f` `\n` `\r` `\t`, other bytes below 0x20 as lower-case \u00xx. */
    public static function esc(string $s): string
    {
        static $t = null;
        if ($t === null) {
            $t = [];
            for ($i = 0; $i < 32; $i++) {
                $t[chr($i)] = sprintf('\u%04x', $i);
            }
            $t["\x08"] = '\b';
            $t["\x0c"] = '\f';
            $t["\n"] = '\n';
            $t["\r"] = '\r';
            $t["\t"] = '\t';
            $t['"'] = '\"';
            $t['\\'] = '\\\\';
        }
        return '"' . strtr($s, $t) . '"';
    }

    /** [digits, exp10] of the shortest round-trip decimal of a finite non-zero $abs (d.ddd x 10^exp10). */
    public static function shortest(float $abs): array
    {
        $t = '';
        for ($p = 1; $p <= 17; $p++) {
            $t = sprintf('%.' . ($p - 1) . 'e', $abs);
            if ((float)str_replace(',', '.', $t) === $abs) {
                break;
            }
        }
        [$m, $e] = explode('e', $t);
        return [rtrim(preg_replace('/\D/', '', $m), '0') ?: '0', (int)$e];
    }

    /**
     * Text of a float64 field value where SPEC.md determines it, else null. Determined:
     * the zeros, 0.1 <= |v| < 1e21 in fixed notation with at least one fractional digit
     * (shortest digits; zero padding only where that is also the exact integer, so the
     * shortest-digits vs exact-expansion question for 2^63 / 2^64 stays open), |v| >= 1e21
     * as `d[.ddd]e<exp>` and 5e-324. Everything else (small magnitudes) is left to the bits.
     */
    public static function floatText(float $f): ?string
    {
        if ($f == 0.0) {
            return fdiv(1, $f) < 0 ? '-0.0' : '0.0';
        }
        $sign = $f < 0 ? '-' : '';
        $abs = abs($f);
        [$d, $e] = self::shortest($abs);
        if ($e >= 21) {
            return $sign . $d[0] . (strlen($d) > 1 ? '.' . substr($d, 1) : '') . 'e' . $e;
        }
        if ($e < -1) {
            return $abs === 4.9406564584124654e-324 ? $sign . '5e-324' : null;
        }
        if ($e === -1) {
            return $sign . '0.' . $d;
        }
        if (strlen($d) > $e + 1) {
            return $sign . substr($d, 0, $e + 1) . '.' . substr($d, $e + 1);
        }
        $pad = str_pad($d, $e + 1, '0');
        return sprintf('%.0f', $abs) === $pad ? $sign . $pad . '.0' : null;
    }

    /**
     * Expected projected row of a record known to match: [[name, kind, value], ...] in schema
     * order, kind one of int, float, bool, string, null. `-0` is the integer 0 / positive 0.0.
     */
    public static function project(string $text, array $plan): array
    {
        $members = self::scan($text);
        $row = [];
        foreach ($plan['fields'] as [$name, $type, , , $default, $path]) {
            $v = self::resolve($members, $path);
            if ($v === null) {
                // record() let a path be missing only from a field with a default
                $row[] = [$name, ...self::defaultCell($type, $default)];
                continue;
            }
            if ($v['k'] === 'null') {
                $row[] = [$name, 'null', null];
                continue;
            }
            $row[] = match ($type) {
                'string' => [$name, 'string', $v['s']],
                'bool' => [$name, 'bool', $v['k'] === 'true'],
                'int64' => [$name, 'int', $v['raw'] === '-0' ? 0 : (int)$v['raw']],
                default => [$name, 'float', $v['raw'] === '-0' ? 0.0 : (float)$v['raw']],
            };
        }
        return $row;
    }

    /** Exact output line (with LF) of a projected row, or null when a float's text is not determined by the spec. */
    public static function line(array $row): ?string
    {
        $parts = [];
        foreach ($row as [$name, $kind, $v]) {
            $val = match ($kind) {
                'null' => 'null',
                'bool' => $v ? 'true' : 'false',
                'int' => (string)$v,
                'string' => self::esc($v),
                'float' => self::floatText($v),
            };
            if ($val === null) {
                return null;
            }
            $parts[] = self::esc((string)$name) . ':' . $val;
        }
        return '{' . implode(',', $parts) . "}\n";
    }

    // ---- per-record validation (SPEC.md section 5, validation order) ----------------

    /**
     * Validate one record's JSON text (after framing). Returns
     * ['ok'=>true,'match'=>bool] or ['ok'=>false,'step'=>int,'field'=>?string].
     * The size cap (step 1) is applied by run().
     */
    public static function record(string $text, array $plan): array
    {
        try {
            $members = self::scan($text);
        } catch (JfRefSyntax $e) {
            return $e->getCode() === 3
                ? ['ok' => false, 'step' => 3, 'reason' => 'not_object', 'field' => null]
                : ['ok' => false, 'step' => 2, 'reason' => 'invalid_json', 'field' => null];
        }
        $visited = [];
        $dup = self::walk($members, [], $plan, $visited);
        if ($dup !== null) {
            return ['ok' => false, 'step' => 4, 'reason' => 'duplicate_key', 'field' => $dup];
        }
        foreach ($plan['fields'] as [$name, $type, $nullable, $hasDefault, , $path]) {
            $v = self::resolve($members, $path);
            if ($v === false) {
                return ['ok' => false, 'step' => 5, 'reason' => 'path_not_object', 'field' => $name];
            }
            if ($v === null) {
                // Only a missing key takes the default; a present null never does
                if ($hasDefault) {
                    continue;
                }
                return ['ok' => false, 'step' => 5, 'reason' => 'missing_field', 'field' => $name];
            }
            if ($v['k'] === 'null') {
                if (!$nullable) {
                    return ['ok' => false, 'step' => 5, 'reason' => 'null_field', 'field' => $name];
                }
                continue;
            }
            if (!self::fieldOk($type, $v)) {
                return ['ok' => false, 'step' => 5, 'reason' => 'type_mismatch', 'field' => $name];
            }
        }
        // step 6, every entry whatever its operators; where never sees defaults
        $values = [];
        foreach ($plan['where'] as [$name, , $path]) {
            $v = self::resolve($members, $path);
            if ($v === false) {
                return ['ok' => false, 'step' => 6, 'reason' => 'path_not_object', 'field' => $name];
            }
            if ($v !== null && ($v['k'] === 'array' || $v['k'] === 'object')) {
                return ['ok' => false, 'step' => 6, 'reason' => 'where_not_scalar', 'field' => $name];
            }
            $values[] = $v;
        }
        // step 7: AND over entries and over each entry's operators; a missing leaf or intermediate never matches
        foreach ($plan['where'] as $i => [, $ops]) {
            if ($values[$i] === null) {
                return ['ok' => true, 'match' => false];
            }
            foreach ($ops as [$op, $operand]) {
                if (!self::opHolds($op, $operand, $values[$i])) {
                    return ['ok' => true, 'match' => false];
                }
            }
        }
        return ['ok' => true, 'match' => true];
    }

    /**
     * Step 4, depth first in document order, which is byte order: in the object
     * $members reached through the keys $prefix, each key that is a segment of some plan path
     * at this position is a node; a node met twice is a duplicate_key, and getField() names the
     * first fields entry, then the first where entry, whose path passes through it. Only
     * objects under a node that some path continues below are entered. Returns that name or null.
     */
    private static function walk(array $members, array $prefix, array $plan, array &$seen): ?string
    {
        $entries = [];
        foreach ($plan['fields'] as $f) {
            $entries[] = [$f[0], $f[5]];
        }
        foreach ($plan['where'] as $w) {
            $entries[] = [$w[0], $w[2]];
        }
        foreach ($members as [$key, $v]) {
            $node = [...$prefix, $key];
            $owner = null;
            $inner = false;
            foreach ($entries as [$name, $path]) {
                if (array_slice($path, 0, count($node)) === $node) {
                    $owner ??= $name;
                    $inner = $inner || count($path) > count($node);
                }
            }
            if ($owner === null) {
                continue;
            }
            $id = serialize($node);
            if (isset($seen[$id])) {
                return $owner;
            }
            $seen[$id] = true;
            if ($inner && $v['k'] === 'object') {
                $dup = self::walk($v['members'], $node, $plan, $seen);
                if ($dup !== null) {
                    return $dup;
                }
            }
        }
        return null;
    }

    /**
     * The value at $path below the root members, found by descending the first
     * occurrence of each key (the walk has refused repeats), null when a key is missing, false
     * when an intermediate is present but not an object (null included).
     */
    public static function resolve(array $members, array $path)
    {
        foreach ($path as $i => $seg) {
            $found = null;
            foreach ($members as [$key, $v]) {
                if ($key === $seg) {
                    $found = $v;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            if ($i === count($path) - 1) {
                return $found;
            }
            if ($found['k'] !== 'object') {
                return false;
            }
            $members = $found['members'];
        }
        return null;
    }

    /**
     * Run a whole input string. Success: ['ok'=>true,'read','matched','bytesRead'].
     * Failure: ['ok'=>false,'line','record','offset','matched','step','reason','field']. Either way the
     * output side holds every matched record before the failure, which 1.0 writes before it throws.
     */
    public static function run(string $data, array $planSpec): array
    {
        $plan = self::plan($planSpec);
        $len = strlen($data);
        $pos = 0;
        $line = 0;
        $rec = 0;
        $matched = 0;
        $rows = [];
        while ($pos < $len) {
            $nl = strpos($data, "\n", $pos);
            $end = $nl === false ? $len : $nl;
            $next = $nl === false ? $len : $nl + 1;
            $text = substr($data, $pos, $end - $pos);
            if ($text !== '' && $text[-1] === "\r") {
                $text = substr($text, 0, -1);
            }
            $line++;
            if ($text === '') {
                if ($plan['skip']) {
                    $pos = $next;
                    continue;
                }
                return ['ok' => false, 'line' => $line, 'record' => $rec + 1, 'offset' => $pos, 'matched' => $matched, 'step' => 1, 'reason' => 'blank_line', 'field' => null] + self::output($rows);
            }
            $rec++;
            if (strlen($text) > $plan['cap']) {
                return ['ok' => false, 'line' => $line, 'record' => $rec, 'offset' => $pos, 'matched' => $matched, 'step' => 1, 'reason' => 'record_too_large', 'field' => null] + self::output($rows);
            }
            $r = self::record($text, $plan);
            if (!$r['ok']) {
                return ['ok' => false, 'line' => $line, 'record' => $rec, 'offset' => $pos, 'matched' => $matched] + $r + self::output($rows);
            }
            if ($r['match']) {
                $matched++;
                $rows[] = self::project($text, $plan);
            }
            $pos = $next;
        }
        return ['ok' => true, 'read' => $rec, 'matched' => $matched, 'bytesRead' => $len] + self::output($rows);
    }

    /**
     * Output side of a run: 'rows' (projected rows of the matched records so far), 'lines'
     * (their exact text, null where a float's text is open), 'out' (all lines joined, null
     * when any is open) and 'bytes' (its length, null likewise).
     */
    private static function output(array $rows): array
    {
        $lines = array_map([self::class, 'line'], $rows);
        $out = in_array(null, $lines, true) ? null : implode('', $lines);
        return ['rows' => $rows, 'lines' => $lines, 'out' => $out, 'bytes' => $out === null ? null : strlen($out)];
    }

    /**
     * Render a run() result exactly as jf_run_str() renders the extension's outcome. bytesWritten
     * is '?' when a float's text is not determined by the spec (see floatText()); compare with
     * jf_stats_open() then.
     */
    public static function render(array $r): string
    {
        if ($r['ok']) {
            return sprintf('read=%d matched=%d emitted=%d bytesRead=%d bytesWritten=%s', $r['read'], $r['matched'], $r['matched'], $r['bytesRead'], $r['bytes'] ?? '?');
        }
        return sprintf('JsonFuse\RecordException %s field=%s line=%d record=%d offset=%d [read=%d matched=%d emitted=%d bytesRead=%d bytesWritten=%s]',
            $r['reason'], $r['field'] === null ? 'null' : json_encode($r['field']), $r['line'], $r['record'], $r['offset'],
            $r['record'] - 1, $r['matched'], count($r['rows']), $r['offset'], $r['bytes'] ?? '?');
    }
}

/**
 * Run ONE record text (plus terminator) through the extension and condense the outcome:
 * "match" / "nomatch" (success with matched 1 / 0), "error" (RecordException on 1/1/0),
 * or a diagnosis. Also checks the counters and that no record bytes leak.
 */
function jf_v(string $record, array $planOver, string $term = "\n"): string
{
    $data = $record . $term;
    $in = jf_mem($data);
    $out = jf_out();
    try {
        $s = JsonFuse\Runner::process($in, $out, jf_plan($planOver));
        rewind($out);
        $got = stream_get_contents($out);
        if ($s->recordsRead !== 1 || $s->bytesRead !== strlen($data)) {
            return 'BAD stats ' . jf_stats($s);
        }
        if ($s->recordsEmitted !== $s->recordsMatched || $s->bytesWritten !== strlen($got)) {
            return 'BAD output counters ' . jf_stats($s) . ' output=' . strlen($got);
        }
        if ($s->recordsMatched === 0) {
            return $got === '' ? 'nomatch' : 'BAD output written for a non-match';
        }
        if ($s->recordsMatched !== 1) {
            return 'BAD matched ' . $s->recordsMatched;
        }
        if (substr_count($got, "\n") !== 1 || substr($got, -1) !== "\n" || !is_array(json_decode($got, true))) {
            return 'BAD output is not exactly one JSON object line';
        }
        $ref = JfRef::run($data, $planOver + ['fields' => ['id' => 'int64']]);
        if (!$ref['ok'] || $ref['matched'] !== 1) {
            return 'BAD reference disagrees on the verdict';
        }
        if ($ref['out'] !== null && $ref['out'] !== $got) {
            return 'BAD output bytes ' . bin2hex($got) . ' want ' . bin2hex($ref['out']);
        }
        return 'match';
    } catch (Throwable $e) {
        $m = $e->getMessage();
        if (!($e instanceof JsonFuse\RecordException)) {
            return 'BAD ' . get_class($e) . ': ' . $m;
        }
        if (jf_ctx($m) !== 'line=1 record=1 offset=0') {
            return 'BAD ctx ' . jf_ctx($m);
        }
        if (str_contains($m, 'SECRET') || (strlen($record) > 4 && str_contains($m, substr($record, 0, 40)))) {
            return 'BAD message leaks record bytes';
        }
        if (!preg_match('//u', $m)) {
            return 'BAD message not UTF-8';
        }
        rewind($out);
        return stream_get_contents($out) === '' ? 'error' : 'BAD output written for a rejected record';
    } finally {
        fclose($in);
        fclose($out);
    }
}

/** Table runner: rows are [name, record, expected] against one plan; prints "name ok" or a diagnosis. */
function jf_rows(string $title, array $planOver, array $rows): void
{
    echo "-- $title\n";
    foreach ($rows as [$name, $record, $want]) {
        $got = jf_v($record, $planOver);
        echo str_pad($name, 66), $got === $want ? 'ok' : "FAIL got $got want $want", "\n";
    }
}

/**
 * Escape every non-ASCII code point of a UTF-8 string as a JSON \uXXXX escape (surrogate pairs
 * above U+FFFF, lower-case hex); ASCII is left literal.
 */
function jf_u_escape(string $s): string
{
    $out = '';
    foreach (preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
        $n = strlen($ch);
        if ($n === 1) {
            $out .= $ch;
            continue;
        }
        $b = array_values(unpack('C*', $ch));
        $cp = match ($n) {
            2 => (($b[0] & 0x1F) << 6) | ($b[1] & 0x3F),
            3 => (($b[0] & 0x0F) << 12) | (($b[1] & 0x3F) << 6) | ($b[2] & 0x3F),
            default => (($b[0] & 0x07) << 18) | (($b[1] & 0x3F) << 12) | (($b[2] & 0x3F) << 6) | ($b[3] & 0x3F),
        };
        if ($cp >= 0x10000) {
            $c = $cp - 0x10000;
            $out .= sprintf('\u%04x\u%04x', 0xD800 + ($c >> 10), 0xDC00 + ($c & 0x3FF));
        } else {
            $out .= sprintf('\u%04x', $cp);
        }
    }
    return $out;
}

/** UTF-8 encoding of one code point (no mbstring needed). */
function jf_chr(int $cp): string
{
    if ($cp < 0x80) {
        return chr($cp);
    }
    if ($cp < 0x800) {
        return chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F);
    }
    if ($cp < 0x10000) {
        return chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
    }
    return chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F);
}
