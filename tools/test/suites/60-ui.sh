#!/usr/bin/env bash
# Browser-level tests for the watch enhancer (mirror toggle, overlays, banners).
# Uses Playwright against the running stack; the assertions live in
# tools/test/ui/specs/*.spec.ts.

suite_ui() {
    section "ui"

    local dir="$ROOT/tools/test/ui"
    if [[ ! -d "$dir/node_modules/@playwright/test" ]]; then
        skip "playwright deps missing (cd tools/test/ui && npm install)"
        return
    fi
    if ! command -v npx >/dev/null 2>&1; then
        skip "npx missing"
        return
    fi
    if [[ "${UI_SKIP:-0}" == "1" ]]; then
        skip "UI_SKIP=1"
        return
    fi

    mkdir -p "$ARTIFACT_DIR"
    local log="$ARTIFACT_DIR/ui-playwright.log"

    ( cd "$dir" && BASE_URL="$BASE_URL" ARTIFACT_DIR="$ARTIFACT_DIR" \
        TEST_USER="$TEST_USER" TEST_PASSWORD="$TEST_PASSWORD" \
        npx playwright test mirror.spec.ts refresh.spec.ts thumbnails-shorts.spec.ts --project=chromium --reporter=list ) >"$log" 2>&1
    local code=$?

    local passed failed
    passed=$(grep -Eo '[0-9]+ passed' "$log" | tail -1 | awk '{print $1}')
    failed=$(grep -Eo '[0-9]+ failed' "$log" | tail -1 | awk '{print $1}')
    passed=${passed:-0}
    failed=${failed:-0}

    if (( code == 0 )); then
        pass "playwright browser suite ($passed tests)"
    elif grep -q 'Executable doesn.t exist' "$log"; then
        skip "chromium not installed (cd tools/test/ui && npx playwright install chromium)"
    else
        fail "playwright browser suite ($failed failed, log: $log)"
        if [[ -n "${CRZZ_VERBOSE:-}" ]]; then
            tail -40 "$log"
        fi
    fi
}
