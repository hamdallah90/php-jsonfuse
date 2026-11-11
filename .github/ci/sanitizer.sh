#!/usr/bin/env bash
# ASan+UBSan PHPT, the Zend-allocator debug run, and fuzz corpus replay, on a debug PHP built from source.
# env (optional): PHP_VERSION, PHP_URL, PHP_SHA256 (default 8.3.36 from php.net) and SAN_CC, the compiler
# of PHP and the extension (default clang; clang 19 cannot build an ASan PHP 8.5 or 8.6, so those use gcc).
# The fuzz corpus replay always uses clang (libFuzzer).
# Called from .github/workflows/ci.yml.
# Each lane's log, and the .diff, .out, .exp, .log and .mem files of every test it failed, go to
# $JF_LOG_DIR (default sanitizer-logs/ in the checkout; mount it to keep them past a container),
# and a failing test's name and diff are printed too.
set -euo pipefail

export PHP_VERSION=${PHP_VERSION:-8.3.36}
PHP_SHA256=${PHP_SHA256:-dd54693aa334c5c4d72e9aee96b082c2473750a18a6c1d105789c93a3b1692fd}
PHP_URL=${PHP_URL:-https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz}
SAN_CC=${SAN_CC:-clang}
case "$SAN_CC" in
    clang) SAN_CXX=clang++ ;;
    gcc) SAN_CXX=g++ ;;
    *) echo "::error::SAN_CC must be clang or gcc" >&2; exit 1 ;;
esac

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends clang llvm lld libclang-rt-dev gcc g++ autoconf automake libtool bison re2c make \
    pkg-config file ca-certificates curl xz-utils libxml2-dev libsqlite3-dev libonig-dev zlib1g-dev locales >/dev/null
sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen
locale-gen >/dev/null

src=$(pwd)
logs=${JF_LOG_DIR:-$src/sanitizer-logs}
mkdir -p "$logs"
cd /usr/src
curl -fsSL -o php.tar.xz "$PHP_URL"
echo "${PHP_SHA256}  php.tar.xz" | sha256sum -c -
mkdir php && tar xf php.tar.xz -C php --strip-components=1
cd php
CC=$SAN_CC CXX=$SAN_CXX CFLAGS="-O1 -g -fno-omit-frame-pointer" ./configure \
    --prefix=/usr/local --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
    --disable-all --enable-cli --enable-debug --enable-address-sanitizer --enable-undefined-sanitizer \
    --enable-mbstring --enable-session --with-zlib --without-pcre-jit --disable-cgi --disable-phpdbg >/dev/null
make -j"$(nproc)" >/dev/null
make install >/dev/null
cp run-tests.php /usr/local/lib/php/build/run-tests.php
php -r 'exit(PHP_VERSION === getenv("PHP_VERSION") && PHP_DEBUG ? 0 : 1);' \
    || { echo "::error::expected a debug PHP ${PHP_VERSION}" >&2; exit 1; }
cd "$src"

build=$(mktemp -d)
cp -a "$src/." "$build/"
cd "$build"
# -DJF_SCAN_STATS adds the "validating scan accepted" info row that 151, 152, 153 and the scan
# differential read, so a scan that hands every record to yyjson fails here; 153 skips without it,
# and the minimum pass counts below include it.
export CC=$SAN_CC CFLAGS="-O1 -g -fno-omit-frame-pointer -fsanitize=address,undefined -DJF_SCAN_STATS" LDFLAGS="-fsanitize=address,undefined"
phpize >/dev/null
./configure --enable-jsonfuse >/dev/null
make -j"$(nproc)" 2>&1 | tee /tmp/jf-make.log >/dev/null
if grep -E 'warning:' /tmp/jf-make.log | grep -E 'src/'; then
    echo "::error::compiler warnings in src/" >&2
    exit 1
fi

