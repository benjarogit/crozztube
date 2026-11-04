#!/usr/bin/env bash
# Direct-URL MP4 import (progressive file, no HLS, no provider embed).
#
# The sample file is a real MP4 hosted by MDN; override with DIRECT_MP4_URL or
# set DIRECT_MP4_URL=none to skip. The import is removed afterwards unless
# KEEP_TEST_VIDEOS=1.

suite_direct() {
    section "direct"

    local url="${DIRECT_MP4_URL:-https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4}"
    if [[ "$url" == "none" ]]; then
        skip "direct MP4 test disabled (DIRECT_MP4_URL=none)"
        return
    fi

    local category_id
    category_id=$(db_scalar "SELECT id FROM video_categories ORDER BY id LIMIT 1;")
    if [[ -z "$category_id" ]]; then
        skip "no video category available"
        return
    fi

    local title="CrzHarness Direct MP4 $(date +%s)"
    local body
    body=$(jq -n --arg url "$url" --arg title "$title" --argjson category "$category_id" \
        '{provider:"direct", url:$url, title:$title, category_id:$category}')

    local created video_id slug
    created=$(api POST "/api/video-imports" "$body")
    video_id=$(printf '%s' "$created" | jq -r '.video.id // empty')
    slug=$(printf '%s' "$created" | jq -r '.video.slug // empty')

    if [[ -z "$video_id" || -z "$slug" ]]; then
        fail "direct MP4 import rejected: $(printf '%s' "$created" | head -c 300)"
        return
    fi
    register_created_video "$video_id"
    pass "direct MP4 import accepted"

    local waited=0 state=""
    while (( waited < 120 )); do
        state=$(db_scalar "SELECT state FROM crozztube_import_sources WHERE video_id=$video_id;")
        [[ "$state" == "fresh" || "$state" == "error" ]] && break
        sleep 2
        waited=$((waited + 2))
    done

    if [[ "$state" != "fresh" ]]; then
        fail "direct MP4 resolve did not finish (state=${state:-none}, error=$(db_scalar "SELECT COALESCE(error_message,'') FROM crozztube_import_sources WHERE video_id=$video_id;"))"
        return
    fi
    pass "direct MP4 resolves without HLS"

    check_eq "direct source kind is mp4" "mp4" \
        "$(db_scalar "SELECT kind FROM crozztube_import_sources WHERE video_id=$video_id;")"

    local status
    status=$(api GET "/api/source-resolver/status/slug/$slug")
    check_eq "direct source is fresh" "true" "$(printf '%s' "$status" | jq -r '.source.fresh')"
    check_eq "direct source has_direct" "true" "$(printf '%s' "$status" | jq -r '.source.has_direct')"
    check_eq "direct source is downloadable" "true" "$(printf '%s' "$status" | jq -r '.source.downloadable')"
    check_eq "direct mirror mode is link" "link" "$(printf '%s' "$status" | jq -r '.source.mirror.mode')"
    check_eq "direct mirror has no embed" "null" "$(printf '%s' "$status" | jq -r '.source.mirror.embed_url // "null"')"
    check_eq "direct mirror points at the file" "$url" \
        "$(printf '%s' "$status" | jq -r '.source.mirror.external_url')"

    # The watch payload must hand the player the original file, not an embed.
    local watch source_url source_type
    watch=$(api GET "/api/videos/$slug")
    source_url=$(printf '%s' "$watch" | jq -r '.video.video_sources[0].src // "null"')
    source_type=$(printf '%s' "$watch" | jq -r '.video.video_sources[0].type // "null"')
    check_eq "watch payload uses the MP4 file" "$url" "$source_url"
    check_eq "watch payload MIME is video/mp4" "video/mp4" "$source_type"

    local duration
    duration=$(printf '%s' "$watch" | jq -r '.video.duration_seconds // 0')
    check_ge "direct MP4 duration is probed" "1" "$duration"

    # Range request: the file really serves media bytes, not an HTML interstitial.
    local media_code media_type
    media_code=$("${CURL[@]}" -o /dev/null -w '%{http_code}' -r 0-1023 "$url")
    media_type=$("${CURL[@]}" -o /dev/null -w '%{content_type}' -r 0-1023 "$url")
    [[ "$media_code" == "200" || "$media_code" == "206" ]] \
        && pass "direct MP4 serves media bytes ($media_code)" \
        || fail "direct MP4 range request returned $media_code"
    check_contains "direct MP4 content type" "video/mp4" "$media_type"
}
