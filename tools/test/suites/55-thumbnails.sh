#!/usr/bin/env bash

suite_thumbnails() {
    section "thumbnails"
    local output code
    output=$(docker exec crozztube-web php tools/test/php/thumbnail-checks.php 2>&1)
    code=$?
    if (( code == 0 )); then
        pass "$(printf '%s\n' "$output" | tail -1)"
    else
        fail "thumbnail integration checks"
        printf '%s\n' "$output"
    fi
    local slug http
    slug=$(db_scalar "SELECT slug FROM videos ORDER BY id LIMIT 1;")
    http=$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' -X PUT "$BASE_URL/api/videos/$slug/thumbnail" -H 'Accept: application/json' -H 'Content-Type: image/png' --data-binary 'invalid')
    check_eq "thumbnail editing requires authentication" "401" "$http"
    check_eq "streaming instance rejects video upload sessions" "403" "$(api_code POST /api/upload-sessions)"
    http=$(api PUT "/api/videos/$slug/thumbnail" '{"invalid":true}' | jq -r '.message // ""')
    check_contains "thumbnail endpoint requires a raw image body" "directly" "$http"
    http=$(curl -sk --max-time 10 -o /dev/null -w '%{http_code}' -X PUT "$BASE_URL/api/videos/$slug/thumbnail" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: image/png' --data-binary 'invalid')
    check_eq "thumbnail endpoint validates actual image bytes" "422" "$http"
    http=$(python3 -c 'import sys; sys.stdout.buffer.write(b"x" * (5242880 + 1))' | curl -sk --max-time 15 -o /dev/null -w '%{http_code}' -X PUT "$BASE_URL/api/videos/$slug/thumbnail" -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' -H 'Content-Type: image/png' --data-binary @-)
    check_eq "thumbnail request size is capped before processing" "413" "$http"
}
