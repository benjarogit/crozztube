# HANDOFF — CrozzTube Source-Resolver (Stand: 4. Nov 2026, ~14:00)

> Weitergabedokument für den nächsten Agenten. Aktueller Zustand + offene Punkte.
> Wiki (Zugänge/Umgebung/Konfig/Tests): `Wiki/index.html` — zuerst lesen!

---

## 1. Projekt & Umgebung

- **Projekt:** CrozzTube = PlayHub web v0.9.13 (Laravel 13, React 19), lokale Instanz.
- **Konzept:** NUR Streaming/Import. Keine eigenen Uploads (Nutzer/Creator laden nichts hoch), **keine API-Keys**. Jedes importierte Video bekommt per **yt-dlp** eine Direkt-URL (eigener Player, HLS bevorzugt), Provider-Embed (YouTube) ist nur Mirror/Fallback.
- **URL:** `https://crozztube.local:8443` (self-signed) · Admin: `benny` / `meZVRW0bYEbpHsJc`
- **Docker-Stack** (`docker/compose.yml`, Projekt `crozztube-local`): crozztube-web (PHP 8.3 + FFmpeg + yt-dlp 2026.10.26), -mysql (Port 3308! User crozztube/crozztube-local-db), -redis (Pass crozztube-local-redis), -worker (`queue:work videos,maintenance,default`), -cron (schedule:run je Minute + täglicher yt-dlp-Auto-Update), -phpmyadmin (8083), -mailhog.
- **NICHT anfassen:** Container `crozztube-audit-*` (separate Audit-Instanz unter /home/benny/audit-instance).
- **Git-Backup:** `https://github.com/benjarogit/crozztube` (privat). Regelmäßig committen + pushen.

## 2. Was fertig & verifiziert ist

### Plugin `crozztube.source-resolver` v0.5.0 (installiert + aktiv)
Quelle: `docker/plugin-src/crozztube.source-resolver/` (dort liegt der Code, installiert wird via ZIP-Upload an die Admin-API; Hilfsskript `tools/dev/reinstall-plugin.sh`).

- **Resolver (`src/Resolver.php`):** yt-dlp `-J` keyless. Auswahllogik: echtes HLS-Manifest → sonst muxed MP4 → sonst **"hls-synth"** (YouTube-Sonderfall) → sonst Fehler.
- **YouTube-Sonderfall (wichtig!):** YouTube liefert seit SABR kein muxed MP4 und kein Master-Manifest mehr. Lösung: itags 233/234 sind versteckte **HLS-Audio-Playlists** (AAC-LC, geprüft via ffprobe). Das Plugin speichert bis zu 8 Video-Playlists (144p–2160p, AVC/VP9, deduped pro Höhe) + 1 Audio-Playlist in der DB (`variants`, `audio_url`) und baut ein **synthetisches HLS-Master-Manifest**.
- **Metadaten-Backfill:** `Resolver::backfillMetadata()` schreibt `duration_seconds` + `is_short` ins `videos`-Table, wenn dort NULL/0 (Fixt den früheren Bug „3:32-Video als Short“). Short-Erkennung über `/shorts|reel|reels/` in der Import-URL.
- **Passthrough-Proxy absichtlich!** googlevideo sendet kein `Access-Control-Allow-Origin`, HLS.js braucht daher einen Same-Origin-Pfad. Der Proxy streamt nur durch (curl-basiert), speichert **nichts** (kein Re-Hosting). Host-Allowlist: nur `*.googlevideo.com`.

### Provider-neutraler Mirror-Umschalter (v0.3.x)
- `src/Support/Mirror.php` erzeugt serverseitig den Mirror-Payload: `provider`, `label`, `mode` (`iframe` | `link`), `embed_url`, `external_url`. Das Frontend enthält **keine Provider-Logik** — YouTube, Vimeo, Dailymotion, Twitch und direkte Datei-URLs funktionieren über dieselbe Struktur.
- `embed_url` im `VideoResource` wird für YouTube **nicht** gesetzt (würde die Direktquelle verdrängen). Der Mirror kommt ausschließlich aus dem Plugin-Payload.
- Frontend: `assets/watch-enhancer.js|css` (Plugin-Assets). Button in `.watch-toolbar .action-bar`, Overlay im `.watch-player-stage`. Auswahl pro Video in `localStorage['crz.mirror.prefs']`.
- CSS setzt `.watch-player-stage { position: relative; }` — PlayHub positioniert die Stage nicht, sonst deckt das absolute Overlay die Toolbar ab.
- SPA-Navigation: Enhancer baut Overlay/Button/Banner bei Routenwechsel selbst ab.

