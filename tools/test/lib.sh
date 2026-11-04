#!/usr/bin/env bash
# Shared helpers for the CrozzTube test harness.
# Source this file from suites; do not execute directly.

BASE_URL="${BASE_URL:-https://crozztube.local:8443}"
CURL_TIMEOUT="${CURL_TIMEOUT:-60}"
CURL=(curl -sk --max-time "$CURL_TIMEOUT")

TEST_USER="${TEST_USER:-benny}"
TEST_PASSWORD="${TEST_PASSWORD:-meZVRW0bYEbpHsJc}"

DB_CONTAINER="${DB_CONTAINER:-crozztube-mysql}"
DB_NAME="${DB_NAME:-crozztube}"
DB_USER="${DB_USER:-crozztube}"
DB_PASS="${DB_PASS:-crozztube-local-db}"

TOKEN="${TOKEN:-}"
ARTIFACT_DIR="${ARTIFACT_DIR:-/tmp/crozztube-tests}"

PASS=0
FAIL=0
SKIP=0
FAILED_NAMES=()

if [[ -t 1 ]]; then
    C_RED=$'\033[31m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'
    C_DIM=$'\033[2m'; C_BOLD=$'\033[1m'; C_RESET=$'\033[0m'
else
    C_RED=''; C_GREEN=''; C_YELLOW=''; C_DIM=''; C_BOLD=''; C_RESET=''
fi

section() {
    printf '\n%s== %s ==%s\n' "$C_BOLD" "$*" "$C_RESET"
}

pass() {
    PASS=$((PASS + 1))
    printf '  %sPASS%s %s\n' "$C_GREEN" "$C_RESET" "$*"
}

fail() {
    FAIL=$((FAIL + 1))
    FAILED_NAMES+=("$*")
    printf '  %sFAIL%s %s\n' "$C_RED" "$C_RESET" "$*"
}

skip() {
    SKIP=$((SKIP + 1))
    printf '  %sSKIP%s %s\n' "$C_YELLOW" "$C_RESET" "$*"
}

check_eq() { # name expected actual
    local name="$1" expected="$2" actual="$3"
    if [[ "$expected" == "$actual" ]]; then
        pass "$name"
    else
        fail "$name (expected '$expected', got '$actual')"
    fi
}

check_ne() {
    local name="$1" unexpected="$2" actual="$3"
    if [[ "$unexpected" != "$actual" ]]; then
        pass "$name"
    else
        fail "$name (did not expect '$unexpected')"
    fi
}

check_contains() { # name needle haystack
    local name="$1" needle="$2" haystack="$3"
    if [[ "$haystack" == *"$needle"* ]]; then
        pass "$name"
    else
        fail "$name (missing '$needle')"
    fi
}

check_ge() { # name minimum actual
    local name="$1" min="$2" actual="$3"
    if [[ "$actual" =~ ^[0-9]+$ ]] && (( actual >= min )); then
        pass "$name"
    else
        fail "$name (expected >= $min, got '$actual')"
    fi
}

check_http() { # name path expected
    local name="$1" path="$2" expected="${3:-200}" code
    code=$(api_code GET "$path")
    check_eq "$name" "$expected" "$code"
}

need_cmd() {
    command -v "$1" >/dev/null 2>&1 || { fail "tool '$1' missing"; return 1; }
}

login() {
    local body
    body=$("${CURL[@]}" -X POST "$BASE_URL/api/auth/login" \
        -H 'Accept: application/json' -H 'Content-Type: application/json' \
        -d "{\"login\":\"$TEST_USER\",\"password\":\"$TEST_PASSWORD\",\"device_name\":\"crozztube-harness\"}" \
        2>/dev/null) || true
    TOKEN=$(printf '%s' "$body" | jq -r '.token // empty' 2>/dev/null)
    [[ -n "$TOKEN" ]]
}

api_code() { # method path
    local method="$1" path="$2"
    "${CURL[@]}" -o /dev/null -w '%{http_code}' -X "$method" "$BASE_URL$path" \
        -H 'Accept: application/json' \
        ${TOKEN:+-H "Authorization: Bearer $TOKEN"}
}

api() { # method path [json-body]
    local method="$1" path="$2" body="${3:-}"
    if [[ -n "$body" ]]; then
        "${CURL[@]}" -X "$method" "$BASE_URL$path" \
            -H 'Accept: application/json' -H 'Content-Type: application/json' \
            ${TOKEN:+-H "Authorization: Bearer $TOKEN"} \
            -d "$body"
    else
        "${CURL[@]}" -X "$method" "$BASE_URL$path" \
            -H 'Accept: application/json' \
            ${TOKEN:+-H "Authorization: Bearer $TOKEN"}
    fi
}

db() { # raw SQL, tab-separated, no headers
    docker exec "$DB_CONTAINER" mysql -N -B -u"$DB_USER" -p"$DB_PASS" "$DB_NAME" -e "$1" 2>/dev/null
}

db_scalar() {
    db "$1" | head -1
}

wait_for() { # fn timeout_seconds description
    local fn="$1" timeout="$2" desc="$3" waited=0
    while (( waited < timeout )); do
        if "$fn"; then
            return 0
        fi
        sleep 2
        waited=$((waited + 2))
    done
    return 1
}

save_artifact() { # filename < stdin
    local name="$1"
    mkdir -p "$ARTIFACT_DIR"
    cat > "$ARTIFACT_DIR/$name"
    printf '  %sartifact:%s %s\n' "$C_DIM" "$C_RESET" "$ARTIFACT_DIR/$name"
}

video_id_for_slug() {
    db_scalar "SELECT id FROM videos WHERE slug='$1' LIMIT 1;"
}

# Cleanup registry for videos created by the harness.
CREATED_VIDEO_IDS=()
register_created_video() { CREATED_VIDEO_IDS+=("$1"); }

cleanup_created_videos() {
    local id
    for id in "${CREATED_VIDEO_IDS[@]:-}"; do
        [[ -z "$id" ]] && continue
        if [[ "${KEEP_TEST_VIDEOS:-0}" == "1" ]]; then
            printf '  %skept test video id=%s%s\n' "$C_DIM" "$id" "$C_RESET"
            continue
        fi
        api DELETE "/api/videos/$id" >/dev/null 2>&1 || true
        db "DELETE FROM crozztube_import_sources WHERE video_id=$id;" >/dev/null 2>&1 || true
    done
}

sed_in_place() { # portable in-place sed (GNU)
    sed -i "$@"
}
