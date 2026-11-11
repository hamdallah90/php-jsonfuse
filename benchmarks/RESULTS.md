# Benchmark report 2026-11-09

Separate benchmark run of a pre-release JsonFuse build on Linux x86-64, 30 vCPU (clean tree). No harness code was changed.

## Environment
- OS/kernel: Debian (php:8.x-cli images) in Docker on Ubuntu host, kernel 6.8.0-1064-nvidia, KVM guest.
- CPU / arch / RAM: Intel Xeon Platinum 8358 @ 2.60 GHz, x86_64, 30 vCPU (1 thread per core as seen by the guest), 222 GiB. Governor not exposed in the VM (`unknown`). Jobs pinned with `docker --cpuset-cpus` to single cores in 0-9. Another tenant's benchmark ran on CPUs 10-29 throughout (host load average 5 to 18 during these runs; 0.7 before they started).
- PHP: 8.3.36 primary (NTS, non-debug); 8.4.27 and 8.5.12 confirmation. Extension compiled by the image with `-O2` (PHP_CFLAGS), gcc 14.2.0.
- Extension: a pre-release build. yyjson 0.13.0.
- Baseline versions: PECL simdjson 4.0.0, iliaal/fastjson 0.10.2, JSON Machine 1.2.6, `json_decode` (PHP core).
- Data generator: `benchmarks/generate_ndjson.php`, seed 20261109; fixtures verified against `benchmarks/fixtures.sha256` before every job.
- OPcache/JIT: asserted from inside PHP on every run (`opcache=`, `jit_on=` on each RAW line; the harness exits 3 on mismatch). Modes measured: `nojit` (opcache on, JIT off) and `jit` (tracing, 64M). Counts: 204 baseline runs per impl/mode in the parallel set all matched (nojit: opcache=1 jit_on=0; jit: opcache=1 jit_on=1). Mode `off` (opcache disabled) was not measured. JsonFuse is native so only `jit` was run (the harness skips `nojit` for it).

## Noise rule (declared before timing; also in `benchmarks/results/main83/NOISE_RULE.txt`)
1. Median of timed runs per configuration, p95 nearest-rank, all samples kept, none discarded.
2. A configuration is noisy if max/median > 1.25 or (p95-min)/median > 0.25.
3. Any workload whose ratio vs the best PHP baseline lies in [1.6, 2.4] and decides the headline is re-run isolated (one job, no other benchmark job running, CPUs 0-9 only) and the isolated result replaces the parallel one.
4. Best baseline = the PHP baseline configuration (impl x {nojit,jit}) with the lowest median.
Timing is the in-process timed section (`ns=` on RAW lines, one pass over the whole file after warm-ups); wall time and user/sys are in the raw logs too.

## Method actually run
- Equivalence check (`run.sh --equiv`): PASS before any timing. Every timed job also compared output SHA-256 across all implementations and modes (`EQUIV ... byte-identical` lines, none failed).
- Parallel set (`results/main83`, `results/small83`): one job per workload, each pinned to its own core (cores 0-7 for main, 3-6 for 10k, at most 7 concurrent), every implementation of a workload inside the same job. Workloads: narrow and wide x {100k, 1m} x {90%, 10%, 1%} plus 10k for all of them. 1m: 2 warm-ups, 5 timed runs, 3 RSS runs. Below 1m: 3 warm-ups, 10 timed runs, 3 RSS runs. RSS from separate fresh processes with no warm-up (`/usr/bin/time` max RSS).
- Isolated set (`results/iso83`): narrow-100k-10, narrow-1m-10, narrow-100k-1, narrow-1m-1 (the four that fell in the window and decide the gate), run one after another on core 0 with no other benchmark job running. Same run counts. (Host still shared with the other tenant.)
- Confirmation (`results/conf84`, `results/conf85`): PHP 8.4 and 8.5, narrow-1m-10 and wide-100k-10, all implementations and both modes, run in parallel on cores 0-3.
- JSON Machine (`results/jm83`): narrow-100k-10 and wide-100k-10 only, JIT mode only, 1 warm-up, 3 timed runs, 1 RSS run (it is too slow to run the full protocol in the time available).
- Derived tables: `results/analysis/*.txt` (awk over the RAW lines; scripts alongside). Raw per-run lines: `results/<set>/<workload>/raw.log`.