### Auto-Refresh abgelaufener Direkt-URLs (v0.4/0.5)
- **Serverseitig:** `BaseSourceJob` mit Cache-Lock (600 s) + DB-State `refreshing`. `force` überspringt die „ist noch frisch“-Prüfung; **explizite/geforce Refreshs umgehen den 30-s-Debounce** (Job-Lock verhindert Doppelarbeit) — sonst verschluckt der Debounce Retry/Expiry-Läufe.
- `BaseSourceJob::request()` markiert abgelaufene Quellen als `stale` und liefert `bool` (ob wirklich eingereiht). Hängende `refreshing`-Zustände (Worker-Crash) werden nach `LOCK_TTL - 60 s` ignoriert/normalisiert.
- **Status-Payload:** `state`, `kind`, `fresh`, `has_direct`, `refreshing`, `error_message`, `downloadable`, `expires_at`, `expires_in`, `can_refresh`, `retry_after`, `refresh_started_at`, `mirror`.
- **Frontend (`watch-enhancer.js`):** Banner („wird erneuert“ / Fehler + Retry + Mirror), Polling, einmaliger Auto-Reload nach frischer URL (max. 2 Versuche, Resume-Position in `sessionStorage`), Mirror-Fallback bei hartem Fehler. Auto-Refresh triggert nur bei vorhandener, abgelaufener Direktquelle — kein Endlos-yt-dlp bei kaputtem Import.
- **Sicherheitsnetze ohne JS:** `manifest`-Route stößt bei abgelaufener Quelle einen Refresh an und liefert das aktuelle Manifest weiter; `playlist`-Route triggert bei CDN-Fehler einen Refresh (Video-ID wird durch das synthetische Manifest gereicht).

### Wartungs-Jobs (v0.5.0)
- `crozztube:sources:refresh-expiring` — alle 5 Min, Optionen `--window=15 --limit=10 --stagger=15 --scheduled --dry-run`. Erneuert Quellen kurz vor Ablauf, gestaffelt (Delay pro Dispatch), Queue `maintenance`.
- `crozztube:sources:probe` — stündlich zwischen 02:00–05:00, Optionen `--limit=20 --min-age=60 --stagger=10 --scheduled --dry-run`. HEAD-Check, Fallback 1-Byte-Range-GET, schreibt `last_checked_at`/`last_check_status`; defekte URLs → `stale` + Refresh.
- Beide respektieren die Pause-Datei `storage/framework/crozztube-maintenance.pause` (nur mit `--scheduled`, damit manuelle Läufe immer funktionieren). Das Test-Harness setzt sie automatisch.
- Migration `2026_11_04_000003_add_probe_fields_…` (Probe-Spalten + Index auf `expires_at`).

### Test-Harness (vollständig agent-ausführbar)
```bash
./tools/test/run.sh all          # alles: 70 Checks + 5 Playwright-Tests
./tools/test/run.sh list         # Suiten anzeigen
./tools/test/run.sh refresh jobs # gezielt
SLOW=0 ./tools/test/run.sh jobs  # ohne yt-dlp-Roundtrip
```
Suiten: `stack`, `api`, `media`, `refresh`, `mirror`, `jobs`, `ui`. Details: `Wiki/tests.html`.
Letzter vollständiger Lauf: **70 Checks + 5 Browser-Tests grün**.

### Commits
| Commit | Inhalt |
|---|---|
| `98eb7e5` | Baseline PlayHub 0.9.13 + Resolver v0.2.4 |
| `0f09a40` | Test-Harness (stack, api, media) |
| `6e6a701` | Provider-neutraler Mirror + UI-Suite |
| `0a65ed8` | Auto-Refresh + Wartungs-Jobs + refresh/jobs-Suiten |

