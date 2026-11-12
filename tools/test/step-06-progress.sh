#!/usr/bin/env bash
set -euo pipefail

docker exec crozztube-web php tools/test/php/watch-progress-checks.php
