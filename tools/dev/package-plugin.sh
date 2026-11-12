#!/usr/bin/env bash
# Build an installable plugin ZIP from the maintained source tree.
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
plugin_id="${1:?Usage: package-plugin.sh PLUGIN_ID [OUTPUT_DIRECTORY]}"
[[ "$plugin_id" =~ ^[a-z][a-z0-9._-]+$ ]] || { echo 'Invalid plugin ID.' >&2; exit 1; }
plugin_source="$project_root/docker/plugin-src/$plugin_id"
plugin_version="$(jq -er .version "$plugin_source/plugin.json")"
package_directory="${2:-/tmp/crozztube-plugins}"
mkdir -p "$package_directory"
package_directory="$(cd "$package_directory" && pwd)"
package_temp="$(mktemp -d)"
trap 'rm -rf -- "$package_temp"' EXIT

(cd "$plugin_source" && zip -qr "$package_temp/plugin.zip" . -x '*.DS_Store')
package_path="$package_directory/$plugin_id-$plugin_version.zip"
mv "$package_temp/plugin.zip" "$package_path"
printf '%s\n' "$package_path"
