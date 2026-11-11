#!/usr/bin/env bash
# Benchmark driver: see benchmarks/README.md and BENCHMARK_PROTOCOL.md. Raw per-run lines go to the results file.
#
#   run.sh [options] [workload ...]      default workloads: every narrow and wide workload
#   run.sh --list | --equiv | --env | --fixtures
#
# options:
#   --php 8.3|8.4|8.5      PHP line of the image (default 8.3)
#   --cpuset LIST          docker --cpuset-cpus, for example 2-9 (default: not pinned)
#   --runs N               timed runs per configuration (default 10)
#   --warmups N            in-process warm-ups before each timed run (default 3)
#   --rss-runs N           separate RSS runs with no warm-up (default 3)
#   --modes LIST           off,nojit,jit (default all three)
#   --impls LIST           jsonfuse,php,simdjson,fastjson,jsonmachine (default all)
#   --results DIR          results directory (default benchmarks/results/<UTC timestamp>)
#   --no-provenance        skip benchmarks/provenance.sh; the log then says publishable=no (development runs only)
set -euo pipefail

SEED=20261109
SIZES="10k 100k 1m"
PCTS="10 90 1 0"
ALL_WORKLOADS=""
for shape in narrow wide; do
    for size in $SIZES; do
        for pct in $PCTS; do
            ALL_WORKLOADS+="$shape-$size-$pct "
        done
    done
done
OVERSIZED="oversized-bound oversized-line"

PHP=8.3 CPUSET="" RUNS=10 WARMUPS=3 RSS_RUNS=3 MODES=off,nojit,jit IMPLS=jsonfuse,php,simdjson,fastjson,jsonmachine RESULTS="" CMD=run PROVENANCE=1
WORKLOADS=()
while [ $# -gt 0 ]; do
    case "$1" in
        --php) PHP=$2; shift 2 ;;
        --cpuset) CPUSET=$2; shift 2 ;;
        --runs) RUNS=$2; shift 2 ;;
        --warmups) WARMUPS=$2; shift 2 ;;
        --rss-runs) RSS_RUNS=$2; shift 2 ;;
        --modes) MODES=$2; shift 2 ;;
        --impls) IMPLS=$2; shift 2 ;;
        --results) RESULTS=$2; shift 2 ;;
        --no-provenance) PROVENANCE=0; shift ;;
        --list) CMD=list; shift ;;
        --fixtures) CMD=fixtures; shift ;;
        --equiv) CMD=equiv; shift ;;
        --env) CMD=env; shift ;;
        -*) echo "unknown option: $1" >&2; exit 2 ;;
        *) WORKLOADS+=("$1"); shift ;;
    esac
done
if [ ${#WORKLOADS[@]} -eq 0 ]; then
    # shellcheck disable=SC2206
    WORKLOADS=($ALL_WORKLOADS $OVERSIZED)
fi

if [ -z "${JF_IN_CONTAINER:-}" ]; then
    ROOT=$(cd "$(dirname "$0")/.." && pwd)
    IMAGE="jsonfuse-bench:php$PHP"
    VOLUME="jsonfuse_bench_fixtures"
    RESULTS=${RESULTS:-$ROOT/benchmarks/results/$(date -u +%Y%m%dT%H%M%SZ)-php$PHP}
    mkdir -p "$RESULTS"
    RESULTS=$(cd "$RESULTS" && pwd)
    docker build --quiet --build-arg PHP_TAG="$PHP-cli" -t "$IMAGE" -f "$ROOT/benchmarks/Dockerfile" "$ROOT" >/dev/null
    docker_args=(--rm -e JF_IN_CONTAINER=1 -e JF_EXTRA_CFLAGS="${JF_EXTRA_CFLAGS:-}" --tmpfs /out:size=16g -v "$VOLUME":/fixtures -v "$RESULTS":/results
        -e JF_COMMIT="${JF_COMMIT:-$(git -C "$ROOT" rev-parse HEAD 2>/dev/null || echo unknown)}"
        -e JF_DIRTY="$(git -C "$ROOT" status --porcelain 2>/dev/null | wc -l)"
        -e JF_BASE_DIGEST="$(docker image inspect --format '{{join .RepoDigests ","}}' "php:$PHP-cli" 2>/dev/null | grep . || echo unknown)")
    [ -n "$CPUSET" ] && docker_args+=(--cpuset-cpus "$CPUSET")
    # Arguments are forwarded verbatim; the container side reads them through the same parser.
    forward=(--php "$PHP" --runs "$RUNS" --warmups "$WARMUPS" --rss-runs "$RSS_RUNS" --modes "$MODES" --impls "$IMPLS")
    [ "$CMD" != run ] && forward+=("--$CMD")
    [ "$PROVENANCE" = 0 ] && forward+=(--no-provenance)
    exec docker run "${docker_args[@]}" "$IMAGE" /jsonfuse/benchmarks/run.sh "${forward[@]}" "${WORKLOADS[@]}"
fi

COMMON=(-d memory_limit=-1 -d serialize_precision=-1)
BENCH=/jsonfuse/benchmarks
JF_LOG=/results/raw.log
OPCACHE_EXT=()
php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' || OPCACHE_EXT=(-d zend_extension=opcache)

mode_flags() {
    case "$1" in
        off)   printf '%s\n' -d opcache.enable=0 -d opcache.enable_cli=0 ;;
        nojit) printf '%s\n' "${OPCACHE_EXT[@]}" -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit=off -d opcache.jit_buffer_size=0 -d opcache.file_update_protection=0 ;;
        jit)   printf '%s\n' "${OPCACHE_EXT[@]}" -d opcache.enable=1 -d opcache.enable_cli=1 -d opcache.jit=tracing -d opcache.jit_buffer_size=64M -d opcache.file_update_protection=0 ;;
        *) echo "bad mode $1" >&2; exit 2 ;;
    esac
}

