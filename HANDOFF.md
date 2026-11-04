# HANDOFF — CrozzTube Source-Resolver (Stand: 4. Nov 2026, ~12:45)

> Weitergabedokument für den nächsten Agenten. Aktueller Zustand + komplette Aufgabenliste.
> Wiki (Zugänge/Umgebung/Konfig): `Wiki/index.html`, `Wiki/konfiguration.html` — zuerst lesen!

---

## 1. Projekt & Umgebung

- **Projekt:** CrozzTube = PlayHub web v0.9.13 (Laravel 13, React 19), lokale Instanz.
- **Konzept:** NUR Streaming/Import. Keine eigenen Uploads (Nutzer/Creator laden nichts hoch), **keine API-Keys**. Jedes importierte Video bekommt per **yt-dlp** eine Direkt-URL (eigener Player, HLS bevorzugt), Provider-Embed (YouTube) ist nur Mirror/Fallback.
- **URL:** `https://crozztube.local:8443` (self-signed) · Admin: `benny` / `meZVRW0bYEbpHsJc`
- **Docker-Stack** (`docker/compose.yml`, Projekt `crozztube-local`): crozztube-web (PHP 8.3 + FFmpeg + yt-dlp 2026.10.26), -mysql (Port 3308! User crozztube/crozztube-local-db), -redis (Pass crozztube-local-redis), -worker (queue:work videos,default), -cron (schedule:run je Minute + täglicher yt-dlp-Auto-Update), -phpmyadmin (8083), -mailhog.
- **NICHT anfassen:** Container `crozztube-audit-*` (separate Audit-Instanz unter /home/benny/audit-instance).

## 2. Was fertig & verifiziert ist

### Plugin `crozztube.source-resolver` v0.2.4 (installiert + aktiv)
Quelle: `docker/plugin-src/crozztube.source-resolver/` (dort liegt der Code, installiert wird via ZIP-Upload an die Admin-API).

- **Resolver (`src/Resolver.php`):** yt-dlp `-J` keyless. Auswahllogik: echtes HLS-Manifest → sonst muxed MP4 → sonst **"hls-synth"** (YouTube-Sonderfall) → sonst Fehler.
- **YouTube-Sonderfall (wichtig!):** YouTube liefert seit SABR kein muxed MP4 und kein Master-Manifest mehr. Lösung: itags 233/234 sind versteckte **HLS-Audio-Playlists** (AAC-LC, geprüft via ffprobe). Das Plugin speichert bis zu 8 Video-Playlists (144p–2160p, AVC/VP9, deduped pro Höhe) + 1 Audio-Playlist in der DB (`variants`, `audio_url`) und baut ein **synthetisches HLS-Master-Manifest**.
- **Endpoints (`routes/api.php`, Controller `src/ResolverController.php`):**
  - `GET /api/source-resolver/status/{videoId}` → State (fresh/refreshing/error, `downloadable`) für Ajax-Banner
  - `POST /api/source-resolver/refresh/{videoId}` → Refresh anstoßen (idempotent)
  - `GET /api/source-resolver/manifest/{videoId}` → synthetisches Master-Manifest (M3U8)
  - `GET /api/source-resolver/playlist?url=…` → CDN-Playlist-Passthrough + URL-Rewrite (auch `#EXT-X-MAP:URI="…"`)
  - `GET /api/source-resolver/seg?url=…` → Segment-Streampassthrough
  - `GET /api/source-resolver/download/{videoId}` → Download-Passthrough beste MP4; **409 wenn HLS-only**
