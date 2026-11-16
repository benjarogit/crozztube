#!/usr/bin/env bash
set -euo pipefail

docker exec crozztube-web php artisan migrate --force
docker exec crozztube-web php tools/test/php/series-checks.php
node --test tools/test/series-playback.test.mjs
# Renders a controlled series payload on the real watch page: chip, episode
# context, one autoplay switch and no overflow. Read-only, one worker, no media.
node tools/test/ui/series-ui-check.mjs
