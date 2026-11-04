# crozztube lokale Dokumentation (PlayHub 0.9.13)

**STATUS: INSTALLIERT & LAUFEND (4. Nov 2026)** — https://crozztube.local:8443 ✔ Health-Endpoint OK

## Zugänge

| Zugang | Wert |
| --- | --- |
| Website | https://crozztube.local:8443 (self-signed → Browser-Ausnahme akzeptieren) |
| Admin | https://crozztube.local:8443/admin |
| Admin-Login | **benny** / **meZVRW0bYEbpHsJc** (benny@crozztube.local) |
| phpMyAdmin | http://127.0.0.1:8083 |
| MailHog | http://127.0.0.1:8026 (UI) / SMTP im Container-Netz `mailhog:1025` |
| MySQL | 127.0.0.1:3308 — user `crozztube` / `crozztube-local-db`, DB `crozztube` (root: `crozztube-local-root`) |
| Lizenz | KNL-169D-00FC-DA0D-BA8E-C450-6EF0-B982-1E1D (receipt: storage/app/license.json) |

## Services (docker compose, Projekt `crozztube-local`, Datei `docker/compose.yml`)

| Service | Container | Details |
| --- | --- | --- |
| Web | crozztube-web | Ports 127.0.0.1:8082→80, 127.0.0.1:8443→443; PHP 8.3 + FFmpeg + Node 20 + docker-cli + imagick |
| MySQL 8.0.46 | crozztube-mysql | 3308; Volume crozztube-playhub_mysql-data |
| Redis 7 | crozztube-redis | nur intern; Passwort crozztube-local-redis; **DB 2** = LiveKit-Koordination |
| phpMyAdmin | crozztube-phpmyadmin | 8083 |
| MailHog | crozztube-mailhog | 1026 (Host-SMTP), 8026 (UI); im Netz via `mailhog:1025` |
| Worker | crozztube-worker | `queue:work --queue=videos,default` (Profile `runtime`) |
| Scheduler | crozztube-cron | `schedule:run` jede Minute (Profile `runtime`) |

Nicht anfassen: `crozztube-audit-*` (separate Audit-Instanz in /home/benny/audit-instance).

## Wichtige Befehle

```bash
cd "/mnt/intern-1tb/Projekte - Vibe Coding/crozztube/docker"

# alle Dienste inkl. Worker/Cron starten/stoppen (Daten bleiben in Volumes)
docker compose --profile runtime up -d
docker compose --profile runtime down

# nach .env/config-Änderungen (im App-Root):
docker exec crozztube-web php artisan optimize:clear
docker exec crozztube-web php artisan queue:restart

# Logs
docker logs -f crozztube-web
docker logs -f crozztube-worker
tail -f "/mnt/intern-1tb/Projekte - Vibe Coding/crozztube/storage/logs/laravel.log"
```

## Hinweise

- Quelle: `/mnt/intern-1tb/Neuer Ordner/Scripte - Mods - Systeme/playhub video cms/PlayHub web v0.9.13/Playhub-0.9.13.-full` (kopiert am 4.11.2026)
- APP_ENV=production, MAIL_MAILER=log (Mails gehen ins Laravel-Log; echte Testmails via MailHog: Admin → SMTP host `mailhog`, port 1025)
- APP_URL = https://crozztube.local:8443 (fix; Wizard-Reinstall blockiert durch storage/app/installed.lock)
- Self-Signed-Zertifikat (825 Tage) für localhost / crozztube.local / 127.0.0.1 — Browser-Ausnahme nötig
- **LiveKit/Live-Streaming noch NICHT eingerichtet** (bewusst zurückgestellt): Nachrüstung per `sudo livekit/install.sh` im App-Root; Web-Container hat bereits docker-CLI/Compose + gemounteten daemon-Socket
- Kataloge: 6 Gifts importiert; Sticker nutzen KLIPY (API-Key im Admin konfigurierbar)
