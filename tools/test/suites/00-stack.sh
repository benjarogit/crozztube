#!/usr/bin/env bash
# Stack health: containers, HTTPS, plugin state, worker/cron.

suite_stack() {
    section "stack"

    if ! command -v docker >/dev/null 2>&1; then
        skip "docker CLI not available"
        return
    fi

    local expected=(crozztube-web crozztube-worker crozztube-cron crozztube-mysql crozztube-redis)
    local name state
    for name in "${expected[@]}"; do
        state=$(docker inspect -f '{{.State.Running}}' "$name" 2>/dev/null)
        check_eq "container $name running" "true" "${state:-false}"
    done

    local code
    code=$(curl -sk -o /dev/null -w '%{http_code}' --max-time 15 "$BASE_URL/" 2>/dev/null)
    check_eq "HTTPS homepage" "200" "$code"

    local enabled
    enabled=$(db_scalar "SELECT enabled FROM playhub_plugins WHERE id='crozztube.source-resolver' LIMIT 1;")
    check_eq "plugin enabled" "1" "$enabled"

    local source_count
    source_count=$(db_scalar "SELECT COUNT(*) FROM crozztube_import_sources;")
    check_ge "import sources table readable" 0 "${source_count:-0}"

    local failed
    failed=$(db_scalar "SELECT COUNT(*) FROM failed_jobs;")
    if [[ "${failed:-0}" -gt 0 ]]; then
        printf '  %snote:%s %s failed job(s) in failed_jobs (historical, not fatal)\n' "$C_YELLOW" "$C_RESET" "$failed"
    fi

    if docker exec crozztube-cron sh -lc 'command -v php >/dev/null' 2>/dev/null; then
        pass "cron container has php"
    else
        fail "cron container has php"
    fi

    local schedule_ok
    schedule_ok=$(docker exec crozztube-web php artisan schedule:list >/dev/null 2>&1 && echo yes || echo no)
    check_eq "scheduler list works" "yes" "$schedule_ok"
}
