#!/usr/bin/env bash
# Builds the libFuzzer target and, with "coverage", a separate coverage binary.
# Needs clang with the compiler-rt fuzzer runtime; no PHP. Run from anywhere.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
OUT=${JF_FUZZ_OUT:-$ROOT/fuzz/out}
CC=${CC:-clang}
SRC=(fuzz/fuzz_jsonfuse.c src/jf_errors.c src/jf_plan.c src/jf_stream.c src/jf_parse.c src/jf_writer.c src/jf_runner.c third_party/yyjson/yyjson.c)
# The same yyjson configuration as config.m4, so the fuzzed parser is the shipped parser.
COMMON=(-std=gnu11 -g -O1 -fno-omit-frame-pointer -Ifuzz/shim -Isrc -Ithird_party/yyjson -Istubs -Dyyjson_api= -DYYJSON_READER_DEPTH_LIMIT=511)

mkdir -p "$OUT"
cd "$ROOT"
"$CC" "${COMMON[@]}" -fsanitize=fuzzer,address,undefined -fno-sanitize-recover=undefined "${SRC[@]}" -o "$OUT/fuzz_jsonfuse"
if [ "${1:-}" = coverage ]; then
	"$CC" "${COMMON[@]}" -fsanitize=fuzzer -fprofile-instr-generate -fcoverage-mapping "${SRC[@]}" -o "$OUT/fuzz_jsonfuse_cov"
fi
# The guard-page harness for the validating scan, built as a release build would be (no sanitizer
# instrumentation around the word loads), so a read past the padding faults on the guard page.
"$CC" "${COMMON[@]}" -O2 fuzz/guard_pages.c src/jf_parse.c third_party/yyjson/yyjson.c -o "$OUT/guard_pages"
echo "built: $OUT/fuzz_jsonfuse $OUT/guard_pages"
