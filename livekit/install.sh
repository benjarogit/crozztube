#!/bin/sh
set -eu

script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
project_dir="$(dirname "$script_dir")"
php_bin="${PHP_BIN:-php}"
web_user="${1:-$(stat -c '%U' "$project_dir/storage")}"

if [ "$(id -u)" -ne 0 ]; then
  printf '%s\n' 'Run this installer as root so it can access Docker and register the optional manager service.' >&2
  exit 1
fi

command -v docker >/dev/null 2>&1 || { printf '%s\n' 'Docker is not installed.' >&2; exit 1; }
docker compose version >/dev/null 2>&1 || { printf '%s\n' 'Docker Compose v2 is not installed.' >&2; exit 1; }
command -v "$php_bin" >/dev/null 2>&1 || { printf '%s\n' "PHP binary not found: $php_bin" >&2; exit 1; }
php_bin="$(command -v "$php_bin")"
id "$web_user" >/dev/null 2>&1 || { printf '%s\n' "Website user not found: $web_user" >&2; exit 1; }

web_group="$(id -gn "$web_user")"
mkdir -p "$script_dir/runtime/status" "$script_dir/runtime/logs" "$script_dir/runtime/certs" "$project_dir/uploads/live-streams" "$project_dir/storage/app/.config"
chown -R "$web_user:$web_group" "$script_dir/runtime"
chown -R "$web_user:$web_group" "$project_dir/storage/app/.config"
chmod 0750 "$script_dir/runtime"
chmod 1777 "$project_dir/uploads/live-streams"
chmod 0755 "$script_dir/entrypoint.sh" "$script_dir/status-exporter.sh" "$script_dir/install.sh"

if command -v runuser >/dev/null 2>&1; then
  (
    cd "$project_dir"
    runuser -u "$web_user" -- env HOME="$project_dir/storage" XDG_CONFIG_HOME="$project_dir/storage/app/.config" "$php_bin" artisan tinker --execute='App\Support\LiveKitSettings::save(App\Support\LiveKitSettings::settings(), false);'
  )
else
  su -s /bin/sh "$web_user" -c "cd '$project_dir' && HOME='$project_dir/storage' XDG_CONFIG_HOME='$project_dir/storage/app/.config' '$php_bin' artisan tinker --execute='App\\Support\\LiveKitSettings::save(App\\Support\\LiveKitSettings::settings(), false);'"
fi

docker compose -f "$script_dir/runtime/docker-compose.yml" up -d

if command -v systemctl >/dev/null 2>&1 && command -v systemd-escape >/dev/null 2>&1 && [ -d /run/systemd/system ]; then
  unit_source="$script_dir/playhub-livekit-manager@.service"
  unit_link=/etc/systemd/system/playhub-livekit-manager@.service
  instance="$(systemd-escape --path "$script_dir")"
  queue_instance="$(systemd-escape --path "$project_dir")"
  queue_unit="playhub-queue-worker-$queue_instance.service"
  queue_unit_file="/etc/systemd/system/$queue_unit"
  ln -sfn "$unit_source" "$unit_link"
  cat > "$queue_unit_file" <<EOF
[Unit]
Description=PlayHub queue worker for $project_dir
After=network-online.target
Wants=network-online.target
ConditionPathExists=$project_dir/artisan

[Service]
Type=simple
User=$web_user
Group=$web_group
WorkingDirectory=$project_dir
ExecStart=$php_bin artisan queue:work --queue=videos,default --sleep=3 --tries=3 --timeout=7200 --max-time=3600
ExecReload=$php_bin artisan queue:restart
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=7300
UMask=0002
NoNewPrivileges=true
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
  chmod 0644 "$queue_unit_file"
  systemctl daemon-reload
  systemctl enable --now "playhub-livekit-manager@$instance.service"
  systemctl enable "$queue_unit"
  systemctl restart "$queue_unit"
  printf '%s\n' "LiveKit installed. Manager: playhub-livekit-manager@$instance.service"
  printf '%s\n' "Queue worker installed: $queue_unit"
else
  manager_pid_file="$script_dir/runtime/status/manager.pid"
  if [ -r "$manager_pid_file" ] && kill -0 "$(sed -n '1p' "$manager_pid_file")" 2>/dev/null; then
    printf '%s\n' 'LiveKit installed. The project-local Docker manager is already running.'
  else
    nohup "$script_dir/status-exporter.sh" --watch >> "$script_dir/runtime/status/manager.log" 2>&1 &
    printf '%s\n' "$!" > "$manager_pid_file"
    chmod 0644 "$manager_pid_file"
    printf '%s\n' 'LiveKit installed. Add livekit/status-exporter.sh --watch to your OS startup manager for reboot persistence.'
  fi

  queue_pid_file="$script_dir/runtime/status/queue-worker.pid"
  queue_log_file="$script_dir/runtime/logs/queue-worker.log"
  if [ -r "$queue_pid_file" ] && kill -0 "$(sed -n '1p' "$queue_pid_file")" 2>/dev/null; then
    printf '%s\n' 'The project-local queue worker is already running.'
  else
    (
      cd "$project_dir"
      if command -v runuser >/dev/null 2>&1; then
        nohup runuser -u "$web_user" -- "$php_bin" artisan queue:work --queue=videos,default --sleep=3 --tries=3 --timeout=7200 >> "$queue_log_file" 2>&1 &
      else
        nohup su -s /bin/sh "$web_user" -c "cd '$project_dir' && exec '$php_bin' artisan queue:work --queue=videos,default --sleep=3 --tries=3 --timeout=7200" >> "$queue_log_file" 2>&1 &
      fi
      printf '%s\n' "$!" > "$queue_pid_file"
    )
    chown "$web_user:$web_group" "$queue_pid_file" "$queue_log_file"
    chmod 0644 "$queue_pid_file" "$queue_log_file"
    printf '%s\n' 'Queue worker started. Add it to your OS startup manager for reboot persistence.'
  fi
fi
