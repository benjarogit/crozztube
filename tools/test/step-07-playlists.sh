#!/usr/bin/env bash
set -euo pipefail

docker exec crozztube-web php tools/test/php/shared-playlist-checks.php
