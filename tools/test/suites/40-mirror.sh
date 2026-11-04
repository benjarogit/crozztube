#!/usr/bin/env bash
# Mirror contract (provider-agnostic).
# Expects the status payload to expose a `mirror` object.

suite_mirror() {
    section "mirror"

    local video_id
    video_id=$(db_scalar "SELECT video_id FROM crozztube_import_sources ORDER BY id LIMIT 1;")
    if [[ -z "$video_id" ]]; then
        skip "no import source in database"
        return
    fi

    local status
    status=$(api GET "/api/source-resolver/status/$video_id")
    if ! printf '%s' "$status" | jq -e '.source | has("mirror")' >/dev/null 2>&1; then
        fail "status payload has no mirror object"
        return
    fi

    local mode provider embed
    mode=$(printf '%s' "$status" | jq -r '.source.mirror.mode // "null"')
    provider=$(printf '%s' "$status" | jq -r '.source.mirror.provider // "null"')
    embed=$(printf '%s' "$status" | jq -r '.source.mirror.embed_url // "null"')

    check_eq "mirror mode for import" "iframe" "$mode"
    check_eq "mirror provider detected" "youtube" "$provider"
    check_contains "mirror embed URL" "/embed/" "$embed"
    check_contains "mirror uses nocookie" "youtube-nocookie.com" "$embed"

    # Slug lookup: the watch page must need exactly one request.
    local slug
    slug=$(db_scalar "SELECT slug FROM videos WHERE id = $video_id;")
    local by_slug
    by_slug=$(api GET "/api/source-resolver/status/slug/$slug")
    check_eq "status by slug resolves video" "$video_id" "$(printf '%s' "$by_slug" | jq -r '.source.video_id // "null"')"
    check_eq "status by slug mirrors id lookup" "$mode" "$(printf '%s' "$by_slug" | jq -r '.source.mirror.mode // "null"')"
    check_eq "status by unknown slug is 404" "404" "$(api_code GET "/api/source-resolver/status/slug/does-not-exist-crz")"

    # Provider-independent payload shape.
    printf '%s' "$status" | jq -e '.source.mirror | has("label") and has("external_url")' >/dev/null 2>&1 \
        && pass "mirror has label/external_url" || fail "mirror payload shape"

    # Every embeddable provider must resolve without provider-specific frontend code.
    local out
    out=$(docker exec crozztube-web php artisan tinker --execute="
        \$video = App\Models\Video::find($video_id);
        echo json_encode(CrzSourceResolver\Support\Mirror::forVideo(\$video, 'crozztube.local'));
    " 2>/dev/null | tail -1)
    check_eq "Mirror::forVideo provider" "youtube" "$(printf '%s' "$out" | jq -r '.provider // "null"')"
    check_contains "Mirror::forVideo embed URL" "/embed/" "$(printf '%s' "$out" | jq -r '.embed_url // "null"')"

    # Provider matrix: same frontend must work for every supported provider.
    local matrix
    matrix=$(docker exec crozztube-web php artisan tinker --execute='
        $cases = [
            "youtube" => "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
            "youtube-short" => "https://www.youtube.com/shorts/Hrn2aF9K8Ig",
            "vimeo" => "https://vimeo.com/76979871",
            "dailymotion" => "https://www.dailymotion.com/video/x8abcde",
            "twitch-vod" => "https://www.twitch.tv/videos/123456789",
            "direct" => "https://cdn.example.com/clip.mp4",
        ];
        $result = [];
        foreach ($cases as $name => $url) {
            $provider = str_contains($name, "youtube") ? "youtube" : explode("-", $name)[0];
            $video = new App\Models\Video([
                "source_type" => "import",
                "import_provider" => $provider,
                "import_url" => $url,
                "video_path" => "",
            ]);
            $result[$name] = CrzSourceResolver\Support\Mirror::forVideo($video, "crozztube.local");
        }
        echo json_encode($result);
    ' 2>/dev/null | tail -1)

    check_eq "youtube matrix mode" "iframe" "$(printf '%s' "$matrix" | jq -r '.["youtube"].mode')"
    check_contains "youtube matrix embed" "youtube-nocookie.com/embed/dQw4w9WgXcQ" "$(printf '%s' "$matrix" | jq -r '.["youtube"].embed_url')"
    check_eq "short matrix mode" "iframe" "$(printf '%s' "$matrix" | jq -r '.["youtube-short"].mode')"
    check_contains "short matrix embed" "/embed/Hrn2aF9K8Ig" "$(printf '%s' "$matrix" | jq -r '.["youtube-short"].embed_url')"
    check_contains "vimeo matrix embed" "player.vimeo.com/video/76979871" "$(printf '%s' "$matrix" | jq -r '.vimeo.embed_url')"
    check_contains "dailymotion matrix embed" "dailymotion.com/embed/video/x8abcde" "$(printf '%s' "$matrix" | jq -r '.dailymotion.embed_url')"
    check_contains "twitch matrix parent" "parent=crozztube.local" "$(printf '%s' "$matrix" | jq -r '.["twitch-vod"].embed_url')"
    check_eq "direct falls back to link" "link" "$(printf '%s' "$matrix" | jq -r '.direct.mode')"
    check_eq "direct external URL kept" "https://cdn.example.com/clip.mp4" "$(printf '%s' "$matrix" | jq -r '.direct.external_url')"
}