impl_ext() { # jsonfuse needs its own extension; the others need theirs
    case "$1" in
        jsonfuse) printf '%s\n' -d extension=jsonfuse ;;
        simdjson) printf '%s\n' -d extension=simdjson ;;
        fastjson) printf '%s\n' -d extension=fastjson ;;
        php|jsonmachine) : ;;
        *) echo "bad impl $1" >&2; exit 2 ;;
    esac
}

impl_ok() { # impl: returns 0 if runnable on this image, prints the recorded reason otherwise
    case "$1" in
        simdjson)
            if [ -f /simdjson-unavailable ]; then
                echo "SKIP impl=simdjson reason=$(cat /simdjson-unavailable)" | tee -a "$JF_LOG"
                return 1
            fi
            php -d extension=simdjson -r 'exit(function_exists("simdjson_decode") ? 0 : 1);' 2>/dev/null || {
                echo "SKIP impl=simdjson reason=extension_did_not_load" | tee -a "$JF_LOG"
                return 1
            } ;;
        fastjson)
            php -d extension=fastjson -r 'exit(function_exists("fastjson_decode") ? 0 : 1);' 2>/dev/null || {
                echo "SKIP impl=fastjson reason=extension_did_not_load" | tee -a "$JF_LOG"
                return 1
            } ;;
    esac
    return 0
}

fixture_args() { # workload -> shape count pct_or_bytes
    local w=$1 shape size pct count
    case "$w" in
        oversized-bound) echo "oversized-bound 2000 1048576 0"; return ;;
        oversized-line)  echo "oversized-line 67108864 0 0"; return ;;
    esac
    IFS=- read -r shape size pct <<<"$w"
    case "$size" in
        10k) count=10000 ;;
        100k) count=100000 ;;
        1m) count=1000000 ;;
        *) echo "bad workload $w" >&2; exit 2 ;;
    esac
    echo "$shape $count $pct"
}

ensure_fixture() { # workload path
    local w=$1 path=$2 shape arg2 arg3 want
    read -r shape arg2 arg3 _ <<<"$(fixture_args "$w")"
    if [ ! -s "$path" ] || [ ! -s "$path.sha256" ]; then
        case "$shape" in
            oversized-bound) php -d memory_limit=-1 "$BENCH/generate_ndjson.php" "$shape" "$arg2" "$arg3" "$SEED" "$path" ;;
            oversized-line)  php -d memory_limit=-1 "$BENCH/generate_ndjson.php" "$shape" "$arg2" "$SEED" "$path" ;;
            *)               php -d memory_limit=-1 "$BENCH/generate_ndjson.php" "$shape" "$arg2" "$arg3" "$SEED" "$path" ;;
        esac
        sha256sum "$path" | awk '{print $1}' > "$path.sha256"
    fi
    [ "$(sha256sum "$path" | awk '{print $1}')" = "$(cat "$path.sha256")" ] || { echo "FIXTURE CHECKSUM MISMATCH: $w" >&2; exit 5; }
    # When a pin for this fixture exists, it must match: fixtures are reproducible across machines.
    want=$(awk -v f="$w" '$2 == f {print $1}' "$BENCH/fixtures.sha256" 2>/dev/null || true)
    if [ -n "$want" ] && [ "$want" != "$(cat "$path.sha256")" ]; then
        echo "FIXTURE PIN MISMATCH: $w" >&2; exit 5
    fi
}

