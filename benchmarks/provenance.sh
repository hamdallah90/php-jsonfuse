#!/usr/bin/env bash
# Benchmark provenance, run inside the benchmark container before any timed run.
# Checks that the source is a git checkout with an empty `git status --porcelain`, builds jsonfuse.so in this job
# from `git archive HEAD`, installs that build for the run, and prints PROVENANCE lines: the commit, the status,
# the .so SHA-256 and `php --ri jsonfuse`. Ends with `PROVENANCE publishable=yes`, or prints
# `PROVENANCE publishable=no reason=...` and exits 6, so numbers from any other build cannot pass as published.
#
#   provenance.sh [source-dir]      default /jsonfuse, the tree the image copied
set -euo pipefail

src=${1:-/jsonfuse}

if ! commit=$(git -C "$src" rev-parse --verify HEAD 2>/dev/null); then
    echo "PROVENANCE publishable=no reason=not_a_git_checkout"
    exit 6
fi
echo "PROVENANCE commit=$commit"
status=$(git -C "$src" status --porcelain)
if [ -n "$status" ]; then
    printf '%s\n' "$status" | sed 's/^/PROVENANCE dirty: /'
    echo "PROVENANCE publishable=no reason=dirty_tree"
    exit 6
fi
echo "PROVENANCE status_porcelain=empty"

# The committed tree only, with the flags of the image build (benchmarks/Dockerfile). JF_EXTRA_CFLAGS (default
# empty) is appended and printed; the scan comparison sets it to -DJF_DISABLE_SCAN for the base build.
build=$(mktemp -d)
git -C "$src" archive --format=tar HEAD | tar -x -C "$build"
echo "PROVENANCE extra_cflags=${JF_EXTRA_CFLAGS:-}"
(
    cd "$build"
    phpize >/dev/null
    CFLAGS="${PHP_CFLAGS:-} -Wall -Wextra ${JF_EXTRA_CFLAGS:-}" ./configure --enable-jsonfuse >/dev/null
    make -j"$(nproc)" >/dev/null 2>&1
)
so="$(php-config --extension-dir)/jsonfuse.so"
cp "$build/modules/jsonfuse.so" "$so"
rm -rf "$build"
echo "PROVENANCE so=$so sha256=$(sha256sum "$so" | cut -d' ' -f1)"
php -d extension=jsonfuse --ri jsonfuse | sed '/^$/d; s/^/PROVENANCE ri: /'
echo "PROVENANCE publishable=yes"
