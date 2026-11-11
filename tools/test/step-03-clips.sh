#!/usr/bin/env bash
set -euo pipefail

readonly retained_clip_id="5e06399e-041d-4f31-a198-a6dcd4e7af8f"

node --test --test-reporter=spec tools/test/clip-encoder.test.mjs
node tools/test/clip-browser-format.mjs
php tools/test/php/clip-api-checks.php
docker exec crozztube-web php tools/test/php/clip-collection-checks.php
php tools/test/php/clip-publisher-checks.php
php tools/test/php/clip-gateway-checks.php
node tools/test/clip-transfer-queue.mjs
node tools/test/clip-confirmation.mjs
node tools/test/ui/clip-composer.mjs
node tools/test/ui/clip-management.mjs
node tools/test/ui/clip-confirmation.mjs
node tools/test/ui/clip-workflow-live-observe.mjs --run "--id=${retained_clip_id}"
