#!/usr/bin/env bash
# usage: run.sh <seconds per worker> <workers>. Runs in a container or anywhere clang with libFuzzer exists.
# Total fuzzing is seconds * workers CPU-seconds. Prints a per-file coverage table from a separate coverage build.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
SECONDS_PER_WORKER=${1:-60}
WORKERS=${2:-4}
OUT=${JF_FUZZ_OUT:-$ROOT/fuzz/out}
export JF_FUZZ_OUT=$OUT

"$ROOT/fuzz/build.sh" coverage

mkdir -p "$OUT/corpus" "$OUT/artifacts"
cd "$OUT"
export ASAN_OPTIONS=detect_leaks=1:halt_on_error=1:abort_on_error=0:symbolize=1:print_summary=1
export UBSAN_OPTIONS=print_stacktrace=1:halt_on_error=1:print_summary=1

./fuzz_jsonfuse -workers="$WORKERS" -jobs="$WORKERS" -max_total_time="$SECONDS_PER_WORKER" -print_final_stats=1 \
	-max_len=262144 -timeout=30 -rss_limit_mb=3000 -dict="$ROOT/fuzz/jsonfuse.dict" \
	-artifact_prefix="$OUT/artifacts/" corpus "$ROOT/fuzz/corpus/seed" "$ROOT/fuzz/corpus/regression" || true

# The fuzzer's own exit status is ignored above, so any artifact file is a finding (one crash file is enough).
if [ -n "$(find "$OUT/artifacts" -type f \( -name 'crash-*' -o -name 'oom-*' -o -name 'timeout-*' -o -name 'leak-*' \) -print -quit)" ]; then
	echo "FUZZ FINDINGS:"
	ls -la "$OUT/artifacts"
	exit 1
fi

LLVM_PROFILE_FILE="$OUT/cov.profraw" ./fuzz_jsonfuse_cov -runs=0 corpus "$ROOT/fuzz/corpus/seed" "$ROOT/fuzz/corpus/regression" >/dev/null 2>&1
llvm-profdata merge -sparse "$OUT/cov.profraw" -o "$OUT/cov.profdata"
llvm-cov report ./fuzz_jsonfuse_cov -instr-profile="$OUT/cov.profdata" -ignore-filename-regex='(third_party|fuzz)/'