show_env() {
    echo "ENV php=$(php -r 'echo PHP_VERSION;') nts=$(php -r 'echo PHP_ZTS ? "no" : "yes";') debug=$(php -r 'echo PHP_DEBUG ? "yes" : "no";')"
    echo "ENV arch=$(uname -m) kernel=$(uname -r) nproc=$(nproc) mem_kb=$(awk '/MemTotal/{print $2}' /proc/meminfo)"
    echo "ENV cpu=$(grep -m1 'model name' /proc/cpuinfo | sed 's/.*: //') governor=$(cat /sys/devices/system/cpu/cpu0/cpufreq/scaling_governor 2>/dev/null || echo unknown)"
    echo "ENV compiler=$(cc --version | head -1)"
    echo "ENV ext_cflags=$PHP_CFLAGS extra_cflags=${JF_EXTRA_CFLAGS:-}"
    echo "ENV commit=${JF_COMMIT:-unknown} dirty_paths=${JF_DIRTY:-unknown} base_image=${JF_BASE_DIGEST:-unknown}"
    echo "ENV jsonfuse=$(php -d extension=jsonfuse -r 'echo phpversion("jsonfuse");') yyjson=$(awk '/^tag:/{print $2}' /jsonfuse/third_party/yyjson/PIN.txt)"
    echo "ENV simdjson=$(php -d extension=simdjson -r 'echo phpversion("simdjson");' 2>/dev/null || echo unavailable) fastjson=$(php -d extension=fastjson -r 'echo phpversion("fastjson");' 2>/dev/null || echo unavailable) json_machine=1.2.6"
    echo "ENV fixtures=docker-volume (page cache warm after first read; cold cache is NOT established) output=tmpfs"
}

one_run() { # run_kind workload impl mode shape fixture run warmups extra...
    local kind=$1 w=$2 impl=$3 mode=$4 shape=$5 fixture=$6 run=$7 warmups=$8; shift 8
    local out=/out/result.ndjson tf=/out/time.txt line sha wall user sys rss
    local script=("$BENCH/bench_php.php" "$impl" "$mode")
    local env_prefix=(env WARMUPS="$warmups")
    if [ "$impl" = jsonfuse ]; then
        script=("$BENCH/bench_jsonfuse.php" "$mode")
    elif [ "$impl" = jsonmachine ]; then
        env_prefix+=(JF_JSON_MACHINE_SRC=/json-machine/src)
    fi
    local mode_args=() ext_args=()
    mapfile -t mode_args < <(mode_flags "$mode")
    mapfile -t ext_args < <(impl_ext "$impl")
    line=$("${env_prefix[@]}" /usr/bin/time -f '%e %U %S %M' -o "$tf" \
        php "${COMMON[@]}" ${ext_args[@]+"${ext_args[@]}"} ${mode_args[@]+"${mode_args[@]}"} "${script[@]}" "$shape" "$fixture" "$out" "$@")
    sha=$(sha256sum "$out" | cut -d' ' -f1)
    read -r wall user sys rss < "$tf"
    echo "RAW kind=$kind workload=$w impl=$impl mode=$mode run=$run warmups=$warmups wall_s=$wall user_s=$user sys_s=$sys maxrss_kb=$rss $line out_sha=${sha:0:16}" | tee -a "$JF_LOG"
    LAST_SHA=$sha
    rm -f "$out"
}