## Hypothesis (SPEC section 9)
At least 2x median throughput against the best PHP baseline (`json_decode` and PECL simdjson, JIT on) on at least one predeclared workload, on x86-64. Memory is not a comparative claim. Bounded memory on oversized lines is a safety property.

## Observed

### Gate result
Gate met on the SPEC's named baselines, with a thin margin against fastjson.
- Narrow-1m-10 (the default narrow workload), isolated, PHP 8.3: JsonFuse 4,079,021 rec/s (median 245.2 ms, p95 254.1 ms). `json_decode` loop (JIT) 1,027,267 rec/s: **3.97x**. PECL simdjson (JIT) 1,629,136 rec/s: **2.50x**. fastjson (JIT) 1,912,785 rec/s: **2.13x**.
- Gate is met at narrow 100k and 1m for 90%, 10% and 1% match (isolated or parallel, see table): lowest passing ratio vs the best baseline is 2.08x (narrow-100k-1 and narrow-1m-1, isolated).
- Gate is NOT met on any wide workload (1.53x to 1.71x vs the best baseline) and not at narrow-10k-1 (1.49x) or narrow-10k-90 (1.84x).
- The best baseline was fastjson (JIT) in every workload. `json_decode` was never the best baseline. fastjson is not named in the SPEC's gate sentence; the 2x claim holds against it only by 4 to 9 percent at the lowest narrow points.

### Per-workload table, PHP 8.3 (JsonFuse vs best baseline)
Rows marked (iso) are the isolated re-run; all others are from the parallel set. Throughput in records/s from the median. Columns "vs X" are median-time ratios (X median / JsonFuse median), each against X's best mode. `*` = JsonFuse config flagged noisy by the rule. RSS in MiB, JsonFuse / best baseline, median of fresh-process runs.

| Workload | JsonFuse rec/s (median / p95 ms) | Best baseline | Best rec/s | Ratio vs best | vs json_decode | vs fastjson | vs simdjson | RSS MiB |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| narrow-10k-90 | 2458887 (4.1/4.2 ms) | fastjson jit | 1334704 | 1.84 | 3.52 | 1.84 | 2.92 | 26.2 / 26.5 |
| narrow-10k-10 | 4184363 (2.4/2.6 ms) | fastjson jit | 1945752 | 2.15 | 3.90 | 2.15 | 2.49 | 26.0 / 26.5 |
| narrow-10k-1 | 3072134 (3.3/3.5 ms) | fastjson jit | 2063271 | 1.49 | 2.64 | 1.49 | 1.63 | 26.2 / 26.5 |
| narrow-100k-90 | 3388122 (29.5/33.0 ms) | fastjson jit | 1363983 | 2.48 | 4.83 | 2.48 | 3.77 | 26.2 / 26.2 |
| narrow-100k-10 (iso) | 4164448 (24.0/24.3 ms) | fastjson jit | 1906262 | 2.18 | 3.88 | 2.18 | 2.53 | 26.2 / 26.5 |
| narrow-100k-1 (iso) | 4335610 (23.1/23.5 ms) | fastjson jit | 2086670 | 2.08 | 3.68 | 2.08 | 2.31 | 26.2 / 26.5 |
| narrow-1m-90 | 3460615 (289.0/293.1 ms) | fastjson jit | 1316849 | 2.63 | 5.06 | 2.63 | 4.18 | 26.2 / 26.5 |
| narrow-1m-10 (iso) | 4079021 (245.2/254.1 ms) | fastjson jit | 1912785 | 2.13 | 3.97 | 2.13 | 2.50 | 26.2 / 26.5 |
| narrow-1m-1 (iso) | 4333302 (230.8/276.9 ms) | fastjson jit | 2078552 | 2.08 | 3.93 | 2.08 | 2.32 | 26.0 / 26.5 |
| wide-10k-90 | 282733 (35.4/52.5 ms)* | fastjson jit | 172676 | 1.64 | 3.75 | 1.64 | 1.79 | 26.5 / 26.5 |
| wide-10k-10 | 286236 (34.9/57.1 ms)* | fastjson jit | 183136 | 1.56 | 3.57 | 1.56 | 1.61 | 26.5 / 26.5 |
| wide-10k-1 | 290873 (34.4/34.8 ms) | fastjson jit | 187275 | 1.55 | 3.63 | 1.55 | 1.61 | 26.2 / 26.5 |
| wide-100k-90 | 290604 (344.1/348.5 ms) | fastjson jit | 171942 | 1.69 | 3.94 | 1.69 | 1.84 | 26.2 / 26.5 |
| wide-100k-10 | 296179 (337.6/338.5 ms) | fastjson jit | 184347 | 1.61 | 3.79 | 1.61 | 1.67 | 26.5 / 26.5 |
| wide-100k-1 | 284448 (351.6/353.3 ms) | fastjson jit | 185636 | 1.53 | 3.55 | 1.53 | 1.56 | 26.2 / 26.2 |
| wide-1m-90 | 288785 (3462.8/3579.4 ms) | fastjson jit | 169338 | 1.71 | 3.90 | 1.71 | 1.86 | 26.5 / 26.5 |
| wide-1m-10 | 298964 (3344.9/3488.3 ms) | fastjson jit | 181122 | 1.65 | 3.79 | 1.65 | 1.70 | 26.5 / 26.5 |
| wide-1m-1 | 302376 (3307.1/3496.3 ms) | fastjson jit | 184284 | 1.64 | 3.80 | 1.64 | 1.70 | 26.2 / 26.5 |