- **Passthrough-Proxy absichtlich!** googlevideo sendet kein `Access-Control-Allow-Origin`, HLS.js braucht daher einen Same-Origin-Pfad. Der Proxy streamt nur durch (curl-basiert, da PHP-fopen bei googlevideo hängen bleibt), speichert **nichts** (kein Re-Hosting). Host-Allowlist: nur `*.googlevideo.com`.
- **Refresh/Single-Flight:** `src/Jobs/BaseSourceJob.php` — Cache-Lock 600 s + Dispatch-Guard 30 s (`refreshing`-State). TTL wird aus `expire=`-Param der gespeicherten CDN-URLs berechnet (YouTube ~6 h), darf niemals > Proxy-URL re-resolen ohne Job.
- **DB:** Tabelle `crozztube_import_sources` (migrations idempotent, `media_url`, `variants` (JSON), `audio_url`, `expires_at`, `state`, `refresh_attempts`, …).
- **Core-Patches (drei, dokumentiert im Code als „CrozzTube-Patch“):**
  1. `app/Models/Video.php` → `getVideoUrlAttribute`: Resolver-URL vor Embed
  2. `app/Http/Resources/VideoResource.php` → `videoSources`: Resolver-Quelle (type `application/x-mpegurl` bei HLS) bevorzugt
  3. `app/Http/Controllers/Api/VideoController.php` → `import()`: dispatcht `ResolveImportSource` (Resolve beim Import, nicht lazy)

### Verifizierte Tests (Stand jetzt)
- Resolve-Job end-to-end auf YouTube-Video (video_id 1, „Rick Astley“, ~3:32): state=fresh, 8 Varianten, TTL ~6 h ✓
- Struktur + Parsing von Master-Manifest, Playlist-Rewrite, INIT/Video/Audio-Segment-Fetch durch den Proxy: HTTP 200, korrekte Größen, ~0,1–0,2 s ✓
- Watch-API liefert eigene Quelle statt Embed ✓
- Download-Endpoint: 409 + `downloadable:false` bei HLS-only ✓ (Button versteckt = Design-Entscheidung)
- **Vom User manuell bestätigt:** echtes YouTube-Video („Jägermeister vs seine billige Kopie vom Netto“) spielt zügig im UI ✓
- **NICHT getestet:** mehrstündiges Video, Live-Ablauf-Refresh im Browser, Mirror-Wechsel (existiert noch nicht), Download bei einem Direct-MP4-Import, Nacht-Job.

### Live-Stand der Instanz (verifiziert am 4. Nov 2026, 12:45)

Alle drei importierten Videos sind aktuell `fresh` (`kind=hls-synth`), Status-Endpoint antwortet ohne Auth, Worker-Queue ist **leer**:

| video_id | Inhalt | Länge | is_short | expires_at | Manifest | Segment-Test |
|---|---|---|---|---|---|---|
| 1 | Rick Astley – Never Gonna Give You Up | **NULL** (!) | **1 (falsch)** | 15:08 | 200, 8 Varianten | – |
| 2 | Jägermeister vs seine billige Kopie vom Netto | 543 s | 0 | 15:28 | 200, 6 Varianten | 200, 4,4 MB, 0,22 s |
| 3 | Arnold REJECTED Terminator … (`/shorts/Hrn2aF9K8Ig`) | 97 s | 1 | 15:36 | 200, 8 Varianten | 200, 7,4 MB, 0,41 s |

- **Short-Erkennung funktioniert:** Video 3 (97 s, `/shorts/`-URL) wurde als `is_short=1` erkannt und spielt über den Resolver (Manifest + Segment geprüft).
- **Mittellanges Video funktioniert:** Video 2 (9 Min.) liefert 6 Varianten + Segment.
- **BUG/Fund:** Video 1 (3:32) hat `duration_seconds = NULL` **und** `is_short = 1` → wird fälschlich als Short gelistet. Ursache: PlayHub-Import setzt Dauer/Short-Flag aus Provider-Metadaten, der Resolver schreibt `title`/`duration_seconds` nur in `crozztube_import_sources`, **nicht** zurück ins `videos`-Table. Fix-Option: im Resolve-Job `videos.duration_seconds` + `is_short` (Duration/Aspect) nachziehen, wenn dort NULL.
- `failed_jobs` enthält 4 historische Einträge (id 7: `yt-dlp: not found` vor der Installation; id 8/9: `Uh7ljxRNCzo` unavailable; id 10: transient „No play-as-direct source“ bei Video 1) — **nicht** aktuell relevant, Queue läuft leer. Bei Bedarf `php artisan queue:flush`.
- Stack läuft vollständig (web/worker/cron/mysql/redis/phpma/mailhog), `https://crozztube.local:8443` → HTTP 200.

