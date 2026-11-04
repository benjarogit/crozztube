#!/usr/bin/env bash
# Rebuild and reinstall the CrozzTube source-resolver plugin.
# The plugin is installed from a ZIP through the admin API, so source changes
# under docker/plugin-src only take effect after this script ran.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PLUGIN_DIR="$ROOT/docker/plugin-src/crozztube.source-resolver"
PLUGIN_ID="crozztube.source-resolver"
BASE_URL="${BASE_URL:-https://crozztube.local:8443}"
ZIP="${ZIP:-/tmp/opencode/${PLUGIN_ID}.zip}"
LOGIN="${TEST_USER:-benny}"
PASSWORD="${TEST_PASSWORD:-meZVRW0bYEbpHsJc}"

say() { printf '\n== %s ==\n' "$*"; }

say "build ZIP"
rm -f "$ZIP"
( cd "$PLUGIN_DIR" && zip -rq "$ZIP" . -x '*.DS_Store' )
VERSION=$(jq -r .version "$PLUGIN_DIR/plugin.json")
printf '  %s (%s bytes, v%s)\n' "$ZIP" "$(stat -c %s "$ZIP")" "$VERSION"

say "login"
TOKEN=$(curl -sk -X POST "$BASE_URL/api/auth/login" \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "{\"login\":\"$LOGIN\",\"password\":\"$PASSWORD\",\"device_name\":\"reinstall\"}" | jq -r .token)
[[ -n "$TOKEN" && "$TOKEN" != "null" ]] || { echo "login failed" >&2; exit 1; }

api() { # method path [extra curl args...]
  local method="$1" path="$2"; shift 2
  curl -sk -X "$method" "$BASE_URL$path" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$@"
}

say "disable"
api POST "/api/admin/plugins/$PLUGIN_ID/disable" | jq -c '{message}' || true
sleep 1

say "install"
INSTALL=$(api POST /api/admin/plugins -F "package=@$ZIP")
printf '%s\n' "$INSTALL" | jq -c '{message, version: .plugin.version}' 2>/dev/null || printf '%s\n' "$INSTALL"
if printf '%s' "$INSTALL" | grep -q 'Too Many Attempts'; then
  echo "throttled — waiting 62s and retrying" >&2
  sleep 62
  INSTALL=$(api POST /api/admin/plugins -F "package=@$ZIP")
  printf '%s\n' "$INSTALL" | jq -c '{message, version: .plugin.version}' 2>/dev/null || printf '%s\n' "$INSTALL"
fi
printf '%s' "$INSTALL" | jq -e '.plugin.version' >/dev/null 2>&1 || { echo "install failed" >&2; exit 1; }

say "enable"
api POST "/api/admin/plugins/$PLUGIN_ID/enable" | jq -c '{message, version: .plugin.version}'

say "restart worker"
docker exec crozztube-web php artisan queue:restart

say "done"
