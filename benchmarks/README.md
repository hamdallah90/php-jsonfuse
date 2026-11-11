# JsonFuse benchmarks

Follows `BENCHMARK_PROTOCOL.md`. Everything runs in Docker so the PHP build, baselines and extension are identical between runs.

```sh
benchmarks/run.sh --list                         # workload names
benchmarks/run.sh --equiv                        # equivalence check, must pass first
benchmarks/run.sh --cpuset 2-9 --runs 10 --warmups 3 --rss-runs 3 narrow-1m-10 wide-100k-1
```

Raw per-run lines go to `benchmarks/results/<timestamp>-php<version>/raw.log` (or `--results DIR`). Each result line starts `RAW kind=timed|rss`, with wall, user, system and max RSS from `/usr/bin/time`, the timed section's own nanoseconds, record and byte counts, and the output SHA-256 prefix. `ENV` lines record the CPU, kernel, compiler, PHP and dependency versions. Every run asserts opcache and JIT state from inside PHP and exits 3 on a mismatch.

**Provenance.** Before any timed run, `provenance.sh` runs inside the container on the tree the image copied (`.git` included). It requires a git checkout with an empty `git status --porcelain`, builds `jsonfuse.so` in that job from `git archive HEAD`, installs it for the run, and writes `PROVENANCE` lines to `raw.log`: the commit, the empty status, the `.so` SHA-256 and `php --ri jsonfuse`, then `PROVENANCE publishable=yes`. On a dirty tree or a copy without `.git` it writes `publishable=no` and the run stops with exit 6. `--no-provenance` skips the check for development runs and records `publishable=no`. Only numbers from a log with `publishable=yes`, measured on the release commit, are published.

`JF_EXTRA_CFLAGS` (default empty) is appended to the extension's `CFLAGS` in that build and recorded on the `PROVENANCE extra_cflags=` and `ENV` lines. The wide-scan comparison measures one commit twice: as built, and with `JF_EXTRA_CFLAGS=-DJF_DISABLE_SCAN` as the base.

`JF_COMMIT=<sha>` only labels the `ENV` line of a run from a tree without `.git` (for example an rsynced copy); such a run is never publishable.

## Measurement rules

Per protocol: a fixture checksum is verified before timing; every implementation's output for a workload must hash the same or the run exits 4; each configuration gets repeated fresh-process runs, with in-process warm-ups for the timed runs and separate warm-up-free runs for max RSS. The results file is the record: take medians and p95 from `RAW` lines; never hand-pick. Cold-cache conditions are not established; the page cache is warm after the first read, and the logs say so.

## Workloads (SPEC section 9)

| Name | Records | Fields in | Emitted | Note |
| --- | --- | --- | --- | --- |
| `narrow-<size>-<pct>` | 10k, 100k, 1m | 6 | 2 fields | `pct` is 10 (default narrow workload), 90 (high match) or 1 (low match) |
| `wide-<size>-<pct>` | 10k, 100k, 1m | 100 | 3 fields | |
| `<shape>-<size>-0` | 10k, 100k, 1m | | none | Every record parsed and validated, nothing emitted: the closest the public API gets to parse-only |
| `oversized-bound` | 2,000 records of 1 MiB | | | Bound test, not a comparison: asserts max RSS against the spec bound while reading about 2 GiB |
| `oversized-line` | one 64 MiB line | | | Bound test: the line must be refused at the cap with RSS staying bounded |

Implementations: `jsonfuse` (the real extension via `Plan` and `Runner`), `php` (`fgets` + `json_decode` + hand-written validation and `json_encode`), `simdjson` (same loop, PECL `simdjson_decode`), `fastjson` (same loop, `fastjson_decode` and `fastjson_encode`), `jsonmachine` (the streaming JSON library, see below). Modes: `off`, `nojit`, `jit`.

Baseline loops are generated per schema into include files so opcache and the JIT apply to them, in the form a developer would write by hand. Read `lib.php` for the source.

`jsonmachine` runs up to 100k records by default: above that it is skipped with a recorded reason, because the userland tokenizer takes very long. Run with `--impls jsonmachine` alone to measure it above that size.

## Where the implementations do not match

`benchmarks/check_equiv.php` (`run.sh --equiv`) lists every disagreement; on valid generated data outputs are byte-identical. On invalid input:

| Input | jsonfuse | Baselines | Favours |
| --- | --- | --- | --- |
| Duplicate referenced key | rejects | last wins, accepted | baselines: they skip the key walk |
| Oversized record | rejected at the cap, bounded buffer | `fgets` reads the whole line, then rejects | baselines: no cost on valid data, unbounded on hostile input |

JSON Machine reads one JSON document, so the runner hands it the file as one array, line by line. It does not enforce a per-record cap. It fails on malformed lines through its own exceptions, and its validation differs in the same way as the decode-based baselines.

Concurrency (comparison dimension 6 of `BENCHMARK_PROTOCOL.md`): JsonFuse is a single-process transform; the driver records one PHP process per run and does not model PHP-FPM workers.

## Results

Measured on a pre-release build by a separate benchmark run, recorded in full in `RESULTS.md` (this directory): Intel Xeon Platinum 8358, x86-64 KVM guest, PHP 8.3.36 NTS, opcache on, JIT on and off, shared host, jobs pinned to CPUs 0 to 9. Summary, PHP 8.3, full pipeline, median of 5 to 10 timed runs:

| Comparison | Ratio (baseline time / JsonFuse time) |
| --- | --- |
| `json_decode` loop | 2.64x to 5.06x over 18 workloads; 3.55x to 5.06x at 100k and 1m records |
| PECL simdjson 4.0.0 | 1.56x to 4.18x |
| `fastjson` 0.10.2 (best baseline everywhere) | 1.49x to 2.63x; wide records 1.53x to 1.71x; narrow at 100k and 1m 2.08x to 2.63x; narrow at 10k 1.49x to 2.15x |
| JSON Machine 1.2.6, 100k records, 3 runs | 21.5x (wide), 23.8x (narrow) |

The 2x gate of SPEC section 9 is met on the narrow workloads at 100k and 1m records against `json_decode` and PECL simdjson, and against `fastjson` by a thin margin (2.08x to 2.18x at 10% and 1% match, isolated re-runs). It is not met on any wide workload.

Memory: every implementation sits at about 25 to 28 MiB max RSS, PHP's floor, so there is no comparative memory claim. The oversized-record bound held: 2,000 records of 1 MiB peaked at 27,840 to 28,080 KiB RSS (27.2 to 27.4 MiB), and one 64 MiB line was refused at the 1 MiB cap with `RecordException` at 27,600 KiB (27.0 MiB), both under the bound of 96,324 KiB, which includes an 80 MiB allowance. Each oversized record had 4 keys plus one huge string, the best case for parser memory.

Not measured: cold cache, concurrent workers, opcache-disabled mode, parser-only (0% match) workloads, JSON Machine above 100k records, non-x86-64 CPUs. All results come from one shared KVM host.
