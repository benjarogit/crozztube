#!/usr/bin/env bash
# CrozzTube test harness.
#
# Usage:
#   tools/test/run.sh                 # run every available suite
#   tools/test/run.sh stack api       # run selected suites
#   tools/test/run.sh list            # list suites
#
# Environment:
#   BASE_URL=https://crozztube.local:8443
#   TEST_USER=benny TEST_PASSWORD=...
#   KEEP_TEST_VIDEOS=1                # do not delete harness imports
#   ARTIFACT_DIR=/tmp/crozztube-tests
set -uo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/../.." && pwd)"
# shellcheck source=lib.sh
source "$HERE/lib.sh"

declare -A SUITE_FILES=(
    [stack]="00-stack.sh"
    [api]="10-api.sh"
    [media]="20-media.sh"
    [direct]="45-direct.sh"
    [refresh]="30-refresh.sh"
    [mirror]="40-mirror.sh"
    [jobs]="50-jobs.sh"
    [thumbnails]="55-thumbnails.sh"
    [ui]="60-ui.sh"
)
ORDER=(stack api media direct refresh mirror jobs thumbnails ui)

usage() {
    sed -n '2,15p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

suite_available() {
    local file="$HERE/suites/${SUITE_FILES[$1]:-}"
    [[ -n "${SUITE_FILES[$1]:-}" && -f "$file" ]]
}

args=("$@")
if (( ${#args[@]} == 0 )); then
    args=(all)
fi

for arg in "${args[@]}"; do
    case "$arg" in
        -h|--help) usage; exit 0 ;;
        list)
            printf 'Available suites:\n'
            for name in "${ORDER[@]}"; do
                if suite_available "$name"; then
                    printf '  %-8s %s\n' "$name" "${C_DIM}suites/${SUITE_FILES[$name]}${C_RESET}"
                else
                    printf '  %-8s %s(not implemented yet)%s\n' "$name" "$C_DIM" "$C_RESET"
                fi
            done
            exit 0
            ;;
        all) ;;
        *)
            if [[ -z "${SUITE_FILES[$arg]:-}" ]]; then
                printf 'Unknown suite: %s\n' "$arg" >&2
                exit 2
            fi
            ;;
    esac
done

if [[ " ${args[*]} " == *" all "* ]]; then
    run_list=()
    for name in "${ORDER[@]}"; do
        suite_available "$name" && run_list+=("$name")
    done
else
    run_list=("${args[@]}")
fi

printf '%sCrozzTube test harness%s\n' "$C_BOLD" "$C_RESET"
printf '  base:   %s\n' "$BASE_URL"
printf '  suites: %s\n' "${run_list[*]}"

if ! login; then
    printf '%sLogin failed for %s — aborting.%s\n' "$C_RED" "$TEST_USER" "$C_RESET" >&2
    exit 1
fi
printf '  auth:   ok (token %s...)\n' "${TOKEN:0:8}"

# Pause the live maintenance scheduler for the duration of the run: its
# 5-minute refresh cycle would otherwise race the refresh/jobs/ui suites.
PAUSE_FILE="$ROOT/storage/framework/crozztube-maintenance.pause"
mkdir -p "$(dirname "$PAUSE_FILE")"
: > "$PAUSE_FILE"

cleanup() {
    rm -f "$PAUSE_FILE"
    cleanup_created_videos
}
trap cleanup EXIT

for name in "${run_list[@]}"; do
    # shellcheck source=/dev/null
    source "$HERE/suites/${SUITE_FILES[$name]}"
    fn="suite_$name"
    if ! declare -F "$fn" >/dev/null; then
        fail "suite '$name' does not define $fn()"
        continue
    fi
    "$fn"
done

printf '\n%sSummary%s  %spass %d%s  %sfail %d%s  %sskip %d%s\n' \
    "$C_BOLD" "$C_RESET" "$C_GREEN" "$PASS" "$C_RESET" "$C_RED" "$FAIL" "$C_RESET" "$C_YELLOW" "$SKIP" "$C_RESET"

if (( FAIL > 0 )); then
    printf '%sFailed:%s\n' "$C_RED" "$C_RESET"
    printf '  - %s\n' "${FAILED_NAMES[@]}"
    exit 1
fi
exit 0
