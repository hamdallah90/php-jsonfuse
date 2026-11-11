#!/usr/bin/env bash
# The multi-thread harness (tests/zts/jf_threads.c) under ThreadSanitizer, its negative control, and the
# PHPT suite on the same build. PHP is built from a pinned tarball with --enable-zts --enable-embed
# --enable-debug, and PHP, the extension and the harness are compiled with gcc -fsanitize=thread.
# The build has no opcache JIT: its request shutdown writes a process-wide counter
# (zend_jit_profile_counter) from every thread, a race in PHP itself that ThreadSanitizer reports.
# ThreadSanitizer needs a kernel whose address-space randomisation it accepts (vm.mmap_rnd_bits of 28
# or less on x86-64); the workflow lowers it on the runner before this script starts.
# env (optional): PHP_VERSION, PHP_URL, PHP_SHA256 (default 8.5.12 from php.net); JF_LOG_DIR (default
# zts-logs/ in the checkout) receives the harness output, its stderr and the PHPT log.
# Called from .github/workflows/ci.yml in debian:trixie-slim.
set -euo pipefail

export PHP_VERSION=${PHP_VERSION:-8.5.12}
PHP_SHA256=${PHP_SHA256:-08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209}
PHP_URL=${PHP_URL:-https://www.php.net/distributions/php-${PHP_VERSION}.tar.xz}
TSAN="-O1 -g -fno-omit-frame-pointer -fsanitize=thread"

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends gcc g++ libc-dev make autoconf pkg-config re2c bison file dpkg-dev \
    ca-certificates curl xz-utils zlib1g-dev locales >/dev/null
sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen
locale-gen >/dev/null

src=$(pwd)
logs=${JF_LOG_DIR:-$src/zts-logs}
mkdir -p "$logs"

cd /usr/src
curl -fsSL -o php.tar.xz "$PHP_URL"
echo "${PHP_SHA256}  php.tar.xz" | sha256sum -c -
mkdir php && tar xf php.tar.xz -C php --strip-components=1
cd php
CC=gcc CXX=g++ CFLAGS="$TSAN" LDFLAGS="-fsanitize=thread" ./configure \
    --prefix=/usr/local --with-config-file-path=/usr/local/etc/php --with-config-file-scan-dir=/usr/local/etc/php/conf.d \
    --disable-all --enable-cli --enable-embed --enable-zts --enable-debug --with-zlib --disable-cgi --disable-phpdbg \
    --disable-opcache-jit >/dev/null
make -j"$(nproc)" >/dev/null
make install >/dev/null
cp run-tests.php /usr/local/lib/php/build/run-tests.php
mkdir -p /usr/local/etc/php/conf.d
php -r 'exit(PHP_VERSION === getenv("PHP_VERSION") && PHP_ZTS && PHP_DEBUG ? 0 : 1);' \
    || { echo "::error::expected a ZTS debug PHP ${PHP_VERSION}" >&2; exit 1; }

# build <tree>: the extension and the harness, both with ThreadSanitizer, inside <tree>.
build() {
    (
        cd "$1"
        phpize >/dev/null
        CFLAGS="$TSAN -Wall -Wextra" LDFLAGS="-fsanitize=thread" ./configure --enable-jsonfuse >/dev/null
        make -j"$(nproc)" >make.log 2>&1 || { tail -n 40 make.log; exit 1; }
        if grep -E 'warning:' make.log | grep -E 'src/'; then
            echo "::error::compiler warnings in src/" >&2
            exit 1
        fi
        # shellcheck disable=SC2046,SC2086
        gcc $TSAN -Wall -Wextra -Werror $(php-config --includes) tests/zts/jf_threads.c \
            -L/usr/local/lib -Wl,-rpath,/usr/local/lib -lphp -o jf_threads
    )
}

# harness <tree> <label>: runs it, keeps stdout and stderr in $logs; returns its exit status.
harness() {
    local rc=0
    TSAN_OPTIONS="halt_on_error=0 exitcode=66 second_deadlock_stack=1" \
        "$1/jf_threads" "$1/modules/jsonfuse.so" "$1/tests/zts/jf_threads.php" \
        >"$logs/$2.out" 2>"$logs/$2.err" || rc=$?
    cat "$logs/$2.out"
    return "$rc"
}

build_dir=$(mktemp -d)
cp -a "$src/." "$build_dir/"
build "$build_dir"
syms=$(nm -D "$build_dir/modules/jsonfuse.so") || { echo "::error::nm failed, so the exported-symbol check cannot run" >&2; exit 1; }
if grep yyjson_ <<<"$syms" >/dev/null; then
    echo "::error::yyjson_ symbols are exported" >&2
    exit 1
fi

rc=0
harness "$build_dir" threads || rc=$?
races=$(grep -c 'WARNING: ThreadSanitizer' "$logs/threads.err" || true)
leaks=$(grep -cE 'memory leaks detected|Freeing 0x' "$logs/threads.err" || true)
echo "HARNESS rc=$rc tsan_reports=$races leak_lines=$leaks"
if [ "$rc" != 0 ] || [ "$races" != 0 ] || [ "$leaks" != 0 ]; then
    head -n 200 "$logs/threads.err"
    echo "::error::multi-thread harness failed" >&2
    exit 1
fi

# Negative control: a static counter planted in Runner::process of a scratch copy must be reported.
control=$(mktemp -d)
cp -a "$src/." "$control/"
grep -q '^ZEND_METHOD(JsonFuse_Runner, process)$' "$control/src/jf_runner.c"
sed -i '/^ZEND_METHOD(JsonFuse_Runner, process)$/,/^{$/ s/^{$/{\n\tjf_planted_counter++;/' "$control/src/jf_runner.c"
sed -i 's/^ZEND_METHOD(JsonFuse_Runner, process)$/static long jf_planted_counter;\n&/' "$control/src/jf_runner.c"
grep -q 'jf_planted_counter++;' "$control/src/jf_runner.c"
build "$control"
crc=0
harness "$control" control >/dev/null || crc=$?
# grep -c reads to the end: grep -q would close the pipe early, which pipefail reports as a failure.
planted=$(grep -A 12 'WARNING: ThreadSanitizer: data race' "$logs/control.err" | grep -c 'jf_runner.c' || true)
if [ "$crc" != 66 ] || [ "$planted" = 0 ]; then
    head -n 60 "$logs/control.err"
    echo "::error::negative control: the planted race was not reported (exit $crc)" >&2
    exit 1
fi
echo "CONTROL rc=$crc tsan_reports=$(grep -c 'WARNING: ThreadSanitizer' "$logs/control.err") planted race reported in jf_runner.c"

# The PHPT suite on the same ThreadSanitizer build. Instrumented, 012, 127 and 136 take 60 to 90 s
# each, past run-tests' default limit of 60 s per test.
cd "$build_dir"
TSAN_OPTIONS="halt_on_error=1 exitcode=66" NO_COLOR=1 NO_INTERACTION=1 make test "TESTS=--set-timeout 600 tests" >"$logs/phpt.log" 2>&1 || true
sed -n '/^FAILED TEST SUMMARY/,/^=====/p' "$logs/phpt.log"
for t in tests/*.diff; do
    [ -e "$t" ] || continue
    echo "--- $t"
    head -n 40 "$t"
done
failed=$(awk '/^Tests failed/ {print $4}' "$logs/phpt.log")
borked=$(awk '/^Tests borked/ {print $4}' "$logs/phpt.log")
passed=$(awk '/^Tests passed/ {print $4}' "$logs/phpt.log")
skipped=$(awk '/^Tests skipped/ {print $4}' "$logs/phpt.log")
echo "RESULT passed=${passed:-0} failed=${failed:-0} borked=${borked:-0} skipped=${skipped:-0}"
# Every test file must have run, and none may be marked as an expected failure.
total=$(awk '/^Number of tests/ {print $5}' "$logs/phpt.log")
xfail=$(awk '/^Expected fail/ {print $4}' "$logs/phpt.log")
want=$(ls tests/*.phpt | wc -l | tr -d ' ')
if [ "${total:-0}" != "$want" ] || [ "${xfail:-0}" != 0 ]; then
    echo "::error::expected $want tests and no expected failure, got ${total:-0} and ${xfail:-0}" >&2
    exit 1
fi
# 153 needs a -DJF_SCAN_STATS build (sanitizer.sh, where it must pass), so it skips here, and nothing else
# may. Each result line follows the progress line after a carriage return, uncoloured with NO_COLOR.
skips=$(tr '\r' '\n' <"$logs/phpt.log" | grep -E '^SKIP ' | grep -oE '\[tests/[^]]+\.phpt\]' | sort | tr '\n' ' ' || true)
if [ "${skipped:-0}" != 1 ] || [ "$skips" != '[tests/153_scan_accept_count.phpt] ' ]; then
    echo "::error::expected exactly the skip of 153, got ${skipped:-0}: ${skips:-none}" >&2
    exit 1
fi
[ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -gt 0 ]