Workloads with 0% match (parse-only) were not run.

Parallel-run vs isolated re-run for the four gate-deciding workloads (ratio vs best baseline, fastjson JIT): narrow-100k-1 2.09 -> 2.08; narrow-100k-10 2.12 -> 2.18; narrow-1m-1 2.21 -> 2.08; narrow-1m-10 2.26 -> 2.13. Isolation lowered three of four ratios by up to 0.13; all stay above 2.0.

### Where JsonFuse lost or was close
- Wide records (100 fields, 3 projected): 1.53x to 1.71x vs fastjson/simdjson, never 2x. JsonFuse sustains about 285k to 305k rec/s (about 650 MiB/s) on wide input, and fastjson/simdjson about 170k to 187k. On wide input JsonFuse beats `json_decode` by 3.6x to 3.9x, so the loss is against the native-decoder baselines only.
- narrow-10k-1: 1.49x vs fastjson (the only narrow case under 1.8x). narrow-10k-90: 1.84x. At 10k the timed section is 2 to 4 ms, so fixed per-run costs weigh more; 10k was a parallel run and not repeated isolated (it does not decide the gate).
- Narrow gate margin vs fastjson at 10% and 1% match is 2.08x to 2.18x (4 to 9 percent above 2x), well inside the run-to-run changes seen between the parallel and isolated runs (up to 0.13). A different host load or CPU could move this under 2x. Against `json_decode` and simdjson, the SPEC's named baselines, the margin is large (3.7x to 5.1x and 2.3x to 4.2x).
- Ratios rise with match rate for narrow (2.08 at 1%, 2.13 to 2.18 at 10%, 2.48 to 2.63 at 90%) probably because the baselines pay for `json_encode` and writes on emitted records (not isolated by an experiment).
- No workload where any baseline was faster than JsonFuse.
- Noisy flags: wide-10k-10 and wide-10k-90 JsonFuse (p95 57.1 ms and 52.5 ms against 34.9 ms and 35.4 ms medians; medians unaffected); narrow-100k-10 fastjson in the isolated set; a few other baseline configs (see `results/analysis`). No sample was dropped.

