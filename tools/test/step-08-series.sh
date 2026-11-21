#!/usr/bin/env bash
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/../.."

# The same checks run against the local compose stack (default) and on a bare
# GitHub runner. Default PHP execution: `docker exec crozztube-web`. Set
# CROZZTUBE_PHP_CONTAINER= (empty) to run PHP in the current shell with the
# application database from DB_* / .env.
PHP_CONTAINER="${CROZZTUBE_PHP_CONTAINER-crozztube-web}"
php_run() {
    if [[ -n "$PHP_CONTAINER" ]]; then
        docker exec "$PHP_CONTAINER" "$@"
    else
        "$@"
    fi
}

php_run php artisan migrate --force
php_run php tools/test/php/series-checks.php
# Two PHP processes, one playlist row lock: the second editor must wait and
# then write one complete order. Uses a temporary fixture only.
php_run php tools/test/php/series-concurrency-checks.php
node --test tools/test/series-playback.test.mjs

app_pid=''
router_file=''
fixture_file=''
cleanup() {
    [[ -n "$app_pid" ]] && kill "$app_pid" 2>/dev/null || true
    [[ -n "$router_file" ]] && rm -f "$router_file" || true
    [[ -n "$fixture_file" ]] && rm -f "$fixture_file" || true
}
trap cleanup EXIT

# The compose stack already serves the application at BASE_URL (or the fixture
# default). A bare runner starts the same root entry point here, because this
# deployment serves the repository root instead of an empty public directory.
if [[ -z "$PHP_CONTAINER" && -z "${BASE_URL:-}" ]]; then
    port="${CROZZTUBE_APP_PORT:-8010}"
    app_log="${TMPDIR:-/tmp}/crozztube-series-app.log"
    router_file="$(mktemp)"
    # A fresh checkout has no runtime directories; Laravel reports "Please
    # provide a valid cache path." for HTTP requests until they exist.
    mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs
    cat >"$router_file" <<'PHP'
<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
if (! str_contains($path, '..') && ! preg_match('#/\\.#', $path) && $path !== '/' && is_file(getcwd().$path)) {
    return false;
}
require getcwd().'/index.php';
PHP
    php -S "127.0.0.1:$port" -t . "$router_file" >"$app_log" 2>&1 &
    app_pid=$!
    export BASE_URL="http://127.0.0.1:$port"
    ready=''
    for _ in $(seq 1 60); do
        if curl --fail --silent --show-error --max-time 2 "$BASE_URL/up" >/dev/null 2>&1; then
            ready='1'
            break
        fi
        sleep 1
    done
    if [[ -z "$ready" ]]; then
        echo "The application did not answer at $BASE_URL/up." >&2
        cat "$app_log" >&2 || true
        exit 1
    fi
fi

# A disposable CI database has no catalogue yet. This creates the one public
# video the existing browser check reads; the compose stack keeps its data.
if [[ "${CROZZTUBE_UI_FIXTURE:-0}" == '1' ]]; then
    if [[ -n "$PHP_CONTAINER" ]]; then
        echo 'CROZZTUBE_UI_FIXTURE=1 requires CROZZTUBE_PHP_CONTAINER= (local PHP).' >&2
        exit 1
    fi
    fixture_file="$(mktemp)"
    cat >"$fixture_file" <<'PHP'
<?php

require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Channel;
use App\Models\User;
use App\Models\Video;

$owner = User::query()->firstOrCreate(
    ['email' => 'series-ui-fixture@example.test'],
    [
        'name' => 'Series UI fixture',
        'username' => 'series-ui-fixture',
        'password' => bin2hex(random_bytes(16)),
    ],
);
$channel = Channel::query()->firstOrCreate(
    ['slug' => 'series-ui-fixture-channel'],
    ['user_id' => $owner->id, 'name' => 'Series UI fixture'],
);
Video::query()->firstOrCreate(
    ['slug' => 'series-ui-fixture-video'],
    [
        'user_id' => $owner->id,
        'channel_id' => $channel->id,
        'title' => 'Series UI fixture video',
        'video_path' => 'videos/series-ui-fixture.mp4',
        'status' => 'ready',
        'visibility' => 'public',
    ],
);
echo "Series UI fixture video ready.\n";
PHP
    php "$fixture_file"
fi

# Renders a controlled series payload on the real watch page: chip, episode
# context, one autoplay switch and no overflow. Read-only, one worker, no media.
node tools/test/ui/series-ui-check.mjs
