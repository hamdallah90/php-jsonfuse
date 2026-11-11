#!/usr/bin/env bash
# usage: launch.sh PHPVER BATCHNAME  (reads queue spec from QUEUES env: "core:wl1,wl2;core:...")
set -u
cd "${JF_ROOT:-$PWD}"
PHPV=$1; BATCH=$2; QUEUES=$3; IMPLS=${IMPLS:-jsonfuse,php,simdjson,fastjson}
D=$PWD/benchmarks/results/$BATCH
mkdir -p "$D"
cat > "$D/NOISE_RULE.txt" <<'RULE'
Noise rule (declared before any timed run, 2026-11-09):
1. Statistic: median of timed runs per configuration; p95 reported (nearest-rank). All samples kept in raw.log. No sample is discarded.
2. A configuration is "noisy" if max/median > 1.25 or (p95-min)/median > 0.25 over its timed runs.
3. A ratio is "stable" if neither the JsonFuse configuration nor the best-baseline configuration is noisy.
4. Any workload whose ratio vs best PHP baseline is in [1.6, 2.4] (within 20% of 2x), or whose ratio uses a noisy configuration and decides the headline, is re-run isolated (one job, no other benchmark running, CPUs 0-9 only) and the isolated result replaces the parallel result.
5. Parallel run: up to 8 jobs, one per distinct core of CPUs 0-9; other tenant benchmark runs on CPUs 10-29 and cannot be controlled (load recorded in uptime snapshots).
6. Best baseline per workload = the PHP baseline configuration (impl x {nojit,jit}) with the lowest median wall time.
RULE
uptime > "$D/uptime_start.txt"
IFS=';' read -ra Q <<< "$QUEUES"
for q in "${Q[@]}"; do
  core=${q%%:*}; wls=${q#*:}
  ( for w in ${wls//,/ }; do
      case "$w" in
        *-1m-*) args=(--runs 5 --warmups 2 --rss-runs 3) ;;
        *) args=(--runs 10 --warmups 3 --rss-runs 3) ;;
      esac
      JF_COMMIT=$JF_COMMIT benchmarks/run.sh --php "$PHPV" --cpuset "$core" --modes nojit,jit --impls "$IMPLS" "${args[@]}" ${EXTRA:-} --results "$D/$w" "$w" > "$D/$w.stdout" 2>&1
      echo "$w rc=$?" >> "$D/DONE.txt"
    done ) &
done
wait
uptime > "$D/uptime_end.txt"
echo ALLDONE >> "$D/DONE.txt"
