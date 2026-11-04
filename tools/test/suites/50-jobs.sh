#!/usr/bin/env bash
# Maintenance jobs: staggered expiry refresh + URL probe, scheduler wiring,
# and the maintenance queue.
#
# Set SLOW=0 to skip the yt-dlp round trip.

suite_jobs() {
    section "jobs"

    # --------------------------------------------------------------- commands
    local commands
    commands=$(docker exec crozztube-web php artisan list 2>/dev/null)
    check_contains "refresh-expiring command registered" "crozztube:sources:refresh-expiring" "$commands"
    check_contains "probe command registered" "crozztube:sources:probe" "$commands"

    local schedule
    schedule=$(docker exec crozztube-web php artisan schedule:list 2>/dev/null)
    check_contains "refresh-expiring is scheduled" "crozztube:sources:refresh-expiring" "$schedule"
    check_contains "probe is scheduled" "crozztube:sources:probe" "$schedule"

    # ------------------------------------------------------------ probe happy
    local probe_out
    probe_out=$(docker exec crozztube-web php artisan crozztube:sources:probe --dry-run --limit=3 --min-age=0 2>&1)
    check_contains "probe reports ok for stored sources" "status=ok" "$probe_out"
    check_contains "probe summary printed" "Probed" "$probe_out"

    local checked
    checked=$(db_scalar "SELECT COUNT(*) FROM crozztube_import_sources WHERE last_checked_at IS NOT NULL;")
    check_ge "probe stores last_checked_at" "1" "$checked"

    local oks
    oks=$(db_scalar "SELECT COUNT(*) FROM crozztube_import_sources WHERE last_check_status='ok';")
    check_ge "probe stores last_check_status" "1" "$oks"

    # --------------------------------------------------------- probe detection
    db "INSERT INTO crozztube_import_sources (video_id, media_url, kind, state, refresh_attempts, created_at, updated_at)
        VALUES (999999901, 'https://example.invalid/does-not-exist.mp4', 'mp4', 'fresh', 0, NOW(), NOW());" >/dev/null
    docker exec crozztube-web php artisan crozztube:sources:probe --dry-run --limit=200 --min-age=0 >/dev/null 2>&1
    local broken_status
    broken_status=$(db_scalar "SELECT COALESCE(last_check_status, 'null') FROM crozztube_import_sources WHERE video_id=999999901;")
    check_ne "probe detects a dead URL" "ok" "$broken_status"
    check_ne "probe detects a dead URL (not null)" "null" "$broken_status"
    db "DELETE FROM crozztube_import_sources WHERE video_id=999999901;" >/dev/null

    # ------------------------------------------------------- expiring refresh
    local video_id
    video_id=$(db_scalar "SELECT video_id FROM crozztube_import_sources WHERE kind IN ('hls','mp4','hls-synth') ORDER BY id DESC LIMIT 1;")
    if [[ -z "$video_id" ]]; then
        skip "no resolvable import source"
        return
    fi

    docker exec crozztube-web php artisan tinker --execute="
        CrzSourceResolver\Models\CrozztubeImportSource::where('video_id', $video_id)
            ->update(['expires_at' => now()->addMinutes(5), 'state' => 'fresh']);
        Illuminate\Support\Facades\Cache::forget('source-resolver:req.$video_id');
        Illuminate\Support\Facades\Cache::forget('source-resolver:video.$video_id');
    " >/dev/null 2>&1

    local dry
    dry=$(docker exec crozztube-web php artisan crozztube:sources:refresh-expiring --window=15 --limit=50 --dry-run 2>&1)
    check_contains "expiring source is selected" "video=$video_id" "$dry"

    if [[ "${SLOW:-1}" == "0" ]]; then
        skip "expiring refresh round trip (SLOW=0)"
        return
    fi

    local before_resolved
    before_resolved=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")

    # Clear the request debounce: the suite runs faster than a real visitor.
    docker exec crozztube-web php artisan tinker --execute="
        Illuminate\Support\Facades\Cache::forget('source-resolver:req.$video_id');
        Illuminate\Support\Facades\Cache::forget('source-resolver:video.$video_id');
    " >/dev/null 2>&1

    local run
    run=$(docker exec crozztube-web php artisan crozztube:sources:refresh-expiring --window=15 --limit=1 --stagger=0 2>&1)
    # The scheduler may have queued the very same source a moment earlier, in
    # which case the request debounce reports "0 queued" - the source still
    # gets renewed, so only the command execution is asserted here.
    if [[ "$run" =~ [0-9]+\ refresh\ job\(s\)\ queued ]]; then
        pass "refresh-expiring ran on the selected source"
    else
        printf '  %scommand output:%s %s\n' "$C_DIM" "$C_RESET" "$(printf '%s' "$run" | tr '\n' ' ')"
        fail "refresh-expiring output unexpected"
    fi

    local done=0
    local waited=0
    while (( waited < 180 )); do
        local state resolved
        state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;")
        resolved=$(db_scalar "SELECT COALESCE(resolved_at, '1970-01-01') FROM crozztube_import_sources WHERE video_id=$video_id;")
        if [[ "$state" == "fresh" && "$resolved" > "$before_resolved" ]]; then
            done=1
            break
        fi
        sleep 3
        waited=$((waited + 3))
    done

    (( done == 1 )) && pass "maintenance queue refresh resolved the source" \
        || fail "maintenance queue refresh did not finish (state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;"))"
}