### Baseline detail, narrow-1m-10 (isolated, median ms, PHP 8.3)
| Implementation | nojit | jit |
| --- | ---: | ---: |
| JsonFuse (native) | n/a | 245.2 |
| `json_decode` loop | 1033.2 | 973.5 |
| PECL simdjson loop | 720.7 | 613.8 |
| fastjson loop | 613.5 | 522.8 |

JIT helped every PHP-level baseline on narrow input (by 6% to 15%); on wide input the nojit/jit difference was within about 3% and nojit was occasionally faster. The best mode was taken per baseline per workload.

### JSON Machine (PHP 8.3, JIT, 100k records, 3 runs)
| Workload | JsonFuse median | JSON Machine median | Ratio |
| --- | ---: | ---: | ---: |
| narrow-100k-10 | 24.0 ms | 571.5 ms (175k rec/s) | 23.8x |
| wide-100k-10 | 337.6 ms | 7275 ms (13.7k rec/s) | 21.5x |

JSON Machine ran the same transform with output byte-identical (`EQUIV` line). It was only run at 100k, so it is not part of the best-baseline column (it was slower than every other baseline).

### PHP 8.4 and 8.5 confirmation (parallel, 5 runs at 1m, 10 at 100k)
| PHP | Workload | JsonFuse rec/s | vs json_decode | vs fastjson | vs simdjson |
| --- | --- | ---: | ---: | ---: | ---: |
| 8.4.27 | narrow-1m-10 | 4,135,661 | 3.93 | 2.17 | 2.72 |
| 8.5.12 | narrow-1m-10 | 4,121,492 | 4.02 | 2.15 | 2.48 |
| 8.4.27 | wide-100k-10 | 306,938 | 3.86 | 1.65 | 1.74 |
| 8.5.12 | wide-100k-10 | 307,691 | 3.90 | 1.68 | 1.74 |

On 8.4 and 8.5 the result is the same as on 8.3: fastjson is the best baseline, narrow clears 2x (2.15 to 2.17), wide does not (1.65 to 1.68). On 8.5 the best wide baseline mode for fastjson was nojit. These are parallel runs, not isolated.

### Memory
Max RSS from fresh processes, no warm-up, MiB (median of 3): every implementation sits at about 25 to 28 MiB regardless of file size (narrow-1m-10: JsonFuse 26.3, json_decode 25.3 to 26.3, fastjson 25.8 to 26.5, simdjson 27.0 to 27.7; wide-1m-10: JsonFuse 26.5, json_decode 25.5 to 26.3, fastjson 25.8 to 26.5, simdjson 27.2 to 27.9). This is PHP's floor; memory is not a comparative claim and JsonFuse neither wins nor loses it in a meaningful way. RSS includes the native allocations and mappings (it is the process max RSS), not just `memory_get_peak_usage`.

### Oversized-record bound (SPEC section 6 bound, harness `check_memory_bound`)
- `oversized-bound` (2,000 records of 1 MiB, 2,097,154,000 bytes read, cap 1 MiB): JsonFuse max RSS 27,840 to 28,080 KiB (27.2 to 27.4 MiB) against a bound of 96,324 KiB, which includes an 80 MiB allowance. Each record has 4 keys plus one huge string, the best case for parser memory. BOUND OK. Median 1179 ms for the whole 2 GiB (about 1.7 GiB/s). 189 of 2,000 records matched, as expected.
- `oversized-line` (one 64 MiB line, cap 1 MiB): refused at the cap with RecordException in about 0.67 ms, 0 records read, max RSS 27,600 KiB (27.0 MiB) against the same bound. BOUND OK. The baselines were not run on these two workloads (the harness runs JsonFuse only), so there is no measurement of how the baselines behave there; the README states they read the whole line first.

### Validation differences (affects fairness)
From `benchmarks/README.md` and `run.sh --equiv` (all agree on every other case, 32 hand-written cases plus 6 generated comparisons): JsonFuse rejects a duplicate referenced key, the three baselines accept it (last wins). JsonFuse stops at the cap on an oversized line; the baselines call `fgets`, which reads the whole line first. So JsonFuse does more validation on duplicates and the baselines do none there, while on valid data all outputs were byte-identical. The baseline loops (`benchmarks/lib.php`) are hand-written validation of field presence and type; their rejection set matched JsonFuse on all other equivalence cases.

