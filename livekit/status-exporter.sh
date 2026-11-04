#!/bin/sh
set -eu

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
script_dir="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
settings_file="$script_dir/runtime/settings.json"

setting() {
  key="$1"
  fallback="$2"
  value=""
  if [ -r "$settings_file" ]; then
    value="$(sed -n "s/^[[:space:]]*\"$key\":[[:space:]]*\"\([^\"]*\)\".*/\1/p" "$settings_file" | head -n 1)"
  fi
  printf '%s' "${value:-$fallback}"
}

project_path() {
  relative="$1"
  case "$relative" in
    livekit/*) printf '%s/%s' "$script_dir" "${relative#livekit/}" ;;
    *) return 1 ;;
  esac
}

json_quote() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g; s/\t/\\t/g'
}

inspect_metadata() {
  inspected_name="$1"
  inspected_error="${2:-}"
  if docker inspect --type container "$inspected_name" >/dev/null 2>&1; then
    id="$(docker inspect --format '{{.Id}}' "$inspected_name")"
    inspected_image="$(docker inspect --format '{{.Config.Image}}' "$inspected_name")"
    image_id="$(docker inspect --format '{{.Image}}' "$inspected_name")"
    status="$(docker inspect --format '{{.State.Status}}' "$inspected_name")"
    running="$(docker inspect --format '{{.State.Running}}' "$inspected_name")"
    restarting="$(docker inspect --format '{{.State.Restarting}}' "$inspected_name")"
    restart_count="$(docker inspect --format '{{.RestartCount}}' "$inspected_name")"
    exit_code="$(docker inspect --format '{{.State.ExitCode}}' "$inspected_name")"
    health="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$inspected_name")"
    started_at="$(docker inspect --format '{{.State.StartedAt}}' "$inspected_name")"
    finished_at="$(docker inspect --format '{{.State.FinishedAt}}' "$inspected_name")"
    container_error="$(docker inspect --format '{{.State.Error}}' "$inspected_name")"
    image_tags='[]'
    image_created=''
    image_size=0
    image_arch=''
    image_os=''
    if docker image inspect "$image_id" >/dev/null 2>&1; then
      image_tags="$(docker image inspect --format '{{json .RepoTags}}' "$image_id")"
      image_created="$(docker image inspect --format '{{.Created}}' "$image_id")"
      image_size="$(docker image inspect --format '{{.Size}}' "$image_id")"
      image_arch="$(docker image inspect --format '{{.Architecture}}' "$image_id")"
      image_os="$(docker image inspect --format '{{.Os}}' "$image_id")"
    fi
    [ -n "$inspected_error" ] && container_error="$inspected_error"
    container_json="{\"exists\":true,\"name\":\"$(json_quote "$inspected_name")\",\"id\":\"$(json_quote "$id")\",\"image\":\"$(json_quote "$inspected_image")\",\"image_id\":\"$(json_quote "$image_id")\",\"status\":\"$(json_quote "$status")\",\"running\":$running,\"restarting\":$restarting,\"restart_count\":$restart_count,\"exit_code\":$exit_code,\"health\":\"$(json_quote "$health")\",\"started_at\":\"$(json_quote "$started_at")\",\"finished_at\":\"$(json_quote "$finished_at")\",\"error\":\"$(json_quote "$container_error")\"}"
    image_json="{\"id\":\"$(json_quote "$image_id")\",\"tags\":${image_tags:-[]},\"created\":\"$(json_quote "$image_created")\",\"size\":$image_size,\"architecture\":\"$(json_quote "$image_arch")\",\"os\":\"$(json_quote "$image_os")\"}"
  else
    container_json="{\"exists\":false,\"name\":\"$(json_quote "$inspected_name")\",\"id\":\"\",\"image\":\"\",\"image_id\":\"\",\"status\":\"missing\",\"running\":false,\"restarting\":false,\"restart_count\":0,\"exit_code\":0,\"health\":\"none\",\"started_at\":\"\",\"finished_at\":\"\",\"error\":\"$(json_quote "$inspected_error")\"}"
    image_json='{"id":"","tags":[],"created":"","size":0,"architecture":"","os":""}'
  fi
}

export_status() {
  container_name="$(setting container_name playhub-livekit)"
  ingress_container_name="$(setting ingress_container_name playhub-livekit-ingress)"
  egress_container_name="$(setting egress_container_name playhub-livekit-egress)"
  status_path="$(setting status_path livekit/runtime/status/docker-status.json)"
  status_file="$(project_path "$status_path")"
  status_dir="$(dirname "$status_file")"
  apply_path="$(setting docker_apply_trigger_path livekit/runtime/docker-apply.trigger)"
  apply_file="$(project_path "$apply_path")"
  command_path="$(setting docker_command_path livekit/runtime/docker-command.trigger)"
  command_file="$(project_path "$command_path")"
  compose_path="$(setting docker_compose_path livekit/runtime/docker-compose.yml)"
  compose_file="$(project_path "$compose_path")"
  applied_file="$status_dir/docker-apply.applied"
  command_applied_file="$status_dir/docker-command.applied"
  action_log="$status_dir/docker-action.log"
  action_error_file="$status_dir/docker-action.error"
  action_error="$(sed -n '1p' "$action_error_file" 2>/dev/null || true)"

  mkdir -p "$status_dir"

  if [ -r "$apply_file" ] && [ -r "$compose_file" ]; then
    requested="$(sha256sum "$apply_file" 2>/dev/null | awk '{print $1}')"
    applied="$(sed -n '1p' "$applied_file" 2>/dev/null || true)"
    if [ -n "$requested" ] && [ "$requested" != "$applied" ]; then
      if docker compose -f "$compose_file" up -d --force-recreate livekit ingress egress > "$action_log.tmp" 2>&1; then
        mv -f "$action_log.tmp" "$action_log"
        rm -f "$action_error_file"
        action_error=""
      else
        mv -f "$action_log.tmp" "$action_log"
        action_error="Docker Compose apply failed. See docker-action.log."
        printf '%s\n' "$action_error" > "$action_error_file"
        chmod 0644 "$action_error_file"
      fi
      printf '%s\n' "$requested" > "$applied_file"
      chmod 0644 "$applied_file" "$action_log"
    fi
  fi

  if [ -r "$command_file" ]; then
    requested="$(sha256sum "$command_file" 2>/dev/null | awk '{print $1}')"
    applied="$(sed -n '1p' "$command_applied_file" 2>/dev/null || true)"
    action="$(sed -n '1p' "$command_file" 2>/dev/null || true)"
    if [ -n "$requested" ] && [ "$requested" != "$applied" ]; then
      command_ok=false
      case "$action" in
        start) docker compose -f "$compose_file" up -d livekit ingress egress > "$action_log.tmp" 2>&1 && command_ok=true ;;
        stop) docker compose -f "$compose_file" stop egress ingress livekit > "$action_log.tmp" 2>&1 && command_ok=true ;;
        restart) docker compose -f "$compose_file" restart livekit ingress egress > "$action_log.tmp" 2>&1 && command_ok=true ;;
        recreate) docker compose -f "$compose_file" up -d --force-recreate livekit ingress egress > "$action_log.tmp" 2>&1 && command_ok=true ;;
        pull)
          if docker compose -f "$compose_file" pull livekit ingress egress > "$action_log.tmp" 2>&1 && docker compose -f "$compose_file" up -d livekit ingress egress >> "$action_log.tmp" 2>&1; then
            command_ok=true
          fi
          ;;
        *) printf '%s\n' "Unsupported Docker action: $action" > "$action_log.tmp" ;;
      esac
      mv -f "$action_log.tmp" "$action_log"
      if [ "$command_ok" = true ]; then
        rm -f "$action_error_file"
        action_error=""
      else
        action_error="LiveKit Docker $action failed. See docker-action.log."
        printf '%s\n' "$action_error" > "$action_error_file"
        chmod 0644 "$action_error_file"
      fi
      printf '%s\n' "$requested" > "$command_applied_file"
      chmod 0644 "$command_applied_file" "$action_log"
    fi
  fi

  updated_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
  temporary_file="$(mktemp "$status_dir/.docker-status.XXXXXX")"
  trap 'rm -f "$temporary_file"' EXIT INT TERM

  inspect_metadata "$container_name" "$action_error"
  livekit_container_json="$container_json"
  livekit_image_json="$image_json"
  inspect_metadata "$ingress_container_name" "$action_error"
  ingress_container_json="$container_json"
  ingress_image_json="$image_json"
  inspect_metadata "$egress_container_name" "$action_error"
  egress_container_json="$container_json"
  egress_image_json="$image_json"
  printf '%s\n' "{\"updated_at\":\"$updated_at\",\"container\":$livekit_container_json,\"image\":$livekit_image_json,\"ingress_container\":$ingress_container_json,\"ingress_image\":$ingress_image_json,\"egress_container\":$egress_container_json,\"egress_image\":$egress_image_json}" > "$temporary_file"

  chmod 0644 "$temporary_file"
  mv -f "$temporary_file" "$status_file"
  trap - EXIT INT TERM
}

if [ "${1:-}" = "--watch" ]; then
  while :; do
    export_status
    sleep 5
  done
else
  export_status
fi