run_workload() { # workload
    local w=$1 shape first_sha="" fixture="/fixtures/$w.ndjson"
    read -r shape _ <<<"$(fixture_args "$w")"
    ensure_fixture "$w" "$fixture"
    local count
    count=$(wc -l < "$fixture")

    local impl mode run
    for impl in ${IMPLS//,/ }; do
        impl_ok "$impl" || continue
        case "$w" in
            oversized-*) [ "$impl" = jsonfuse ] || continue ;;
            *) if [ "$impl" = jsonmachine ] && [ "$count" -gt 100000 ] && [ "$IMPLS" != jsonmachine ]; then
                   echo "SKIP workload=$w impl=jsonmachine reason=userland_tokenizer_is_slow_above_100k_records_rerun_with_--impls_jsonmachine_alone_to_measure" | tee -a "$JF_LOG"
                   continue
               fi ;;
        esac
        for mode in ${MODES//,/ }; do
            # jsonfuse is native: nojit is the same native code as off, so it is not repeated.
            [ "$impl" = jsonfuse ] && [ "$mode" = nojit ] && continue
            local expect=()
            [ "$w" = oversized-line ] && expect=(--expect-reject)
            # Timed runs: fresh process, in-process warm-ups, then one timed pass.
            for run in $(seq 1 "$RUNS"); do
                one_run timed "$w" "$impl" "$mode" "$shape" "$fixture" "$run" "$WARMUPS" ${expect[@]+"${expect[@]}"}
                if [ "$w" != oversized-line ]; then
                    [ -z "$first_sha" ] && first_sha=$LAST_SHA
                    if [ "$LAST_SHA" != "$first_sha" ]; then
                        echo "OUTPUT MISMATCH workload=$w impl=$impl mode=$mode: $LAST_SHA != $first_sha" | tee -a "$JF_LOG" >&2
                        exit 4
                    fi
                fi
            done
            # RSS runs: no warm-ups, so the peak is the job's own.
            for run in $(seq 1 "$RSS_RUNS"); do
                one_run rss "$w" "$impl" "$mode" "$shape" "$fixture" "$run" 0 ${expect[@]+"${expect[@]}"}
            done
        done
    done
    [ -n "$first_sha" ] && echo "EQUIV workload=$w all outputs byte-identical sha=${first_sha:0:16}" | tee -a "$JF_LOG"
    # The bound in the oversized workloads is a safety property, asserted, not a comparison.
    if [ "$w" = oversized-bound ] || [ "$w" = oversized-line ]; then
        check_memory_bound "$w"
    fi
}

check_memory_bound() { # workload
    # SPEC section 6: 12*cap + 256 (parser) + cap + 2 + 64 KiB + 4 (reader) + cap + small (writer), with cap = 1 MiB,
    # plus a fixed allowance for the PHP process itself.
    local w=$1 cap=1048576 bound_kb peak
    bound_kb=$(( (12 * cap + 256 + cap + 65542 + cap + 4096) / 1024 + 80 * 1024 ))
    peak=$(awk -v w="$w" '$1 == "RAW" && $2 == "kind=rss" && $3 == "workload=" w && $4 == "impl=jsonfuse" {
        for (i = 1; i <= NF; i++) { if (split($i, a, "=") == 2 && a[1] == "maxrss_kb" && a[2] + 0 > m) { m = a[2] + 0 } }
    } END { print m + 0 }' "$JF_LOG")
    if [ "$peak" -eq 0 ] || [ "$peak" -gt "$bound_kb" ]; then
        echo "BOUND FAILED workload=$w maxrss_kb=$peak bound_kb=$bound_kb" | tee -a "$JF_LOG" >&2
        exit 7
    fi
    echo "BOUND OK workload=$w maxrss_kb=$peak bound_kb=$bound_kb input_bytes=$(stat -c %s "/fixtures/$w.ndjson")" | tee -a "$JF_LOG"
}

case "$CMD" in
    list) for w in "${WORKLOADS[@]}"; do echo "$w"; done ;;
    fixtures)
        for w in "${WORKLOADS[@]}"; do
            ensure_fixture "$w" "/fixtures/$w.ndjson" &
            while [ "$(jobs -rp | wc -l)" -ge "$(nproc)" ]; do wait -n || { echo "fixture generation failed" >&2; exit 1; }; done
        done
        wait
        for w in "${WORKLOADS[@]}"; do printf '%s  %s\n' "$(cat "/fixtures/$w.ndjson.sha256")" "$w"; done
        ;;
    env) show_env ;;
    equiv) php "${COMMON[@]}" -d extension=jsonfuse -d extension=simdjson -d extension=fastjson "$BENCH/check_equiv.php" ;;
    run)
        # A published number needs publishable=yes here; a failed check stops the run (exit 6).
        if [ "$PROVENANCE" = 1 ]; then
            "$BENCH/provenance.sh" /jsonfuse | tee -a "$JF_LOG"
        else
            echo "PROVENANCE publishable=no reason=no_provenance_option" | tee -a "$JF_LOG"
        fi
        show_env | tee -a "$JF_LOG"
        echo "RUNCONFIG php=$PHP runs=$RUNS warmups=$WARMUPS rss_runs=$RSS_RUNS modes=$MODES impls=$IMPLS seed=$SEED" | tee -a "$JF_LOG"
        for w in "${WORKLOADS[@]}"; do
            run_workload "$w"
        done
        ;;
esac
