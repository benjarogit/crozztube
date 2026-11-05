#!/usr/bin/env bash

suite_images() {
    section "external image attachments"
    local output code file http uuid
    for file in image-upload-checks.php image-upload-edge-checks.php image-attachment-api-checks.php; do
        output=$(docker exec crozztube-web php "tools/test/php/$file" 2>&1)
        code=$?
        if (( code == 0 )) && [[ "$file" == "image-upload-checks.php" ]]; then
            local runtime
            runtime=$(BASE_URL="$BASE_URL" python3 "$ROOT/tools/test/image-web-runtime.py" 2>&1)
            code=$?
            if (( code == 0 )); then
                output="$(printf '%s\n' "$output" | tail -1) + $runtime"
            else
                output="$output"$'\n'"$runtime"
            fi
        fi
        if (( code == 0 )); then
            pass "$(printf '%s\n' "$output" | tail -1)"
        else
            fail "$file"
            printf '%s\n' "$output"
        fi
    done
    uuid=00000000-0000-4000-8000-000000000056
    for prefix in /api /api/v1; do
        http=$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' -X PUT \
            "$BASE_URL$prefix/image-uploads/$uuid?purpose=video_comment&target_id=1" \
            -H 'Accept: application/json' -H 'Content-Type: image/png' --data-binary 'invalid')
        check_eq "$prefix raw image uploads require authentication" "401" "$http"
        http=$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' \
            "$BASE_URL$prefix/image-uploads/$uuid" -H 'Accept: application/json')
        check_eq "$prefix image status requires authentication" "401" "$http"
        http=$(python3 -c 'import sys; sys.stdout.buffer.write(b"x" * (8388608 + 1))' \
            | curl -sk --max-time 15 -o /dev/null -w '%{http_code}' -X PUT \
                "$BASE_URL$prefix/image-uploads/$uuid?purpose=video_comment&target_id=1" \
                -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: image/png' --data-binary @-)
        if [[ "$prefix" == "/api" ]]; then
            check_eq "$prefix image transport rejects bodies beyond eight MiB" "413" "$http"
        else
            # This harness logs in through the web API. Native device-session
            # authentication must reject its token before processing image data.
            # Authenticated native body limits run with isolated device fixtures.
            check_eq "native uploads reject web-only sessions before processing bytes" "401" "$http"
        fi
    done
    output=$(docker exec crozztube-web php -r 'echo ini_get("sys_temp_dir");')
    check_eq "PHP request body spill stays on RAM-backed tmpfs" "/dev/shm" "$output"
    local dir="$ROOT/tools/test/ui" log="$ARTIFACT_DIR/image-attachments-playwright.log"
    if [[ "${UI_SKIP:-0}" == "1" ]]; then
        skip "UI_SKIP=1 (image attachment browsers)"
        return
    fi
    if [[ ! -d "$dir/node_modules/@playwright/test" ]] || ! command -v npx >/dev/null 2>&1; then
        fail "image attachment browser prerequisites missing"
        return
    fi
    mkdir -p "$ARTIFACT_DIR"
    ( cd "$dir" && BASE_URL="$BASE_URL" ARTIFACT_DIR="$ARTIFACT_DIR" \
        npx playwright test image-attachments.spec.ts --project=chromium --reporter=list ) >"$log" 2>&1
    code=$?
    if (( code == 0 )); then
        pass "image attachment browser checks ($(grep -Eo '[0-9]+ passed' "$log" | tail -1))"
    else
        fail "image attachment browsers (log: $log)"
        if [[ -n "${CRZZ_VERBOSE:-}" ]]; then tail -40 "$log"; fi
    fi
}