### Live-Stand der Instanz
- Drei importierte Videos, alle `fresh`, `kind=hls-synth`, Manifeste HTTP 200 (6–8 Varianten), Segment-Passthrough geprüft.
- `failed_jobs` enthält historische Einträge (yt-dlp not found, unavailable IDs, transient) — nicht aktuell relevant, Queue läuft leer.
- Stack läuft vollständig, `https://crozztube.local:8443` → HTTP 200.

## 3. Architektur-Entscheidungen (nicht zurückdrehen)

1. **Frontend nur als Plugin-Assets.** `resources/js/` enthält nur Admin/Upload/Embed-Player; die Watch-Page existiert ausschließlich im Bundle. Ein React-Patch ist nicht möglich. Plugin-Assets werden über `plugin.json → assets.scripts/styles` eingebunden.
2. **Mirror ist provider-neutral.** Neue Provider nur in `Mirror::forVideo()` ergänzen; Frontend bleibt unverändert.
3. **Kein Download-UI.** Der Endpoint `/download/{id}` bleibt als Passthrough bestehen, wird aber von der UI nicht mehr angeboten (Entscheidung des Users). Kein Re-Mux/Transcode.
4. **Status per Slug.** `/api/source-resolver/status/slug/{slug}` — die Watch-Page braucht genau einen Request, keinen zusätzlichen `/api/videos/{slug}`-Call.
5. **Eigene Throttle-Präfixe pro Route.** Laravel keyt Limits sonst nur nach IP → Segment-Traffic würde `/status` blockieren und die UI still kaputt machen.
6. **Zeitstempel aus PHP, kein SQL `NOW()`** in Plugin-Code/Tests (siehe Fallstricke).
7. **`force` umgeht den Debounce**, nicht den Lock.

## 4. Offene Punkte

### E1. Mehrstündiges Video (Podcast/Stream-Replay)
Bisher max. 9 Min. geprüft. Master-Manifest, Playlist-Länge, Buffering und Resume unter echten Bedingungen testen.

### E2. Mehrere Nutzer gleichzeitig auf abgelaufener Quelle
Erwartung: genau **ein** yt-dlp-Lauf, `refresh_attempts` steigt nur einmal. Prüfbar über Worker-Log + `refresh_attempts` + `jobs`-Tabelle. Serverseitig durch Lock + Force-Bypass abgedeckt, aber noch nicht mit parallelen Browser-Sessions verifiziert.

### E3. Direct-MP4-Import im Browser
Import einer echten progressiven MP4-Quelle: Wiedergabe + Mirror-Modus `link` prüfen. Download ist bewusst kein UI-Feature mehr.

### E4. Shorts-Darstellung
Serverseitig korrekt (`is_short`, Dauer). Offen: Darstellung in der Shorts-Ansicht mit dem neuen Backfill gegenprüfen.

### E5. Doku-Pflege
Bei jeder Plugin-Version `plugin.json`-Version, Wiki und dieses Dokument mitziehen.

## 5. Betriebs-KnowHow / Fallstricke

