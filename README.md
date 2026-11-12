# CrozzTube

Lokale Streaming-Plattform auf Basis von **PlayHub 0.9.13** (Laravel 13 · React 19). Ausrichtung: **Nur Streaming/Imports — keine eigenen Uploads, keine API-Keys.**

## Schnellstart

| | |
|---|---|
| 🌐 Website | https://crozztube.local:8443 (lokal vertrauenswürdiges HTTPS mit mkcert) |
| 🛠 Admin-Panel | https://crozztube.local:8443/admin |
| 👤 Login | `benny` / `lokal hinterlegt; nicht versionieren` |
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

## Vertrauenswürdiges lokales HTTPS

Führe `bash tools/dev/setup-local-https.sh` im Projektverzeichnis als normaler Entwicklungsnutzer aus. Das Skript verwendet die vorhandene, im System vertrauenswürdige mkcert-CA. Auf einem neuen Rechner musst du mkcert zuerst installieren und einmal `mkcert -install` als dein normaler Nutzer ausführen. Der private CA-Schlüssel bleibt im von `mkcert -CAROOT` angezeigten Nutzerverzeichnis und wird weder ins Projekt noch in Container kopiert.

Das Setup prüft Zertifikatskette, Hostnamen, Gültigkeit und Schlüsselpaar, sichert bisherige Dateien unter dem von Git ausgeschlossenen `docker/certs/` und ersetzt nur die bestehenden Serverzertifikatspfade. Bei laufenden Uploads bricht es ab. Nur der Webcontainer wird bei Bedarf neu erzeugt; Datenbank und Worker laufen weiter. Schlägt die Prüfung nach einem Zertifikatswechsel fehl, werden die gesicherten Dateien wiederhergestellt. Ein erneuter Aufruf bei gültiger Konfiguration ändert nichts. Browserentwürfe werden nicht neu geladen.

Prüfe die Verbindung regulär mit `curl --fail https://crozztube.local:8443/`. Der fokussierte Browsercheck `node tools/test/ui/strict-https.mjs` verwendet Chromium und Firefox mit aktivierter Zertifikatsprüfung. Eine Warnseite muss durch die Vertrauenskette behoben werden; Zertifikatsausnahmen gehören nicht zum Setup. Weitere lokale Browserprofile müssen der gleichen öffentlichen Entwicklungs-CA vertrauen.
