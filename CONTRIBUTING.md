# Contributing to JsonFuse

Behaviour is defined by `SPEC.md`. A change that makes `SPEC.md` wrong must change `SPEC.md` too.

## Before a pull request

1. Build and run the suite on every PHP line you can:

   ```sh
   phpize && ./configure --enable-jsonfuse && make -j"$(nproc)"
   make test NO_INTERACTION=1 TESTS=tests
   ```

2. Compile with warnings on. `-Wall -Wextra` must be silent for `src/`. `nm -D modules/jsonfuse.so` must show no `yyjson_` symbols.
3. If the change touches native code, run the sanitizer job: `.github/ci/sanitizer.sh` runs in a `debian:trixie-slim` container and covers the ASan/UBSan PHPT run, the Zend allocator run and the fuzz corpus replay.
4. Mutation-check every new test: break the code it guards and confirm the test fails. A test that still passes against broken code is not evidence.
5. If you changed native parsing, framing, validation or writing, run the fuzz harness: `fuzz/run.sh <seconds> <workers>`.

## Rules worth repeating

- Native code must never call `exit()`, use `assert()` for input validation, or rely on undefined behaviour.
- Check integer arithmetic before allocation and pointer arithmetic.
- No `php_stream *` is held across a call that can run PHP. See `docs/DESIGN.md`.
- Do not add a performance claim without a recorded `benchmarks/run.sh` result.
- `third_party/yyjson/` stays unmodified; change the pin in `PIN.txt` to update it.

## Tests

Tests live in `tests/*.phpt`. Do not match PHP's `fclose()` refusal warning literally: its text differs on 8.5, use `--EXPECTF--` with `%s` for that line. Tests that read `memory_limit` must `SKIPIF` when `USE_ZEND_ALLOC=0`, because the limit is not enforced then.

## Commits

Use conventional commit messages (`fix:`, `feat:`, `test:`, `docs:`). Keep each commit focused.