### Wichtig für die UI-Arbeit: Frontend ist vorgebaut (kein Watch-Page-Quellcode!)

- `resources/js/` enthält **nur** `AdminDashboard.jsx`, `UploadPage.jsx`, `embed-player.jsx` — die Watch-Page/der Player liegen ausschließlich im kompilierten Bundle `build/assets/main-*.js` (+ `vidstack-*`). Ein React-Source-Patch der Watch-Page ist also **nicht** möglich.
- **Der vorgesehene Weg:** PlayHub-Plugins dürfen Frontend-Assets mitliefern. `app/Plugins/PluginManifest.php` akzeptiert `assets.styles` (`.css`) und `assets.scripts` (`.js`/`.mjs`); `resources/views/app.blade.php` (Z. 171–186) bindet sie als `<link>` bzw. `<script type="module">` nach dem Haupt-Bundle ein und setzt `window.__PLAYHUB_PLUGINS__`. Auslieferung über `/plugin-assets/{pluginId}?asset=…&v={version}`.
- **Konsequenz für TODO A/C:** eigene `assets/watch-enhancer.js` (+ CSS) im Plugin, per `MutationObserver`/Intervall auf der Watch-Route die Buttons (Mirror-Toggle, Download) und das Refresh-Banner in den DOM injizieren, Daten über die bestehenden JSON-Endpoints holen. Bundle-Patching wäre fragil und upgrade-unsicher → nicht machen.

## 3. Offene Aufgaben (priorisiert, mit Details)

### A. Mirror/Embed-Wechsel-Button unter dem Video (Höchste Prio, UI)
Der User will unter dem Player einen Button „Direkt-Video / YouTube-Mirror“ zum Umschalten:
- API-Seite: `VideoResource` liefert `embed_url` heute NUR, wenn keine Direkt-URL existiert. Ändern: `embed_url` **immer** mitliefern (wenn import+wiedergabefähig), damit das Frontend den Mirror jederzeit laden kann.
- Frontend **nicht** per React-Source (existiert nicht für die Watch-Page): als Plugin-Asset `assets/watch-enhancer.js` + `assets/watch-enhancer.css` ausliefern (siehe Abschnitt „Frontend ist vorgebaut“). Button unter dem Player per DOM-Injection, Toggle zwischen eigener Quelle ↔ `youtube/{id}`-Embed-Quelle (PlayHub-Player versteht `video/youtube`-Sources bereits). Auswahl im localStorage persistieren. Standard: Direktquelle.
- Wenn der Player bei der Direktquelle einen Fehler bekommt (Manifest 4xx/5xx, Segment-Reset-Loop) → automatisch Mirror anbieten/einschalten.

### B. Download-Button — Entscheidung nötig
- Heute: YouTube-Videos sind HLS-only → `downloadable=false` → Button versteckt (409). **Dokumentierte Spezifikation war „versteckt wenn nur HLS“.** Der User vermisst den Button aber — mit ihm klären:
  - Option 1 (Spez): versteckt bei HLS-only, sichtbar bei MP4-Quellen (z. B. Direct-Imports). Implementierung dafür ist fertig.
  - Option 2: dem User erklären, dass Download bei YouTube technisch nicht geht, ohne Re-Hosting (muxed MP4 existiert nicht mehr; ffmpeg-Konvertierung wäre Re-Transcode).
