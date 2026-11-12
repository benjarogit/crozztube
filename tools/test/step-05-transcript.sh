#!/usr/bin/env bash
set -euo pipefail

node --test --test-reporter=spec tools/test/transcript-document.test.mjs
docker exec crozztube-web php tools/test/php/transcript-checks.php
