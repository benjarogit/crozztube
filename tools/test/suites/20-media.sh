#!/usr/bin/env bash
# Media path: manifest -> variant playlist -> init segment -> media segment.
# Verifies the proxy rewrites and streams real bytes.

suite_media() {
    section "media"

    local video_id
    video_id=$(db_scalar "SELECT video_id FROM crozztube_import_sources WHERE state='fresh' ORDER BY id LIMIT 1;")
    if [[ -z "$video_id" ]]; then
        skip "no fresh import source"
        return
    fi

    local manifest
    manifest=$(api GET "/api/source-resolver/manifest/$video_id")
    if [[ "$manifest" != *"#EXTM3U"* ]]; then
        fail "manifest missing for video $video_id"
        return
    fi

    local variant_url
    variant_url=$(printf '%s\n' "$manifest" | awk '/^#EXT-X-STREAM-INF/{getline; print; exit}')
    if [[ -z "$variant_url" ]]; then
        fail "manifest has no variant URL"
        return
    fi
    check_contains "variant URL uses proxy" "$BASE_URL/api/source-resolver/playlist?url=" "$variant_url"

    local playlist http
    http=$(curl -sk -o /tmp/crozztube-tests-variant.m3u8 -w '%{http_code}' --max-time 30 "$variant_url")
    playlist=$(cat /tmp/crozztube-tests-variant.m3u8 2>/dev/null)
    check_eq "variant playlist HTTP 200" "200" "$http"
    check_contains "variant playlist has segments" "#EXTINF" "$playlist"

    # Absolute URLs are fine as long as they point at our own proxy, not upstream.
    if printf '%s\n' "$playlist" | grep '^https\?://' | grep -qv "^$BASE_URL/api/source-resolver/"; then
        fail "variant playlist contains non-proxy absolute URLs"
    else
        pass "variant playlist fully rewritten to proxy"
    fi

    local init_uri
    init_uri=$(printf '%s\n' "$playlist" | sed -n 's/^#EXT-X-MAP:URI="\([^"]*\)".*/\1/p' | head -1)
    if [[ -n "$init_uri" ]]; then
        http=$(curl -sk -o /tmp/crozztube-tests-init.bin -w '%{http_code}' --max-time 30 "$init_uri")
        check_eq "init segment HTTP 200" "200" "$http"
        local size magic
        size=$(stat -c %s /tmp/crozztube-tests-init.bin 2>/dev/null || echo 0)
        magic=$(xxd -p -l 8 /tmp/crozztube-tests-init.bin 2>/dev/null)
        check_ge "init segment has bytes" 100 "$size"
        if [[ "$magic" == *"66747970"* || "$magic" == 4700* || "$magic" == 4740* ]]; then
            pass "init segment looks like MP4/TS"
        else
            fail "init segment magic unexpected: $magic"
        fi
    else
        skip "no EXT-X-MAP in variant playlist"
    fi

    local seg_url
    seg_url=$(printf '%s\n' "$playlist" | grep -v '^#' | head -1)
    http=$(curl -sk -o /tmp/crozztube-tests-seg.bin -w '%{http_code}' --max-time 60 "$seg_url")
    local seg_size
    seg_size=$(stat -c %s /tmp/crozztube-tests-seg.bin 2>/dev/null || echo 0)
    check_eq "media segment HTTP 200" "200" "$http"
    check_ge "media segment size" 10000 "$seg_size"

    local audio_uri
    audio_uri=$(printf '%s\n' "$manifest" | sed -n 's/.*#EXT-X-MEDIA:TYPE=AUDIO.*URI="\([^"]*\)".*/\1/p' | head -1)
    if [[ -n "$audio_uri" ]]; then
        http=$(curl -sk -o /tmp/crozztube-tests-audio.m3u8 -w '%{http_code}' --max-time 30 "$audio_uri")
        check_eq "audio playlist HTTP 200" "200" "$http"
        check_contains "audio playlist has segments" "#EXTINF" "$(cat /tmp/crozztube-tests-audio.m3u8 2>/dev/null)"
    else
        skip "no separate audio rendition"
    fi

    rm -f /tmp/crozztube-tests-*.m3u8 /tmp/crozztube-tests-*.bin
}