keep() { # label log: print the failed tests and their diffs, and move their files to $logs/label/
    local t
    mkdir -p "$logs/$1"
    sed -n '/^FAILED TEST SUMMARY/,/^=====/p' "$2"
    for t in tests/*.diff; do
        [ -e "$t" ] || continue
        echo "--- $t"
        cat "$t"
        mv "${t%.diff}".diff "${t%.diff}".out "${t%.diff}".exp "${t%.diff}".log "${t%.diff}".mem "$logs/$1/" 2>/dev/null || true
    done
}

summary() { # label log minimum-passed skip-list (a SKIPIF regression must not pass quietly)
    local failed borked passed skipped total xfail want skips
    failed=$(awk '/^Tests failed/ {print $4}' "$2")
    borked=$(awk '/^Tests borked/ {print $4}' "$2")
    passed=$(awk '/^Tests passed/ {print $4}' "$2")
    skipped=$(awk '/^Tests skipped/ {print $4}' "$2")
    total=$(awk '/^Number of tests/ {print $5}' "$2")
    xfail=$(awk '/^Expected fail/ {print $4}' "$2")
    echo "$1 RESULT passed=${passed:-0} failed=${failed:-0} borked=${borked:-0} skipped=${skipped:-0} total=${total:-0} xfail=${xfail:-0}"
    want=$(ls tests/*.phpt | wc -l)
    if [ "${total:-0}" != "$want" ] || [ "${xfail:-0}" != 0 ]; then
        echo "::error::$1: expected $want tests and no expected failure, got ${total:-0} and ${xfail:-0}" >&2
        exit 1
    fi
    skips=$(tr '\r' '\n' <"$2" | grep -E '^SKIP ' | grep -oE '\[tests/[^]]+\.phpt\]' | sort | tr '\n' ' ' || true)
    if [ "$skips" != "$4" ]; then
        echo "::error::$1: expected the skips ${4:-none}, got ${skips:-none}" >&2
        exit 1
    fi
    [ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -ge "$3" ]
}

passes_153() { # label log: this build has -DJF_SCAN_STATS, so 153 must pass here; it skips in the other lanes.
    # grep -c reads to the end: grep -q would close the pipe early, which pipefail reports as a failure.
    local n
    n=$(tr '\r' '\n' <"$2" | grep -E '^PASS ' | grep -cF '[tests/153_scan_accept_count.phpt]' || true)
    [ "$n" = 1 ] || { echo "::error::$1: tests/153_scan_accept_count.phpt did not pass on the -DJF_SCAN_STATS build" >&2; exit 1; }
}

# PHPT under ASan/UBSan with leak detection. Zend's allocator is off, so memory_limit tests skip themselves,
# and only they may.
USE_ZEND_ALLOC=0 ZEND_DONT_UNLOAD_MODULES=1 \
ASAN_OPTIONS=detect_leaks=1:abort_on_error=0:halt_on_error=1:strict_string_checks=1:detect_stack_use_after_return=1:symbolize=1:print_summary=1 \
UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1 \
NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=--asan tests" >"$logs/asan.log" 2>&1 || true
keep asan "$logs/asan.log"
passes_153 asan "$logs/asan.log"
summary asan "$logs/asan.log" 95 '[tests/070_limits_memory.phpt] [tests/073_reader_preflight.phpt] [tests/075_pool_memory.phpt] [tests/076_preflight_fatal_window.phpt] [tests/081_writer_limits_memory.phpt] [tests/085_writer_preflight.phpt] [tests/088_writer_growth_peak.phpt] [tests/103_reason_memory_limit.phpt] '

# The same build with the Zend allocator on, so ZendMM's leak report applies and memory_limit tests run.
# --asan would turn the allocator off, and without it run-tests.php keeps its plain 60 s limit per test,
# and 136_paths_differential needs about a minute on this ASan/UBSan build, so it timed out whenever the
# machine was busy (148_where_differential needs about 95 s). Give the lane the 180 s that --asan gives the lane above.
ASAN_OPTIONS=detect_leaks=0:abort_on_error=0:halt_on_error=1 \
UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1 \
NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=--set-timeout 180 tests" >"$logs/zend-allocator.log" 2>&1 || true
keep zend-allocator "$logs/zend-allocator.log"
passes_153 zend-allocator "$logs/zend-allocator.log"
summary zend-allocator "$logs/zend-allocator.log" 103 ''

# Replay the committed fuzz corpus under ASan/UBSan.
unset CFLAGS LDFLAGS
JF_FUZZ_OUT=/tmp/jf-fuzz CC=clang fuzz/build.sh
/tmp/jf-fuzz/fuzz_jsonfuse -runs=0 fuzz/corpus/seed fuzz/corpus/regression
# The validating scan with each record's padding ending at a PROT_NONE page: 200,000 generated
# records and every line of the committed corpus.
/tmp/jf-fuzz/guard_pages 200000 1
/tmp/jf-fuzz/guard_pages -f fuzz/corpus/seed/* fuzz/corpus/regression/*
