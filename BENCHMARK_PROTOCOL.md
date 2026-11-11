# BENCHMARK_PROTOCOL.md — Reproducible PHP Native Extension Performance Tests

## Purpose

A native extension is valuable only if its real benefits survive realistic workloads, conversion overhead, error handling, and memory measurement. This protocol is shared by the JsonFuse benchmarks and any related extension benchmarks.

## Required comparison dimensions

1. **End-to-end:** read source data → perform equivalent operation → produce equivalent observable output. Include parsing and conversion costs.
2. **Steady-state:** data already in the expected input representation, with startup/setup time shown separately.
3. **Small / medium / large:** use sizes that fit baseline memory and identify where/why the baseline fails.
4. **Input mix:** include both highly selective and nonselective filters, narrow/wide records, realistic strings, nulls and invalid data where relevant.
5. **Cold vs warm:** explicitly distinguish cold file/page-cache or process startup from warmed repeated operations. Never claim a cold-cache test unless cold conditions were actually established.
6. **Concurrency:** one PHP process, and (where relevant) 4, 16, and 32 independent PHP-FPM-like worker processes. Record concurrency overhead and total RSS; distinguish shared pages from proportional per-process sums.

## Measurement plan

- Fixed deterministic fixture seed; commit a generator and fixture schema, not enormous generated files.
- Generate fixture/checksum before timed runs. Verify outputs and checksums before comparing timing.
- Use separate fresh PHP processes for peak memory measurements; `memory_get_peak_usage()` misses much native/mmap memory.
- Prefer `/usr/bin/time -v` and/or an OS-aware process monitor for maximum RSS, with exact command logs. For multi-worker tests, consider proportional set size (PSS) or unique set size (USS) when available.
- Record wall-clock time and CPU time. Never treat wall-clock time as a direct measurement of CPU usage.
- Warm up JIT/opcache/CPU conditions as applicable and report whether PHP JIT/opcache was enabled.
- Use at least 3 warm-ups and 10 timed runs per configuration where practical; publish median and p95 (and raw samples).
- Use isolated, consistent CPU governor/load and report the machine: CPU model, cores, RAM, architecture, kernel, PHP version/build, ZTS/NTS, compiler flags, extensions, dependency versions.
- Repeat the benchmark to check variance. Never discard inconvenient slow samples without an explained, predefined rule.
- Report both absolute throughput (records/s or lookups/s) and ratios; report memory in MiB and explain whether native allocations and file mappings are included.

## Workload-specific baselines

| Workload | Required baselines | Data sizes and observations |
| --- | --- | --- |
| Tabular array processing | Idiomatic PHP array loops/`array_filter`/`array_map`/aggregation; optional ext-ds; optional SQLite/DuckDB for tabular tasks | 10k, 100k, 1m rows; typed input vs CSV end-to-end; string/numeric mix |
| NDJSON validate, filter, project (JsonFuse) | `json_decode` + pure PHP + `json_encode`; streaming JSON library; PHP simdjson if compatible | 10k, 100k, 1m NDJSON records; small/wide records; high/low match percentages |
| Read-only index lookup | PHP array lookup; APCu; `nocopy` if available; SQLite indexed read-only query | 10k/100k/1m rows; 1, 4, 16, 32 processes; cold/warm; point lookups, missing-key lookups |

## Correctness before performance

- Compare **all outputs** on small reference fixtures.
- For aggregations, document floating-point evaluation order/tolerance and 64-bit overflow behavior.
- For JSON, verify type fidelity, field order where contractual, Unicode escapes, null/missing distinction, duplicate key policy and output parseability.
- For read-only index lookups, verify lookup results across atomic snapshot replacement and concurrent readers.
- Use deterministic failure-case tests independently from throughput tests.

## Performance report template

```markdown
# Benchmark report YYYY-MM-DD

## Environment
- OS/kernel:
- CPU / arch / RAM:
- PHP version / NTS or ZTS / build options:
- Compiler and flags:
- Extension commit:
- Baseline versions:
- Data generator commit / seed:
- OPcache/JIT:

## Workload
- Semantics:
- Input size and format:
- Full-pipeline or steady-state:
- Worker count:
- Warm or cold cache conditions:

## Result
| Implementation | Median wall time | p95 | Throughput | Max RSS | Notes |
| --- | ---: | ---: | ---: | ---: | --- |

## Validation
- Output equivalence checks:
- Test suite status:

## Interpretation
- Where the native extension wins:
- Where it loses:
- Potential measurement artifacts:
- Reproduction command:
```

## Suggested release thresholds

No single speedup percentage is a universal release gate. A usable beta should provide demonstrated speed/memory benefit on **at least one predeclared real-world target workload**, without correctness or safety regressions. Document negative benchmarks openly.
