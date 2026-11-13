#!/usr/bin/env bash
set -euo pipefail

docker exec crozztube-web php artisan migrate --force --path=database/migrations/2026_11_12_000001_create_video_time_marks.php
docker exec crozztube-web php artisan migrate --force --path=database/migrations/2026_11_13_000001_add_value_to_time_mark_votes.php
docker exec crozztube-web php tools/test/php/time-mark-checks.php
