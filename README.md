# CrozzTube

Lokale Streaming-Plattform auf Basis von **PlayHub 0.9.13** (Laravel 13 · React 19). Ausrichtung: **Nur Streaming/Imports — keine eigenen Uploads, keine API-Keys.**

## Schnellstart

| | |
|---|---|
| 🌐 Website | https://crozztube.local:8443 (self-signed Zertifikat → Browser-Ausnahme akzeptieren) |
| 🛠 Admin-Panel | https://crozztube.local:8443/admin |
| 👤 Login | `benny` / `meZVRW0bYEbpHsJc` |
| 📚 **Wiki** | **`Wiki/index.html` öffnen** — Zugänge, Umgebung, Konfiguration mit Begründungen |
| 🐳 Stack | `docker/README.md` · Compose: `docker/compose.yml` (Projekt `crozztube-local`) |

## Zielausrichtung (Kurzfassung)

1. **Import statt Upload** — Nutzer und Creator laden nichts hoch; Admin importiert URLs (YouTube keyless + Direct-URL aktiv; Vimeo/Dailymotion/Twitch deaktiviert).
2. **Direkt-URL via yt-dlp = Pflicht** — eigener Player spielt die aufgelöste Original-URL (HLS bevorzugt, MP4-Fallback); Provider-Embed immer als Mirror-Fallback; Refresh bei Ablauf mit Single-Flight-Lock.
3. **Download-Button** — beste progressive MP4, versteckt bei HLS-only-Quellen.
4. **Live-Streaming (LiveKit) deaktiviert** — Wiederaktivierung in `Wiki/konfiguration.html` dokumentiert.

## Wichtigste Dateien

- `Wiki/index.html` — Einstieg
- `docker/compose.yml` + `docker/README.md` — Umgebung
- `storage/app/playhub-*.json` — zentrale App-Settings (Import, Features, Live)
- `plugins/` — hier lebt später `crozztube.source-resolver`