## Unresolved
- Cold-cache behavior is not established (fixtures live in a Docker volume, page cache warm). No cold claim is made.
- Concurrency (comparison dimension 6 of `BENCHMARK_PROTOCOL.md`: 4, 16, 32 workers, shared vs proportional memory) was not measured.
- Parser-only (0% emit) workloads were not run, so the "parser-only" labelling asked for by SPEC section 9 is not covered; every row is full pipeline.
- Wide-record ratios (1.53x to 1.71x, several inside the 1.6 to 2.4 window) were not re-run isolated. They cannot change the pass of the gate, but the exact wide numbers carry the parallel-run uncertainty (about 0.1 shown for narrow).
- Another tenant's benchmark shared the host (CPUs 10-29, memory bandwidth, L3 unknown). Core placement relative to hyperthread siblings or NUMA nodes is not visible from the guest. Between-job drift was not controlled beyond the isolated re-runs.
- JIT mode `off` (opcache disabled) was not measured; PHP baselines may be slower there, which can only increase JsonFuse's ratio.
- JSON Machine at 1m records was not measured (README says the tokenizer is too slow); JSON Machine ran only 3 timed runs at 100k.
- The 8.4/8.5 confirmation used a smaller run set and was run in parallel.
- Only x86-64 was measured.

## Known limit: plans without range, `in` or path filters (2026-11-10)
Measured after range and `in` filters and `where` paths were added, against the `src/` from before them. The bar was fixed before measuring: a plan that uses none of the new features must stay within 1.0% of the base on narrow-1m-10 and wide-100k-10. It is met on wide and missed on narrow; the narrow result is accepted as a known limit.
- Method: Linux x86-64, 30 vCPU, PHP 8.3.36 NTS in Docker, `php -n`, the narrow-1m-10 and wide-100k-10 workloads of `benchmarks/lib.php` (fixtures 119,958,670 and 229,848,318 bytes), one core alone (builds on a second core), 9 alternating runs per build per session, median wall; CPU time equals wall time. Three sessions in a quiet window (2026-11-10, 19:51Z to 19:59Z, load average 1.3 to 4.0, no other container on the four cores around the timed one, steal ticks flat, no session bimodal). Five earlier sessions on a busy host are not counted.
- Padding control: the base `src/` padded so its hot function (`jf_run_guarded`) sits at the same code address as in the new build (0x7820 against 0x7830; unpadded base 0x6920), to separate code placement from code change.

| Session | narrow-1m-10 base / new / padded base (s) | new / base | padded / base | wide-100k-10 new / base | padded / base |
| --- | --- | --- | --- | --- | --- |
| 1 | 0.2349 / 0.2381 / 0.2365 | 1.0136 | 1.0068 | 1.0030 | 1.0033 |
| 2 | 0.2348 / 0.2377 / 0.2348 | 1.0124 | 1.0000 | 1.0095 | 1.0074 |
| 3 | 0.2357 / 0.2397 / 0.2356 | 1.0170 | 0.9996 | 1.0080 | 0.9991 |

- Pooled over 27 narrow runs: new / base 1.0128, padded / base 0.9996. CPU-time ratio on narrow: 1.0132, 1.0124, 1.0170.
- Callgrind, 100,000 records: narrow 234,164,904 instructions against 233,174,619 (+9.9 per record, +0.42%); wide 2,492,146,919 against 2,491,256,811 (+8.9 per record, +0.04%).
- Not resolved: the narrow cost in wall time (1.2% to 1.7%) is larger than the instruction count change (+0.42%); code placement explains about half of it in session 1 and none in sessions 2 and 3.
- Cost of the new filters against an `=` filter, narrow-1m-10, same sessions (information, no bar): one range operator 1.015 / 1.017 / 1.015; two (`>=` and `<`) 1.055 / 1.065 / 1.060; a 16-member `in` 1.084 / 1.094 / 1.085.

