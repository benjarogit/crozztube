#!/usr/bin/env bash
# Layout uses real GET fixtures with rollback; media/playback stays in suite_ui.
suite_responsive() {
    section "responsive"
    local dir="$ROOT/tools/test/ui"
    if [[ "${RESPONSIVE_SKIP:-0}" == "1" ]]; then
        skip "RESPONSIVE_SKIP=1"
        return
    fi
    if [[ ! -d "$dir/node_modules/@playwright/test" ]] || ! command -v npx >/dev/null 2>&1; then
        fail "responsive browser prerequisites missing (cd tools/test/ui && npm ci && npx playwright install chromium firefox webkit)"
        return
    fi
    mkdir -p "$ARTIFACT_DIR"
    local log="$ARTIFACT_DIR/responsive-playwright.log"
    ( cd "$dir" && BASE_URL="$BASE_URL" ARTIFACT_DIR="$ARTIFACT_DIR" \
        node with-responsive-browsers.mjs responsive --reporter=list ) >"$log" 2>&1
    local code=$? passed failed
    passed=$(grep -Eo '[0-9]+ passed' "$log" | tail -1 | awk '{print $1}')
    failed=$(grep -Eo '[0-9]+ failed' "$log" | tail -1 | awk '{print $1}')
    if (( code == 0 )); then
        pass "responsive browsers (${passed:-0} tests; Chromium, Firefox, WebKit)"
    else
        fail "responsive browsers (${failed:-0} failed, log: $log)"
        if [[ -n "${CRZZ_VERBOSE:-}" ]]; then tail -50 "$log"; fi
    fi
}
