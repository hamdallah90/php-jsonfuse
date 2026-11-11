#!/usr/bin/env bash
# The build with the validating scan compiled out (-DJF_DISABLE_SCAN), in an official PHP
# image: phpt.sh on that build (150 then expects the scan off), and the scan differential driver's output
# from that build against a normal build of the same tree, byte for byte. Called from .github/workflows/ci.yml.
set -euo pipefail

JF_DISABLE_SCAN=1 PHP_CFLAGS="${PHP_CFLAGS:-} -DJF_DISABLE_SCAN" bash .github/ci/phpt.sh

src=$(pwd)
declare -A so
for b in on off; do
    build=$(mktemp -d)
    cp -a "$src/." "$build/"
    flags="${PHP_CFLAGS:-}"
    [ "$b" = off ] && flags="$flags -DJF_DISABLE_SCAN"
    (cd "$build" && phpize >/dev/null && CFLAGS="$flags" ./configure --enable-jsonfuse >/dev/null && make -j"$(nproc)" >/dev/null 2>&1)
    so[$b]=$build/modules/jsonfuse.so
done

# The driver's output from both builds must be identical. Four seeds of 25,000 records each: truncated records,
# bad escapes, bad UTF-8, duplicate keys, deep nesting, NUL bytes, CRLF, empty lines. Then the committed fuzz
# corpus (the seeds and the regression inputs of fuzz/corpus), each file and each of its lines as an input.
compare() { # label, then the driver arguments
    local label=$1
    shift
    for b in on off; do
        php -n -d extension="${so[$b]}" tests/jf_scan_diff.inc "$@" >"/tmp/jf-diff-$b.out" 2>"/tmp/jf-diff-$b.err"
    done
    grep -qx 'validating scan => enabled' /tmp/jf-diff-on.err \
        && grep -qx 'validating scan => disabled' /tmp/jf-diff-off.err \
        || { echo "::error::the two builds do not report the scan as enabled and disabled" >&2; exit 1; }
    if ! cmp -s /tmp/jf-diff-on.out /tmp/jf-diff-off.out; then
        echo "::error::$label: the -DJF_DISABLE_SCAN build and the normal build give different output" >&2
        diff /tmp/jf-diff-on.out /tmp/jf-diff-off.out | head -n 6 >&2 || true
        exit 1
    fi
    echo "$label: $(grep -E '^(runs|files)=' /tmp/jf-diff-on.err), $(wc -l </tmp/jf-diff-on.out) runs, outputs identical"
}

for seed in 1 2 3 4; do
    compare "seed $seed" gen "$seed" 25000
done
compare "fuzz corpus" corpus fuzz/corpus/seed fuzz/corpus/regression
