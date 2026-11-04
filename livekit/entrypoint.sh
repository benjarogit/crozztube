#!/bin/sh
set -eu
umask 077

config_file="${LIVEKIT_CONFIG_FILE:-/playhub/livekit/runtime/livekit.yaml}"
keys_file="${LIVEKIT_KEYS_FILE:-/playhub/livekit/runtime/keys.yaml}"
trigger_file="${LIVEKIT_RESTART_TRIGGER:-/playhub/livekit/runtime/restart.trigger}"
log_file="${LIVEKIT_LOG_FILE:-/playhub/livekit/logs/livekit.log}"
log_pipe="/tmp/playhub-livekit-log.$$"
server_pid=""
logger_pid=""
private_keys_file="$(mktemp /tmp/playhub-livekit-keys.XXXXXX)"
# Windows bind mounts cannot provide the Unix permission bits LiveKit requires.
# Keep the host file read-only and use a private copy inside the container tmpfs.
cat "$keys_file" > "$private_keys_file"
chmod 600 "$private_keys_file"

mkdir -p "$(dirname "$log_file")"
touch "$log_file"

stop_server() {
  if [ -n "$server_pid" ] && kill -0 "$server_pid" 2>/dev/null; then
    kill -TERM "$server_pid"
    wait "$server_pid" || true
  fi
  server_pid=""
}

cleanup() {
  trap - INT TERM
  stop_server
  rm -f "$log_pipe"
  rm -f "$private_keys_file"
  if [ -n "$logger_pid" ]; then
    wait "$logger_pid" 2>/dev/null || true
  fi
  exit 0
}

trap cleanup INT TERM

last="$(sha256sum "$trigger_file" 2>/dev/null | awk '{print $1}')"
rm -f "$log_pipe"
mkfifo "$log_pipe"
tee -a "$log_file" < "$log_pipe" &
logger_pid=$!

/livekit-server --config "$config_file" --key-file "$private_keys_file" > "$log_pipe" 2>&1 &
server_pid=$!

while kill -0 "$server_pid" 2>/dev/null; do
  sleep 2 &
  wait $! || true
  current="$(sha256sum "$trigger_file" 2>/dev/null | awk '{print $1}')"

  if [ -n "$current" ] && [ "$current" != "$last" ]; then
    stop_server
    rm -f "$log_pipe"
    wait "$logger_pid" 2>/dev/null || true
    rm -f "$private_keys_file"
    exit 75
  fi
done

set +e
wait "$server_pid"
exit_code=$?
set -e
server_pid=""
rm -f "$log_pipe"
wait "$logger_pid" 2>/dev/null || true
rm -f "$private_keys_file"
exit "$exit_code"