- **Plugin reinstall:** `./tools/dev/reinstall-plugin.sh` (baut ZIP, disable → install → enable → `queue:restart`). Manuell: `POST /api/admin/plugins` (multipart `package`) → `enable`; **immer** `docker exec crozztube-web php artisan queue:restart`, sonst `__PHP_Incomplete_Class` in Jobs.
- **Manifest-Limits:** `plugin.json`-Textfelder (z. B. `capabilities[]`) dürfen **max. 80 Zeichen** haben, sonst schlägt der Install fehl.
- **Throttle:** `POST /api/admin/plugins` hat `throttle:10,1` — bei mehreren Reinstalls in Folge 429, ~60 s warten.
- **Zeitfalle:** App-Timezone ist `Europe/Berlin`, die MySQL-Session läuft in **UTC**. `NOW()`/`CURRENT_TIMESTAMP` in SQL ist eine Stunde daneben. In Plugin-Code und Tests Zeitstempel aus PHP formatieren (`now()->format('Y-m-d H:i:s')`) oder feste Werte wie `'2000-01-01 00:00:00'` verwenden.
- **Cache-Store:** `database` (Tabelle `cache`, Prefix `crozztube_cache_`). Abgelaufene Keys werden lazy gelöscht; `Cache::add()` funktioniert danach wieder. Für Tests ggf. `Cache::forget('source-resolver:req.{id}')`.
- **Scheduler pausieren:** `touch storage/framework/crozztube-maintenance.pause`; löschen zum Fortsetzen. Das Harness macht das automatisch um seine Laufzeit.
- **DB:** `docker exec crozztube-mysql mysql -ucrozztube -pcrozztube-local-db crozztube …` (Host-Port 3308); Plugin-Tabelle `playhub_plugins`; Runtime-Code unter `storage/app/plugins/crozztube.source-resolver/`.
- **Settings-Dateien:** `storage/app/playhub-features.json` (uploadRoles=admin), `playhub-video-import.json` (direct+youtube AN, vimeo/dailymotion/twitch AUS), `playhub-live-streaming.json` (false).
- **LiveKit:** Runtime bewusst nicht installiert. Re-Enable-Pfad in `Wiki/konfiguration.html` §2.
- **Symfony Process:** `->isSuccessful()` nicht `->successful()`.
- **fopen + googlevideo hängt** → curl-basierter Passthrough. Nicht zurückändern.
- **Watch-DOM:** `.watch-layout-shell > main.watch-page > .watch-primary > .watch-player-stage`; Buttons in `.watch-info .watch-toolbar .action-bar`; Sidebar-Navigation = `aside.sidebar .nav-item` (Buttons, kein `href`).
- **Token-Login für API-Tests:** `POST /api/auth/login` mit `{login,password,device_name}` → Bearer-Token (ggf. in `/tmp/opencode/token.txt`).

## 6. Wichtige Pfade

| Pfad | Inhalt |
|---|---|
| `docker/plugin-src/crozztube.source-resolver/` | Plugin-Quellcode (installierbar via ZIP) |
| `…/assets/watch-enhancer.js|css` | Mirror-Toggle, Banner, Polling, Auto-Reload |
| `…/src/Support/Mirror.php` | provider-neutraler Mirror-Payload |
| `…/src/Jobs/BaseSourceJob.php` | Lock, Debounce, `force`, `recoverStale` |
| `…/src/Commands/RefreshExpiringSources.php`, `ProbeSources.php` | Wartungs-Jobs |
| `…/src/PluginServiceProvider.php` | Routen, Commands, Scheduler |
| `docker/compose.yml`, `docker/Dockerfile` | Stack (Ports, yt-dlp-Fix, Worker-Queue) |
| `app/Models/Video.php`, `app/Http/Resources/VideoResource.php`, `app/Http/Controllers/Api/VideoController.php` | drei dokumentierte Core-Patches |
| `tools/test/` | Harness + Playwright-Suiten |
| `tools/dev/reinstall-plugin.sh` | ZIP-Build + Install-Zyklus |
| `Wiki/` | Klickbare Doku (index/zugang/umgebung/konfiguration/tests) |
| `HANDOFF.md` | dieses Dokument |

## 7. Zielzustand (aus User-Anforderungen)

1. Nur Streaming, keine Uploads (admin-only via `uploadRoles`), keine API-Keys ✓
2. Direkt-URL via yt-dlp = Pflicht-Primärpfad, eigener Player ✓
3. Embed als Mirror-Fallback + umschaltbarer Button unter dem Video ✓ (provider-neutral)
4. Auto-Refresh bei Ablauf/Abbruch, Banner für alle Nutzer, Reload wenn möglich ✓
5. Kein Download-Button in der UI ✓ (Endpoint bleibt)
6. Gestaffelte Nacht-/Expiry-Jobs ohne Serverlast ✓
7. Test-Harness, das der Agent selbst komplett ausführen kann ✓