## Validating scan against yyjson only (2026-11-10)
The `src/` of this release measured twice: as built (scan) and with `-DJF_DISABLE_SCAN` (base, yyjson only),. The bars were fixed before measuring: wide at least 20% faster (base / scan >= 1.20) and narrow no more than 2% slower (scan / base <= 1.02). Both are met in all 12 cells.
- Provenance: a git checkout with an empty `git status --porcelain`; both `jsonfuse.so` built from its `git archive` with the image's `PHP_CFLAGS -Wall -Wextra`, the base with `-DJF_DISABLE_SCAN` added (0 warnings; scan `557e2a2c25b7c2c0`, base `c918b003600fca61`; `php --ri jsonfuse` shows `validating scan => enabled` and `disabled`).
- Method: Linux x86-64, 30 vCPU, PHP 8.3.36 NTS in Docker, `php -n` (no opcache), the `benchmarks/lib.php` plans with NDJSON output to `/dev/null`, fixtures checked against `fixtures.sha256`; per session and cell, the outputs of both builds hashed and compared first (identical in every cell of every session), one untimed read of the fixture, then 9 alternating runs of base, scan and an A/A copy of the base on one core alone (builds on a second, two idle). Records read, matched and emitted and bytes written are identical across all runs of a cell; CPU time equals wall time within 1% in 1,612 of 1,620 runs (eight runs 1% to 6%, six of them in session 3). Ratio per cell: median of the session ratios of median wall times over the three counted sessions.
- Sessions: a session is void if the A/A control at narrow-1m-10 is more than 0.5% from 1, another container runs on the four timing cores, or steal ticks rise on the timed core; a void session is repeated once. Five sessions ran on 2026-11-10 from 23:06Z to 23:36Z (host load average 15 to 19 on other cores, no other container on the timing cores in any session). Two were treated as void while the run went on: session 1 (A/A 0.9923 at narrow-1m-10, steal ticks on the timed core +5) and session 3 (A/A 0.9916 at wide-1m-90, a cell the void rule does not include; its A/A at narrow-1m-10 is 0.9968 and its steal ticks did not change). Sessions 4 and 5 were run as their repeats. By the rule only session 1 is void, so sessions 2, 3 and 4 are counted (4 is the repeat of 1); session 5 is listed and not counted (steal ticks +1). Every one of the five sessions meets both bars in every cell on its own, and the median of all five differs from the counted ratio by at most 0.0031.

