#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
if [[ "${1:-}" == "--mysql" && "$#" == 1 ]]; then
    # A fresh container namespace only: no host ports, volumes, application env,
    # production credentials or connection to the running CrozzTube database.
    nonce="$(openssl rand -hex 6)"
    marker="$(openssl rand -hex 16)"
    database="crozztube_series_test_${nonce}"
    mysql_container="crozztube-series-test-${nonce}"
    cleanup() { docker rm -f "$mysql_container" >/dev/null 2>&1 || true; }
    trap cleanup EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
    docker image inspect mysql:8.0.46 >/dev/null
    docker run --detach --name "$mysql_container" --network none --memory 768m --pids-limit 256 \
        --tmpfs /var/lib/mysql:rw,size=512m \
        -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% -e "MYSQL_DATABASE=$database" \
        mysql:8.0.46 --performance-schema=ON --innodb-buffer-pool-size=64M >/dev/null
    ready=false
    for ((attempt=0; attempt<60; attempt++)); do
        if docker exec "$mysql_container" mysql -uroot --protocol=TCP -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1; then ready=true; break; fi
        sleep 1
    done
    if [[ "$ready" != true ]]; then printf '%s\n' 'Isolated MySQL did not become ready; no checks passed.' >&2; exit 1; fi
    docker exec "$mysql_container" mysql -uroot "$database" -e \
        "CREATE TABLE series_test_guard (marker CHAR(32) NOT NULL); INSERT INTO series_test_guard VALUES ('$marker');"
    docker run --rm --network "container:$mysql_container" --entrypoint php \
        -v "$PWD:/var/www/html:ro" -w /var/www/html \
        -e SERIES_TEST_MYSQL=1 -e "SERIES_TEST_DATABASE=$database" -e "SERIES_TEST_ISOLATION_MARKER=$marker" \
        "${SERIES_TEST_IMAGE:-crozztube-playhub-web:latest}" tools/test/php/series-playlist-checks.php
    exit
fi
if [[ "$#" != 0 ]]; then printf '%s\n' 'Usage: step-08-series.sh [--mysql]' >&2; exit 2; fi
if php -r 'exit(extension_loaded("pdo_sqlite") && extension_loaded("iconv") ? 0 : 1);' >/dev/null 2>&1; then
    php tools/test/php/series-playlist-checks.php
else
    docker run --rm --network none --entrypoint php \
        -v "$PWD:/var/www/html:ro" -w /var/www/html \
        "${SERIES_TEST_IMAGE:-crozztube-playhub-web:latest}" tools/test/php/series-playlist-checks.php
fi
if [[ "${SERIES_TEST_MYSQL_REQUIRED:-0}" == 1 ]]; then bash tools/test/step-08-series.sh --mysql; fi
node --test tools/test/series-seasons.test.mjs
node tools/test/ui/series-seasons.mjs