- Wenn er sich für Option 1 entscheidet: UI-Arbeit — `status.downloadable` ans Frontend geben und Button render/verstecken. TODO-UI: Button in Watch-Actions-Zeile (neben „Add to Watch later") mit `GET /api/source-resolver/download/{id}` verknüpfen.

### C. Auto-Refresh bei ungültiger/expired Direkt-URL (Frontend + kleine Backend-Feinschleife)
Serverseitig existiert das bereits (Job + Lock). **Im Frontend fehlt der ganze Flow** — umsetzen als `assets/watch-enhancer.js` (Plugin-Asset, siehe oben), der den Player beobachtet und das Banner in den DOM injiziert:
1. Player-Fehler auf Direktquelle (z. B. HTTP 403/404 auf Manifest/Segment, oder `expires_at`-Ablauf zu spät erkannt) feststellen (HLS.js error events bzw. fetch-Status).
2. `POST /api/source-resolver/refresh/{videoId}` — Single-Flight garantiert, dass nur EIN Job läuft, egal wie viele User gleichzeitig klicken.
3. Poll `GET /api/source-resolver/status/{videoId}` alle ~3–5 s. Währenddessen Banner: **„Videoquelle wird aktualisiert…“** (für alle wartenden User identisch, da gleicher State).
4. Wenn wieder `fresh`: Source sofort neu laden (HLS.js `loadSource` neu) — falls nicht möglich: Seite automatisch neu laden (z. B. `location.reload()` nach 2 Versuchen). Wenn kein automatischer Reload geht: Hinweis „bitte Seite einmal neu laden".
5. Wenn `error`: Mirror-Button anbieten + Hinweis, dass es weiterhin der YouTube-Embed funktioniert.
- Backend-Nitpick: Watch-API könnte bei `state=refreshing` einen kleinen `retry_after`-Hinweis mitgeben; außerdem prüfen ob der Worker bei `queue timeout` den Lock freigibt (LOCK_TTL 600 s läuft automatisch ab).

### D. Nachts-/Stagger-Jobs: Erreichbarkeit & proaktiver Refresh
- Scheduler existiert bereits (cron-Container fährt `php artisan schedule:run` jede Minute). Anlegen:
  1. **Proaktiver Refresher (stündlich oder alle 2 h):** alle `crozztube_import_sources` mit `expires_at < now + 60 min` → `RefreshSource` dispatchen, **max. 1–2 Jobs pro Minute** (etappenweise, Server nicht belasten; z. B. Command mit `--limit` und Dispatch-Guard).
  2. **Nachtliche Erreichbarkeits-Prüfung (z. B. 03:00):** alle imports in Batches prüfen — günstig: HEAD auf `media_url`/`variants`-URLs reicht in den meisten Fällen (CDN-URLs widerholden `expire=` schon in DB → Ablaufzeit ist schon da, siehe `ttlFromUrl`). Ungültige → `RefreshSource`. Fehler zählen, Dashboard/Admin-Notiz oder log.
- Achtung: yt-dlp-Auflösung pro Video ca. 2–6 s CPU/Netz — deshalb ETAPPENWEISE (Queue-Worker, 1 Job nach dem anderen, `sleep` im Command oder `default`-Queue mit Rate-Limit).

### E. Echte Tests, die noch fehlen (beide vom User explizit gefordert)
1. **Mehrstündiges Video importieren** (Podcast/Stream-Replay): bisher max. 9 Min. (Video 2) geprüft. Master-Manifest/Passthrough unter echten Bedingungen überprüfen (Buffering, Wiederaufnahme, lange Playlists).
2. **Short: serverseitig ✓** (Video 3, 97 s → `is_short=1`, Manifest + Segment HTTP 200). Offen: Browser-Darstellung in der Shorts-Ansicht + das falsche `is_short=1` bei Video 1 (siehe Live-Stand) fixen.
3. **Manuell ablaufen lassen:** `expires_at` in DB auf Vergangenheit setzen → Watch-Seite → Refresh-Flow (nach Aufgabe C) testen.
4. **Direct-MP4-Import** (echte progressive Quelle, z. B. direkt verlinkte MP4): Download-Button muss sichtbar sein und funktionieren.
5. **Mehrere User gleichzeitig** auf abgelaufene Quelle: nur ein Refresh-Job (Logs prüfen: `refresh_attempts` zählt nicht doppelt hoch).

## 4. Betriebs-KnowHow / Fallstricke

- **Plugin reinstall (nach Quellcode-Änderung):** ZIP bauen (`zip -rq /tmp/opencode/crozztube.source-resolver.zip .` aus plugin-src-Ordner), dann API-Zyklus: `POST /api/admin/plugins` (multipart `package`) → `POST /api/admin/plugins/crozztube.source-resolver/enable`. Danach **IMMER** `docker exec crozztube-web php artisan queue:restart` (Worker lädt Job-/Plugin-Klassen sonst nicht → `__PHP_Incomplete_Class`-Fehler der Jobs).
- **Throttle:** `POST /api/admin/plugins` hat `throttle:10,1` — bei mehrere reinstalls in Folge: 429, ~60 s warten. `enable` und Delete liefert 404 wenn install nicht durch ist.
- **DB:** `docker exec crozztube-mysql mysql -ucrozztube -pcrozztube-local-db crozztube …` (im Host-Netz Port 3308).
- **Settings-Dateien:** `storage/app/playhub-features.json` (uploadRoles=admin), `playhub-video-import.json` (direct+youtube AN, vimeo/dailymotion/twitch AUS), `playhub-live-streaming.json` (false).
- **Import-Provider live-Status:** importiert werden kann nur, was in `playhub-video-import.json` AN ist. Vimeo/Dailymotion/Twitch sind **deaktiviert, aber auskommentiert im Code** (re-enablebar).
- **LiveKit:** Runtime bewusst nicht installiert. Re-Enable-Pfad steht in `Wiki/konfiguration.html` §2.
- **Cache CLI-Checks:** Im Container: `php -r 'require "vendor/autoload.php"; require "bootstrap/app.php"; …'` (Autoloader VOR bootstrap laden!).
- **Token-Login für API-Tests:** `POST /api/auth/login` mit `{login,password,device_name}` → Bearer-Token. Ein frisches Token liegt ggf. in `/tmp/opencode/login.json` (verfällt nicht automatisch).
- **Symfony Process:** `->isSuccessful()` nicht `->successful()` (Fehler kostete 1 Version).
- **fopen + googlevideo hängt** → curl-based Passthrough (bereits umgesetzt). Nicht zurückändern.
- **Debug-Restdateien** wurden entfernt (`debug-resolver.php` etc.). Falls du welche findest: im Projekt-Root löschen, gehören nicht ins Repo.

## 5. Wichtige Pfade Übersicht

| Pfad | Inhalt |
|---|---|
| `docker/plugin-src/crozztube.source-resolver/` | Plugin-Quellcode (installierbar via ZIP); Frontend-Assets künftig unter `assets/` + `assets.scripts`/`assets.styles` in `plugin.json` |
| `docker/compose.yml`, `docker/Dockerfile` | Stack (Ports, yt-dlp-Fix, cron-Autoupdate) |
| `app/Models/Video.php`, `app/Http/Resources/VideoResource.php`, `app/Http/Controllers/Api/VideoController.php` | drei dokumentierte Core-Patches |
| `storage/app/playhub-*.json` | Settings (Import-Provider, Upload-Rollen, Live) |
| `Wiki/index.html` (+zugang/umgebung/konfiguration) | Klickbare Doku |
| `HANDOFF.md` (dieses Dokument) | Stand + TODOs |

## 6. Zielzustand (aus User-Anforderungen, unverändert gültig)

1. Nur Streaming, keine Uploads (admin-only via `uploadRoles`), keine API-Keys ✓ (Umsetzung steht)
2. Direkt-URL via yt-dlp = Pflicht-Primärpfad, eigener Player ✓ (v0.2.4, browserseitig vom User bestätigt)
3. Embed immer als Mirror-Fallback + **umschaltbarer Button unter dem Video** → TODO A
4. Auto-Refresh bei Ablauf/Abbruch, Warteschlangen-Banner für alle Nutzer, Reload wenn möglich → TODO C
5. Download-Button zwischen Spezifikation und Userwunsch klären (siehe TODO B)
6. Nachts gestaffelte Erreichbarkeits-/Refresh-Jobs ohne Serverlast → TODO D
