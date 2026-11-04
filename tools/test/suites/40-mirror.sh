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

    # Provider-independent payload shape.
    printf '%s' "$status" | jq -e '.source.mirror | has("label") and has("external_url")' >/dev/null 2>&1 \
        && pass "mirror has label/external_url" || fail "mirror payload shape"

    # Every embeddable provider must resolve without provider-specific frontend code.
    local out
    out=$(docker exec crozztube-web php -r '
        require "vendor/autoload.php";
        require "bootstrap/app.php";
        $video = App\Models\Video::query()->where("id", (int) $argv[1])->first();
        $host = "crozztube.local";
        $p = App\Support\MediaSourceMetadata::originProvider($video);
        $id = App\Support\MediaSourceMetadata::providerIdForVideo($video, $p);
        echo json_encode(["provider" => $p, "id" => $id, "url" => App\Support\MediaSourceMetadata::embedUrl($p, $id, $host)]);
    ' "$video_id" 2>/dev/null)
    check_eq "metadata provider" "youtube" "$(printf '%s' "$out" | jq -r '.provider // "null"')"
    check_contains "metadata embed URL" "/embed/" "$(printf '%s' "$out" | jq -r '.url // "null"')"
}
