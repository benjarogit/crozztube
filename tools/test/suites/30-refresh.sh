#!/usr/bin/env bash
# Auto-refresh contract:
#   - status payload exposes expiry/refresh metadata
#   - hanging `refreshing` states do not lock the UI forever
#   - a forced refresh re-resolves even while the stored source is still fresh
#     (regression test: the job used to bail out on state=fresh)
#   - simulated expiry marks the row stale and can be renewed end-to-end
#
# Set SLOW=0 to skip the yt-dlp round trip.

suite_refresh() {
    section "refresh"

    local video_id slug
    video_id=$(db_scalar "SELECT video_id FROM crozztube_import_sources ORDER BY id LIMIT 1;")
    if [[ -z "$video_id" ]]; then
        skip "no import source in database"
        return
    fi
    slug=$(db_scalar "SELECT slug FROM videos WHERE id=$video_id;")

    local status
    status=$(api GET "/api/source-resolver/status/slug/$slug")
    if ! printf '%s' "$status" | jq -e '.source' >/dev/null 2>&1; then
        fail "status payload missing"
        return
    fi

    # ---------------------------------------------------------------- contract
    printf '%s' "$status" | jq -e '
        (.source | has("expires_at"))
        and (.source | has("expires_in"))
        and (.source | has("can_refresh"))
        and (.source | has("retry_after"))
        and (.source | has("fresh"))
    ' >/dev/null 2>&1 && pass "status exposes refresh metadata" \
        || fail "status is missing refresh metadata"

    check_eq "fresh source can_refresh=false" "false" \
        "$(printf '%s' "$status" | jq -r '.source.can_refresh')"

    # --------------------------------------------------- hanging refresh state
    local backup
    backup=$(db_scalar "SELECT CONCAT(state, '|', COALESCE(refresh_started_at, '')) FROM crozztube_import_sources WHERE video_id=$video_id;")
    db "UPDATE crozztube_import_sources SET state='refreshing', refresh_started_at='2000-01-01 00:00:00' WHERE video_id=$video_id;" >/dev/null

    local hanging
    hanging=$(api GET "/api/source-resolver/status/$video_id")
    check_eq "hanging refresh is not reported as running" "false" \
        "$(printf '%s' "$hanging" | jq -r '.source.refreshing')"
    check_ge "hanging refresh still exposes direct source" "1" \
        "$(printf '%s' "$hanging" | jq -r 'if .source.has_direct then 1 else 0 end')"

    # Restore the row exactly as it was.
    local old_state="${backup%%|*}" old_started="${backup#*|}"
    if [[ -n "$old_started" ]]; then
        db "UPDATE crozztube_import_sources SET state='$old_state', refresh_started_at='$old_started' WHERE video_id=$video_id;" >/dev/null
    else
        db "UPDATE crozztube_import_sources SET state='$old_state', refresh_started_at=NULL WHERE video_id=$video_id;" >/dev/null
    fi

    if [[ "${SLOW:-1}" == "0" ]]; then
        skip "forced refresh round trip (SLOW=0)"
        return
    fi

    # -------------------------------------------------- forced refresh (fresh!)
    local before_resolved
    before_resolved=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")

    local refresh_response
    refresh_response=$(api POST "/api/source-resolver/refresh/$video_id")
    check_eq "refresh request accepted" "true" \
        "$(printf '%s' "$refresh_response" | jq -r '.ok')"

    local refreshed=0
    local waited=0
    while (( waited < 180 )); do
        local state resolved
        state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;")
        resolved=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
        if [[ "$state" == "fresh" && "$resolved" > "$before_resolved" ]]; then
            refreshed=1
            break
        fi
        if [[ "$state" == "error" ]]; then
            fail "forced refresh ended in error: $(db_scalar "SELECT COALESCE(error_message,'') FROM crozztube_import_sources WHERE video_id=$video_id;")"
            return
        fi
        sleep 3
        waited=$((waited + 3))
    done

    if (( refreshed == 1 )); then
        pass "forced refresh re-resolved a fresh source"
    else
        fail "forced refresh did not finish within 180s (state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;"))"
        return
    fi

    # --------------------------------------------------------- expiry handling
    # Simulate the debounce window having passed (the test runs faster than a
    # real visitor would retry).
    docker exec crozztube-web php artisan tinker --execute="
        Illuminate\\Support\\Facades\\Cache::forget('source-resolver:req.$video_id');
        Illuminate\\Support\\Facades\\Cache::forget('source-resolver:video.$video_id');
    " >/dev/null 2>&1 || true

    local resolved_before_expiry
    resolved_before_expiry=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
    db "UPDATE crozztube_import_sources SET expires_at='2000-01-01 00:00:00', state='fresh' WHERE video_id=$video_id;" >/dev/null

    local expired
    expired=$(api GET "/api/source-resolver/status/$video_id")
    check_eq "expired source is not fresh" "false" "$(printf '%s' "$expired" | jq -r '.source.fresh')"
    check_eq "expired source requests refresh" "true" "$(printf '%s' "$expired" | jq -r '.source.can_refresh')"

    api POST "/api/source-resolver/refresh/$video_id" >/dev/null

    local renewed=0
    waited=0
    while (( waited < 180 )); do
        local fresh
        fresh=$(api GET "/api/source-resolver/status/$video_id" | jq -r '.source.fresh')
        if [[ "$fresh" == "true" ]]; then
            renewed=1
            break
        fi
        sleep 3
        waited=$((waited + 3))
    done
    (( renewed == 1 )) && pass "expired source renews automatically" \
        || fail "expired source did not renew within 180s"

    local after_resolved
    after_resolved=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
    [[ "$after_resolved" > "$resolved_before_expiry" ]] && pass "renewal updated resolved_at" \
        || fail "renewal did not update resolved_at"

    # ------------------------------------------------- multi-user single-flight
    # Several visitors hitting the same expired source must cause exactly ONE
    # yt-dlp run, even though every request is an explicit (forced) refresh.
    docker exec crozztube-web php artisan tinker --execute="
        Illuminate\\Support\\Facades\\Cache::forget('source-resolver:req.$video_id');
        Illuminate\\Support\\Facades\\Cache::forget('source-resolver:video.$video_id');
    " >/dev/null 2>&1 || true

    local attempts_before resolved_before_burst
    attempts_before=$(db_scalar "SELECT refresh_attempts FROM crozztube_import_sources WHERE video_id=$video_id;")
    resolved_before_burst=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
    db "UPDATE crozztube_import_sources SET expires_at='2000-01-01 00:00:00', state='fresh' WHERE video_id=$video_id;" >/dev/null

    local i
    for i in 1 2 3 4 5; do
        "${CURL[@]}" -X POST "$BASE_URL/api/source-resolver/refresh/$video_id" \
            -H 'Accept: application/json' >/dev/null 2>&1 &
    done
    wait

    local burst_done=0
    waited=0
    while (( waited < 180 )); do
        local state resolved_now
        state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;")
        resolved_now=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
        if [[ "$state" == "fresh" && "$resolved_now" > "$resolved_before_burst" ]]; then
            burst_done=1
            break
        fi
        sleep 3
        waited=$((waited + 3))
    done

    if (( burst_done == 0 )); then
        fail "concurrent refresh did not renew within 180s"
        return
    fi

    # Let any queued duplicate jobs run and exit before counting attempts.
    sleep 8
    local attempts_after
    attempts_after=$(db_scalar "SELECT refresh_attempts FROM crozztube_import_sources WHERE video_id=$video_id;")
    local delta=$(( attempts_after - attempts_before ))
    check_eq "five concurrent refreshes cause exactly one resolve" "1" "$delta"
    check_eq "concurrent burst leaves the source fresh" "1" \
        "$(db_scalar "SELECT COUNT(*) FROM crozztube_import_sources WHERE video_id=$video_id AND state='fresh' AND expires_at > resolved_at;")"
}
