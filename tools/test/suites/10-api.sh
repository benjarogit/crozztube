#!/usr/bin/env bash
# API contracts for the source resolver.

suite_api() {
    section "api"

    local video_id status
    video_id=$(db_scalar "SELECT video_id FROM crozztube_import_sources ORDER BY id LIMIT 1;")
    if [[ -z "$video_id" ]]; then
        skip "no import source in database (import a video first)"
        return
    fi

    status=$(api GET "/api/source-resolver/status/$video_id")
    printf '%s\n' "$status" | jq -e '.ok == true' >/dev/null 2>&1 \
        && pass "status returns ok" || fail "status returns ok: $status"
    printf '%s\n' "$status" | jq -e '.source | has("state") and has("fresh") and has("refreshing")' >/dev/null 2>&1 \
        && pass "status has state/fresh/refreshing" || fail "status shape"

    local state
    state=$(printf '%s' "$status" | jq -r '.source.state')
    if [[ "$state" == "fresh" ]]; then
        check_http "manifest returns 200" "/api/source-resolver/manifest/$video_id" "200"
        local manifest
        manifest=$(api GET "/api/source-resolver/manifest/$video_id")
        check_contains "manifest is m3u8" "#EXTM3U" "$manifest"
        local variants
        variants=$(printf '%s' "$manifest" | grep -c '^#EXT-X-STREAM-INF')
        check_ge "manifest has variants" 1 "$variants"
    else
        skip "status is '$state' — manifest checks skipped"
    fi

    local code
    code=$(api_code GET "/api/source-resolver/status/999999")
    check_eq "unknown video returns 404" "404" "$code"
}