| Cell | Base median [min, max] (s) | Scan median [min, max] (s) | Ratios scan / base, sessions 1 to 5 | Ratio (sessions 2 to 4) | Median of all five | Bar | Result |
| --- | --- | --- | --- | --- | --- | --- | --- |
| narrow-100k-1 | 0.0237 [0.0232, 0.0244] | 0.0217 [0.0212, 0.0225] | 0.9239, 0.9172, 0.9195, 0.9163, 0.9169 | 0.9172 | 0.9172 | <= 1.02 | pass |
| narrow-100k-10 | 0.0246 [0.0239, 0.0252] | 0.0225 [0.0219, 0.0265] | 0.9162, 0.9156, 0.9152, 0.9168, 0.9164 | 0.9156 | 0.9162 | <= 1.02 | pass |
| narrow-100k-90 | 0.0288 [0.0280, 0.0305] | 0.0269 [0.0263, 0.0273] | 0.9392, 0.9397, 0.9325, 0.9362, 0.9342 | 0.9362 | 0.9362 | <= 1.02 | pass |
| narrow-1m-1 | 0.2353 [0.2317, 0.2395] | 0.2153 [0.2117, 0.2181] | 0.9137, 0.9186, 0.9141, 0.9172, 0.9137 | 0.9172 | 0.9141 | <= 1.02 | pass |
| narrow-1m-10 | 0.2442 [0.2408, 0.2598] | 0.2227 [0.2198, 0.2560] | 0.9106, 0.9100, 0.9096, 0.9101, 0.9122 | 0.9100 | 0.9101 | <= 1.02 | pass |
| narrow-1m-90 | 0.2843 [0.2800, 0.2941] | 0.2655 [0.2615, 0.2738] | 0.9337, 0.9314, 0.9340, 0.9323, 0.9354 | 0.9323 | 0.9337 | <= 1.02 | pass |
| wide-100k-1 | 0.3401 [0.3360, 0.3508] | 0.2676 [0.2634, 0.2784] | 0.7895, 0.7865, 0.7865, 0.7865, 0.7887 | 0.7865 (1.271x) | 0.7865 (1.271x) | base / scan >= 1.20 | pass |
| wide-100k-10 | 0.3416 [0.3364, 0.3498] | 0.2691 [0.2645, 0.2749] | 0.7895, 0.7893, 0.7869, 0.7907, 0.7877 | 0.7893 (1.267x) | 0.7893 (1.267x) | base / scan >= 1.20 | pass |
| wide-100k-90 | 0.3494 [0.3439, 0.3641] | 0.2764 [0.2727, 0.2801] | 0.7886, 0.7915, 0.7917, 0.7913, 0.7911 | 0.7915 (1.263x) | 0.7913 (1.264x) | base / scan >= 1.20 | pass |
| wide-1m-1 | 3.3754 [3.3263, 3.4553] | 2.6475 [2.6114, 2.6846] | 0.7851, 0.7853, 0.7853, 0.7835, 0.7847 | 0.7853 (1.273x) | 0.7851 (1.274x) | base / scan >= 1.20 | pass |
| wide-1m-10 | 3.3869 [3.3544, 3.4659] | 2.6545 [2.6347, 2.6954] | 0.7854, 0.7825, 0.7836, 0.7870, 0.7841 | 0.7836 (1.276x) | 0.7841 (1.275x) | base / scan >= 1.20 | pass |
| wide-1m-90 | 3.4699 [3.4282, 3.5227] | 2.7283 [2.7006, 2.7553] | 0.7869, 0.7888, 0.7813, 0.7867, 0.7863 | 0.7867 (1.271x) | 0.7867 (1.271x) | base / scan >= 1.20 | pass |

Median, min and max are over the 45 runs of all five sessions. Wide records take 21% to 22% less time (1.26x to 1.28x throughput), narrow records 6% to 9% less. Not measured here: opcache or JIT modes (the extension is native code; the PHP side is one `Runner::process` call), other PHP versions, aarch64, baselines (the comparison with `json_decode`, simdjson and `fastjson` above is from the earlier build, before the scan).

## Harness note
`oversized-line` fails under `run.sh` with the default warm-ups: the warm-up loop in `benchmarks/bench_jsonfuse.php` calls `Runner::process` outside the `try`, so the expected `RecordException` becomes an uncaught fatal (exit 255), and because `run.sh` captures the script's stdout in `$line` the error text is swallowed and the job ends silently with rc=255. This bug was fixed after this run. The harness was not changed for the run; that workload ran with `--warmups 0` (`results/main83/oversized-line`, the failed first attempt left as `oversized-line.stdout`). Suggested fix: catch `RecordException` in the warm-up loop when `--expect-reject` is set.

## Validation
- Output equivalence checks: `run.sh --equiv` PASS; byte-identical outputs across all implementations and modes in every job.
- Test suite status: not run (out of scope for this measurement).
- Raw samples: `benchmarks/results/` (the `RAW` lines in each job's `.stdout`, plus the `analysis/` scripts and summaries).

## Reproduction
```sh
rsync -az --delete --exclude=.git ./ <host>:<work-dir>/
# on the benchmark machine, one job per core, for example:
JF_COMMIT=$(git rev-parse HEAD) benchmarks/run.sh --php 8.3 --cpuset 0 --modes nojit,jit --impls jsonfuse,php,simdjson,fastjson --runs 5 --warmups 2 --rss-runs 3 narrow-1m-10
```
The driver used for the series is `benchmarks/results/analysis/launch.sh`; summary scripts are `stats.sh`, `ratios.sh`, `md.sh` in the same directory.
