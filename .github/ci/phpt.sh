#!/usr/bin/env bash
# Build and PHPT for one official PHP image (php:X-cli or php:X-zts). Called from .github/workflows/ci.yml.
set -euo pipefail

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq --no-install-recommends $PHPIZE_DEPS locales >/dev/null
# A comma-decimal locale, so the locale-independence test runs instead of skipping.
sed -i 's/^# *de_DE.UTF-8 UTF-8/de_DE.UTF-8 UTF-8/' /etc/locale.gen
locale-gen >/dev/null
# The stock image enables opcache: state the test configuration instead of inheriting it.
rm -f /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini
php -r 'echo PHP_VERSION, PHP_ZTS ? " ZTS\n" : " NTS\n";'
# JF_EXPECT_ZTS (optional, 0 or 1): fail unless the image's thread safety matches.
if [ -n "${JF_EXPECT_ZTS:-}" ]; then
    php -r 'exit((int)PHP_ZTS === (int)getenv("JF_EXPECT_ZTS") ? 0 : 1);' \
        || { echo "::error::expected PHP_ZTS=${JF_EXPECT_ZTS}" >&2; exit 1; }
fi

src=$(pwd)
build=$(mktemp -d)
cp -a "$src/." "$build/"
cd "$build"

phpize >/dev/null
CFLAGS="${PHP_CFLAGS:-} -Wall -Wextra" ./configure --enable-jsonfuse >/dev/null
make -j"$(nproc)" 2>&1 | tee /tmp/jf-make.log >/dev/null

if grep -E 'warning:' /tmp/jf-make.log | grep -E 'src/'; then
    echo "::error::compiler warnings in src/" >&2
    exit 1
fi
syms=$(nm -D modules/jsonfuse.so) || { echo "::error::nm failed, so the exported-symbol check cannot run" >&2; exit 1; }
if grep yyjson_ <<<"$syms" >/dev/null; then
    echo "::error::yyjson_ symbols are exported" >&2
    exit 1
fi

NO_COLOR=1 NO_INTERACTION=1 REPORT_EXIT_STATUS=1 make test TESTS=tests 2>&1 | tee /tmp/jf-test.log | tail -8
# make test's exit code changes between PHP versions, so read the summary it printed.
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
# 153 needs a -DJF_SCAN_STATS build (sanitizer.sh, where it must pass), so it skips here, and nothing else
# may. Each result line follows the progress line after a carriage return, uncoloured with NO_COLOR.
skips=$(tr '\r' '\n' </tmp/jf-test.log | grep -E '^SKIP ' | grep -oE '\[tests/[^]]+\.phpt\]' | sort | tr '\n' ' ' || true)
if [ "${skipped:-0}" != 1 ] || [ "$skips" != '[tests/153_scan_accept_count.phpt] ' ]; then
    echo "::error::expected exactly the skip of 153, got ${skipped:-0}: ${skips:-none}" >&2
    exit 1
fi
[ "${failed:-0}" = 0 ] && [ "${borked:-0}" = 0 ] && [ "${passed:-0}" -gt 0 ]
