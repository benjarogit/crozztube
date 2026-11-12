#!/usr/bin/env bash
# Reuse this developer's trusted CA; never copy its private key into Docker.
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$project_root"
for executable in mkcert openssl docker curl; do
  command -v "$executable" >/dev/null || { echo "Missing executable: $executable" >&2; exit 1; }
done
ca_root="$(mkcert -CAROOT)"
[[ -r "$ca_root/rootCA.pem" && -r "$ca_root/rootCA-key.pem" ]] || {
  echo 'A local development CA is required. Run mkcert -install as your normal user first.' >&2; exit 1;
}
openssl verify -CApath /etc/ssl/certs "$ca_root/rootCA.pem" >/dev/null || {
  echo 'The existing development CA is not trusted by the system. Run mkcert -install first.' >&2; exit 1;
}

certificate="$project_root/docker/certs/fullchain.pem"
private_key="$project_root/docker/certs/privkey.pem"
valid_pair() {
  local candidate_certificate="$1" candidate_key="$2" cert_public key_public
  [[ -r "$candidate_certificate" && -r "$candidate_key" ]] || return 1
  openssl verify -CAfile "$ca_root/rootCA.pem" "$candidate_certificate" >/dev/null 2>&1 || return 1
  openssl x509 -in "$candidate_certificate" -checkend 2592000 -noout >/dev/null || return 1
  for host in crozztube.local localhost; do
    openssl x509 -in "$candidate_certificate" -checkhost "$host" -noout >/dev/null || return 1
  done
  for address in 127.0.0.1 ::1; do
    openssl x509 -in "$candidate_certificate" -checkip "$address" -noout >/dev/null || return 1
  done
  cert_public="$(openssl x509 -in "$candidate_certificate" -pubkey -noout | openssl pkey -pubin -outform DER 2>/dev/null | openssl dgst -sha256)"
  key_public="$(openssl pkey -in "$candidate_key" -pubout -outform DER 2>/dev/null | openssl dgst -sha256)"
  [[ "$cert_public" == "$key_public" ]]
}

https_ready() {
  curl --silent --show-error --fail --max-time 10 --output /dev/null https://crozztube.local:8443/
}

if valid_pair "$certificate" "$private_key" && https_ready 2>/dev/null; then
  echo 'Local HTTPS already uses a valid, trusted development certificate.'
  exit 0
fi

active_uploads="$(docker exec crozztube-web php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function(Throwable $error): void { fwrite(STDERR, "Could not check active uploads.\n"); exit(1); });
$active = App\Models\UploadSession::query()->whereIn("status", ["pending", "uploading", "assembling"])->where("updated_at", ">=", now()->subMinutes(10))->count();
if (Illuminate\Support\Facades\Schema::hasTable("image_uploads")) $active += Illuminate\Support\Facades\DB::table("image_uploads")->where("status", "uploading")->where("updated_at", ">=", now()->subMinutes(10))->count();
echo $active;
')"
[[ "$active_uploads" =~ ^[0-9]+$ ]] || { echo 'Could not verify upload state.' >&2; exit 1; }
[[ "$active_uploads" == 0 ]] || { echo 'Uploads are active. Retry after they finish; nothing was restarted.' >&2; exit 1; }

mkdir -p "$project_root/docker/certs"
staging="$(mktemp -d "$project_root/docker/certs/.https-stage.XXXXXXXX")"
backup="$(mktemp -d "$project_root/docker/certs/backup.XXXXXXXX")"
chmod 700 "$staging" "$backup"
changed=0
had_certificate=0
had_key=0
if [[ -f "$certificate" ]]; then cp -p "$certificate" "$backup/fullchain.pem"; had_certificate=1; fi
if [[ -f "$private_key" ]]; then cp -p "$private_key" "$backup/privkey.pem"; had_key=1; fi
chmod 600 "$backup"/* 2>/dev/null || true

cleanup() {
  local status=$?
  trap - EXIT
  if [[ "$status" != 0 && "$changed" == 1 ]]; then
    echo 'HTTPS setup failed; restoring the previous certificate files.' >&2
    if [[ "$had_certificate" == 1 ]]; then cp -p "$backup/fullchain.pem" "$certificate"; else rm -f "$certificate"; fi
    if [[ "$had_key" == 1 ]]; then cp -p "$backup/privkey.pem" "$private_key"; else rm -f "$private_key"; fi
    docker compose -f docker/compose.yml up -d --no-deps --force-recreate web >&2 || true
  fi
  rm -rf -- "$staging"
  exit "$status"
}
trap cleanup EXIT

if valid_pair "$certificate" "$private_key"; then
  echo 'Certificate files are valid; refreshing the web container mount.'
else
  mkcert -cert-file "$staging/fullchain.pem" -key-file "$staging/privkey.pem" crozztube.local localhost 127.0.0.1 ::1
  valid_pair "$staging/fullchain.pem" "$staging/privkey.pem" || { echo 'Generated certificate validation failed.' >&2; exit 1; }
  chmod 644 "$staging/fullchain.pem"
  chmod 600 "$staging/privkey.pem"
  changed=1
  mv "$staging/fullchain.pem" "$certificate"
  mv "$staging/privkey.pem" "$private_key"
fi
docker compose -f docker/compose.yml up -d --no-deps --force-recreate web
for attempt in {1..20}; do
  if https_ready 2>/dev/null; then
    echo 'Local HTTPS verified with normal certificate validation. Only the web container was recreated.'
    echo "Previous certificate files: $backup"
    exit 0
  fi
  sleep 1
done
echo 'Local HTTPS did not become ready.' >&2
exit 1
