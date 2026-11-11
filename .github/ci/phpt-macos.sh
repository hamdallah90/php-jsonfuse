#!/usr/bin/env bash
# Build and PHPT on macOS arm64 with the NTS PHP that setup-php installed. Called from .github/workflows/ci.yml.
set -euo pipefail

php -r 'echo PHP_VERSION, PHP_ZTS ? " ZTS " : " NTS ", php_uname("m"), "\n";'
php -r 'exit(PHP_ZTS ? 1 : 0);' || { echo "::error::expected an NTS build" >&2; exit 1; }
php -r 'exit(ini_get("opcache.enable_cli") ? 1 : 0);' || { echo "::error::opcache is enabled for the CLI" >&2; exit 1; }

src=$(pwd)
build=$(mktemp -d)
cp -a "$src/." "$build/"
cd "$build"

phpize >/dev/null
CFLAGS="-Wall -Wextra" ./configure --enable-jsonfuse >/dev/null
make -j"$(sysctl -n hw.ncpu)" 2>&1 | tee /tmp/jf-make.log >/dev/null

if grep -E 'warning:' /tmp/jf-make.log | grep -E 'src/'; then
    echo "::error::compiler warnings in src/" >&2
    exit 1
fi
syms=$(nm -gU modules/jsonfuse.so) || { echo "::error::nm failed, so the exported-symbol check cannot run" >&2; exit 1; }
if grep yyjson_ <<<"$syms" >/dev/null; then
    echo "::error::yyjson_ symbols are exported" >&2
    exit 1
fi

NO_COLOR=1 NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests 2>&1 | tee /tmp/jf-test.log | tail -8
failed=$(awk '/^Tests failed/ {print $4}' /tmp/jf-test.log)
borked=$(awk '/^Tests borked/ {print $4}' /tmp/jf-test.log)
passed=$(awk '/^Tests passed/ {print $4}' /tmp/jf-test.log)
skipped=$(awk '/^Tests skipped/ {print $4}' /tmp/jf-test.log)
echo "RESULT passed=${passed:-0} failed=${failed:-0} borked=${borked:-0} skipped=${skipped:-0}"
# Every test file must have run, and none may be marked as an expected failure.
total=$(awk '/^Number of tests/ {print $5}' /tmp/jf-test.log)
xfail=$(awk '/^Expected fail/ {print $4}' /tmp/jf-test.log)
want=$(ls tests/*.phpt | wc -l | tr -d ' ')
if [ "${total:-0}" != "$want" ] || [ "${xfail:-0}" != 0 ]; then
    echo "::error::expected $want tests and no expected failure, got ${total:-0} and ${xfail:-0}" >&2
    exit 1
fi
# macOS has no /dev/full, so 084 and 105 skip; 153 needs a -DJF_SCAN_STATS build (sanitizer.sh); nothing
# else may skip. Each result line follows the progress line after a carriage return, uncoloured with NO_COLOR.
skips=$(tr '\r' '\n' </tmp/jf-test.log | grep -E '^SKIP ' | grep -oE '\[tests/[^]]+\.phpt\]' | sort | tr '\n' ' ' || true)
if [ "${skipped:-0}" != 3 ] || [ "$skips" != '[tests/084_flush_failure_plain.phpt] [tests/105_reason_flush_failed.phpt] [tests/153_scan_accept_count.phpt] ' ]; then
    echo "::error::expected exactly the skips of 084, 105 and 153, got ${skipped:-0}: ${skips:-none}" >&2
    exit 1
fi
[ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -gt 0 ]
