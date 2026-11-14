#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
if php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("iconv") ? 0 : 1);' >/dev/null 2>&1; then
    php tools/test/php/series-playlist-checks.php
else
    docker run --rm --network none --entrypoint php \
        -v "$PWD:/var/www/html:ro" -w /var/www/html \
        "${SERIES_TEST_IMAGE:-crozztube-playhub-web:latest}" tools/test/php/series-playlist-checks.php
fi
node --test tools/test/series-seasons.test.mjs
node tools/test/ui/series-seasons.mjs
