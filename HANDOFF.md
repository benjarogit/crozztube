# HANDOFF — CrozzTube Source-Resolver (Stand: 5. Nov 2026, v0.7.1)

> Weitergabedokument für den nächsten Agenten. Aktueller Zustand + offene Punkte.
> Wiki (Zugänge/Umgebung/Konfig/Tests): `Wiki/index.html` — zuerst lesen!

---

## Aktueller Nachtrag: responsive Oberfläche

Abnahme am 5. November 2026: vollständiges Harness 103 PASS / 0 FAIL / 0 SKIP. Alle 442 Responsive-Fälle in Chromium, Firefox und WebKit sowie die neun bisherigen Browserprüfungen sind grün. Wiedergabe/Seek laufen beim Resize weiter, Touch-Abbruch hinterlässt keine Sperren.

Navigation B umgesetzt: Desktopsidebar ab 1280 px, darunter Menü ohne reservierte Seitenbreite; untere Besuchernavigation bis 860 px. Inhaltscontainer, lesbare Raster, kompakte mobile Einstellungen/Verwaltungslisten, kurze Dialoge mit Fokus-/Scrollverwaltung und Resize ohne Verlust von Formular-/Playerzustand. Details und Testgrenzen: `Wiki/responsive.html`; gemeinsame Gestaltung: PRODUCT.md/DESIGN.md.

Impeccable 4.8.0 liegt projektlokal und versionsgebunden vor. Der responsive Test-Wrapper prüft Chromium/Firefox/WebKit mit einem Worker; bei fehlenden WebKit-Bibliotheken verwendet er einen automatisch wieder entfernten, begrenzten offiziellen Dockerbrowser. Echte Mobilgeräte und echter Browser-/OS-Zoom bleiben eine gesonderte Geräteprüfung.

## Vorheriger Nachtrag: v0.7.1

- Directupload-Formular: `POST https://www.directupload.eu/upload_you/`, Multipart-Felder `bilddatei`, `showtext=checkbox`, `Upload_file= Bild hochladen `. Original-Hotlink aus HTML-Input, ausschließlich `https://sN.directupload.eu/images/YYMMDD/datei.ext`, keine `/temp/`-Vorschau. Kein Login/Key im geprüften Ablauf. Keine offizielle API; geändertes HTML führt zum kontrollierten Fallback. Lokale Schutzbudgets 10/Minute und 300/Tag, keine behaupteten Anbieterlimits.
- Video ID 3 hat eine live verifizierte Directupload-Kopie (1280×720). Browser-Fallback durch Directupload → FastPic → Postimages → Original mit echten Bild-URLs bestätigt. Zusätzliche Bestandskopien bleiben für das Nachtfenster vorgemerkt.

Die neue Kurzreferenz steht in `AGENT-HANDOFF.md`, die vollständige Thumbnail-Konfiguration in `Wiki/thumbnails.html`.

- Externe Thumbnails mit Originalerhalt, RAM-Verarbeitung, Provider-Fallback und eigenem begrenztem Nacht-Worker sind umgesetzt. **Bildhoster-Keys sind lokal konfiguriert; echte FastPic- und Postimages-Uploads am 5. Nov 2026 erfolgreich geprüft (beide HTTP 200).** Directupload ist als Priorität 1 aktiv: öffentlicher Formularversand ohne Key, am 5. Nov 2026 im sichtbaren Browser und serverseitig erfolgreich geprüft (HTTP 200, Hotlink erreichbar); Postimages ist ausdrücklich eine vom Nutzer gewünschte inoffizielle Integration anhand ShareX/SMF.
- Video-Datei-Uploads sind auch für Admin gesperrt; URL-Import bleibt zugelassen. Die bestehenden Zugänge bleiben gültig. Eigene Video-Thumbnails werden per Raw-Body extern übertragen. Bei Ausfall keine lokale Zwischenablage.
- Shorts: <=180 s, quadratisch/hochkant; Maße gespeichert, Bestandsvideos kontrolliert per Resolver erneuert. Browser-Wiedergabe/Seek und Kapitelanzeige geprüft.
- Backfill schützt bewusste Änderungen, nutzt vorhandene Resolver-Metadaten, keine zweite Extraktion. Permanente und ausgeschöpfte unbekannte Fehler werden nicht ständig vom Scheduler neu angestoßen. Expliziter Retry bleibt möglich.
- Live-Browserprüfung: Directupload sichtbar; nach gezielten Sperren folgen FastPic, Postimages und das Original. Für Video ID 3 ist je Hoster eine geprüfte Kopie gespeichert; weitere Kopien laufen regulär nachts. Keys bleiben ausschließlich in `.env`.
- Aktuell drei echte Videos, Quellen frisch. Veraltetes Direct-MP4-Testsample ID 4 entfernt. Langvideo-Test benötigt weiterhin eine Nutzer-URL.
- Vollständiges Harness: 103 PASS, 0 FAIL, 0 SKIP; Thumbnail-Suite mit 91 PHP-Einzelprüfungen; 9 Wiedergabe-/Resolver-Browsertests und 442 responsive Browserfälle grün. Vollständiger Befehl: `./tools/test/run.sh all`.

## 1. Projekt & Umgebung

- **Projekt:** CrozzTube = PlayHub web v0.9.13 (Laravel 13, React 19), lokale Instanz.
- **Konzept:** NUR Streaming/Import. Keine eigenen Uploads (Nutzer/Creator laden nichts hoch), **keine Video-API-Keys** (Bildhoster mit eigenen lokalen Keys). **yt-dlp ist die zentrale Video-Engine**: Metadaten (Titel, Beschreibung, Tags, Thumbnail, Dauer, Kapitel) beim Import und Direkt-URL (eigener Player, HLS bevorzugt) für die Wiedergabe. Der Provider-Embed (YouTube etc.) ist nur Mirror/Fallback.
- **URL:** `https://crozztube.local:8443` (self-signed) · Admin: `benny` / `meZVRW0bYEbpHsJc`
- **Docker-Stack** (`docker/compose.yml`, Projekt `crozztube-local`): crozztube-web (PHP 8.3 + FFmpeg + yt-dlp 2026.10.26), -mysql (Port 3308! User crozztube/crozztube-local-db), -redis (Pass crozztube-local-redis), -worker (`queue:work videos,maintenance,default`), -cron (schedule:run je Minute + täglicher yt-dlp-Auto-Update), -phpmyadmin (8083), -mailhog.
- **NICHT anfassen:** Container `crozztube-audit-*` (separate Audit-Instanz unter /home/benny/audit-instance).
- **Git-Backup:** `https://github.com/benjarogit/crozztube` (privat). Regelmäßig committen + pushen.

## 2. Was fertig & verifiziert ist

### Plugin `crozztube.source-resolver` v0.7.1 (installiert + aktiv)
Quelle: `docker/plugin-src/crozztube.source-resolver/` (dort liegt der Code, installiert wird via ZIP-Upload an die Admin-API; Hilfsskript `tools/dev/reinstall-plugin.sh`).

- **Resolver (`src/Resolver.php`):** yt-dlp `-J` keyless. Auswahllogik: echtes HLS-Manifest → sonst muxed MP4 → sonst **"hls-synth"** (YouTube-Sonderfall) → sonst Fehler.
- **Direkt verlinkte Dateien (Direct-URL-Provider):** yt-dlp meldet beim Generic-Extractor weder `vcodec`/`acodec` noch eine Höhe. Der Resolver akzeptiert solche vollständigen Dateien (mp4/webm/m4v/mov) als progressive Quelle (`kind=mp4`), TTL 24 h und liest die Dauer per `ffprobe` nach. `media_url` bleibt die Original-URL — nichts wird heruntergeladen oder re-gehostet.
- **yt-dlp als Metadaten-Engine (v0.6.0):** `App\Support\YtDlp` (`-J`, keyless, 10-min-Cache) liefert beim Import Titel/Beschreibung/Tags/Thumbnail/Dauer/Kapitel/Autor. Der Provider `direct` heißt jetzt „Video URL (direct or yt-dlp)“ und akzeptiert **jede von yt-dlp verstandene URL** (TikTok, X, Reddit, SoundCloud …), nicht nur MP4-Dateien. Provider-spezifische APIs/HTML-Metadaten haben Vorrang, yt-dlp füllt Lücken. Der Resolver-Job wird auch für `direct` sofort dispatcht; `Video::getVideoUrlAttribute` bevorzugt für `direct` die aufgelöste URL und fällt auf die Roh-URL zurück.
- **Health-Endpoint:** `GET /api/source-resolver/health` liefert Plugin-Version/Aktivstatus, yt-dlp- und ffprobe-Version (aus denselben Binaries wie der Resolver), Quellenzahlen pro State und den letzten erfolgreichen Resolve. Keine Secrets, eigener Throttle.
- **Retry-Resilienz:** `BaseSourceJob` hat für transiente Fehler `$tries = 4`, Backoff `20/90/240 s` und `failed()`, das den Zustand sauber auf `error` setzt (Lock frei, Mirror-Fallback sichtbar). Transiente CDN-/DNS-Fehler werden damit automatisch wiederholt.
- **YouTube-Sonderfall (wichtig!):** YouTube liefert seit SABR kein muxed MP4 und kein Master-Manifest mehr. Lösung: itags 233/234 sind versteckte **HLS-Audio-Playlists** (AAC-LC, geprüft via ffprobe). Das Plugin speichert bis zu 8 Video-Playlists (144p–2160p, AVC/VP9, deduped pro Höhe) + 1 Audio-Playlist in der DB (`variants`, `audio_url`) und baut ein **synthetisches HLS-Master-Manifest**.
- **Metadaten-Backfill (v0.7.0):** `ImportMetadataBackfill` ergänzt fehlende Dauer, Maße, Titel/Beschreibung/Tags/Thumbnail/Kapitel aus der vorhandenen Resolver-Extraktion. Bewusste Änderungen bleiben erhalten; Shorts werden aus gemessenen Maßen und <=180 s bestimmt, nicht mehr über URL-Pfade.
- **Passthrough-Proxy absichtlich!** googlevideo sendet kein `Access-Control-Allow-Origin`, HLS.js braucht daher einen Same-Origin-Pfad. Der Proxy streamt nur durch (curl-basiert), speichert **nichts** (kein Re-Hosting). Host-Allowlist: nur `*.googlevideo.com`.

### Provider-neutraler Mirror-Umschalter (v0.3.x)
- `src/Support/Mirror.php` erzeugt serverseitig den Mirror-Payload: `provider`, `label`, `mode` (`iframe` | `link`), `embed_url`, `external_url`. Das Frontend enthält **keine Provider-Logik** — YouTube, Vimeo, Dailymotion, Twitch und direkte Datei-URLs funktionieren über dieselbe Struktur.
- `embed_url` im `VideoResource` wird für YouTube **nicht** gesetzt (würde die Direktquelle verdrängen). Der Mirror kommt ausschließlich aus dem Plugin-Payload.
- Frontend: `assets/watch-enhancer.js|css` (Plugin-Assets). Button in `.watch-toolbar .action-bar`, Overlay im `.watch-player-stage`. Auswahl pro Video in `localStorage['crz.mirror.prefs']`.
- CSS setzt `.watch-player-stage { position: relative; }` — PlayHub positioniert die Stage nicht, sonst deckt das absolute Overlay die Toolbar ab.
- SPA-Navigation: Enhancer baut Overlay/Button/Banner bei Routenwechsel selbst ab.

### Auto-Refresh abgelaufener Direkt-URLs (v0.4/0.5)
- **Serverseitig:** `BaseSourceJob` mit Cache-Lock (600 s) + DB-State `refreshing`. `force` überspringt die „ist noch frisch“-Prüfung; **explizite/geforce Refreshs umgehen den 30-s-Debounce** (Job-Lock verhindert Doppelarbeit) — sonst verschluckt der Debounce Retry/Expiry-Läufe.
- **Single-Flight über mehrere Nutzer:** Jeder Job trägt den Anforderungszeitpunkt (`requestedAt`). Wurde die Quelle **nach** diesem Zeitpunkt bereits von einem anderen Job erneuert, steigt der Job aus — auch mit `force`. Fünf gleichzeitige Refresh-Requests erzeugen damit genau **einen** yt-dlp-Lauf (Harness-getestet).
- `BaseSourceJob::request()` markiert abgelaufene Quellen als `stale` und liefert `bool` (ob wirklich eingereiht). Hängende `refreshing`-Zustände (Worker-Crash) werden nach `LOCK_TTL - 60 s` ignoriert/normalisiert.
- **Status-Payload:** `state`, `kind`, `fresh`, `has_direct`, `refreshing`, `error_message`, `downloadable`, `expires_at`, `expires_in`, `can_refresh`, `retry_after`, `refresh_started_at`, `mirror`.
- **Frontend (`watch-enhancer.js`):** Banner („wird erneuert“ / Fehler + Retry + Mirror), Polling, einmaliger Auto-Reload nach frischer URL (max. 2 Versuche, Resume-Position in `sessionStorage`), Mirror-Fallback bei hartem Fehler. Auto-Refresh triggert nur bei vorhandener, abgelaufener Direktquelle — kein Endlos-yt-dlp bei kaputtem Import.
- **Sicherheitsnetze ohne JS:** `manifest`-Route stößt bei abgelaufener Quelle einen Refresh an und liefert das aktuelle Manifest weiter; `playlist`-Route triggert bei CDN-Fehler einen Refresh (Video-ID wird durch das synthetische Manifest gereicht).

### Wartungs-Jobs (eingeführt v0.5.0)
- `crozztube:sources:refresh-expiring` — alle 5 Min, Optionen `--window=15 --limit=10 --stagger=15 --scheduled --dry-run`. Erneuert Quellen kurz vor Ablauf, gestaffelt (Delay pro Dispatch), Queue `maintenance`.
- `crozztube:sources:probe` — stündlich zwischen 02:00–05:00, Optionen `--limit=20 --min-age=60 --stagger=10 --scheduled --dry-run`. HEAD-Check, Fallback 1-Byte-Range-GET, schreibt `last_checked_at`/`last_check_status`; defekte URLs → `stale` + Refresh.
- Beide respektieren die Pause-Datei `storage/framework/crozztube-maintenance.pause` (nur mit `--scheduled`, damit manuelle Läufe immer funktionieren). Das Test-Harness setzt sie automatisch.
- Migration `2026_11_04_000003_add_probe_fields_…` (Probe-Spalten + Index auf `expires_at`).

### Test-Harness (vollständig agent-ausführbar)
```bash
./tools/test/run.sh all          # alles: 102 Harness-Checks + 9 Playwright-Tests
./tools/test/run.sh list         # Suiten anzeigen
./tools/test/run.sh refresh jobs # gezielt
SLOW=0 ./tools/test/run.sh jobs  # ohne yt-dlp-Roundtrip
```
Suiten: `stack`, `api`, `media`, `direct`, `refresh`, `mirror`, `jobs`, `ui`. Details: `Wiki/tests.html`.
Letzter vollständiger Lauf: **102 Harness-Checks + 9 Browser-Tests grün**.

### Commits
| Commit | Inhalt |
|---|---|
| `98eb7e5` | Baseline PlayHub 0.9.13 + Resolver v0.2.4 |
| `0f09a40` | Test-Harness (stack, api, media) |
| `6e6a701` | Provider-neutraler Mirror + UI-Suite |
| `0a65ed8` | Auto-Refresh + Wartungs-Jobs + refresh/jobs-Suiten |
| `4e1e06f` | Doku v0.5.0 (HANDOFF, Wiki Konfiguration/Tests) |
| `f57e566` | Single-Flight über mehrere Nutzer (v0.5.1) |
| `0774175` | Doku-Nachtrag v0.5.1 |
| `16521f2` | Direct-MP4-Fix + `direct`-Suite (v0.5.2) |
| `5f973db` | ffprobe-Dauer für direkte Dateien (v0.5.3) |
| `7504660` | yt-dlp-Metadaten-Engine, generische URLs, Health, Retry (v0.6.0) |

### Live-Stand der Instanz
- Drei echte importierte Videos, alle `fresh`; Direct-MP4-Testsample ID 4 wurde entfernt. Die echten YouTube-Imports: `kind=hls-synth`, Manifeste HTTP 200 (6–8 Varianten), Segment-Passthrough geprüft.
- `failed_jobs` enthält historische Einträge (yt-dlp not found, unavailable IDs, transient) — nicht aktuell relevant, Queue läuft leer.
- Stack läuft vollständig, `https://crozztube.local:8443` → HTTP 200.

## 3. Architektur-Entscheidungen (nicht zurückdrehen)

1. **Frontend-Quellen vorhanden.** Watch/Shorts liegen in `resources/js/main.jsx`; Core-Änderungen mit `npm run build` ausliefern. Resolver-Mirror/Banner bleiben Plugin-Assets (`plugin.json → assets.scripts/styles`). Die frühere Aussage, dass Watch nur im Bundle existiert, war falsch.
2. **Mirror ist provider-neutral.** Neue Provider nur in `Mirror::forVideo()` ergänzen; Frontend bleibt unverändert.
3. **Kein Download-UI.** Der Endpoint `/download/{id}` bleibt als Passthrough bestehen, wird aber von der UI nicht mehr angeboten (Entscheidung des Users). Kein Re-Mux/Transcode.
4. **Status per Slug.** `/api/source-resolver/status/slug/{slug}` — die Watch-Page braucht genau einen Request, keinen zusätzlichen `/api/videos/{slug}`-Call.
5. **Eigene Throttle-Präfixe pro Route.** Laravel keyt Limits sonst nur nach IP → Segment-Traffic würde `/status` blockieren und die UI still kaputt machen.
6. **Zeitstempel aus PHP, kein SQL `NOW()`** in Plugin-Code/Tests (siehe Fallstricke).
7. **`force` umgeht den Debounce**, nicht den Lock.

## 4. Offene Punkte

### E1. Mehrstündiges Video (Podcast/Stream-Replay)
Bisher max. 9 Min. geprüft. Master-Manifest, Playlist-Länge, Buffering und Resume unter echten Bedingungen testen.

### E2. ~~Mehrere Nutzer gleichzeitig auf abgelaufener Quelle~~ ✓
Erledigt: `requestedAt`-Koaleszenz im Job + Harness-Check (5 parallele Refresh-Requests → `refresh_attempts` steigt um genau 1).

### E3. ~~Direct-MP4-Import im Browser~~ ✓
Erledigt: `direct`-Suite importiert eine progressive MP4, prüft `kind=mp4`, `downloadable`, Mirror `link`, Watch-Payload und Range-Response des Originals. Browser-Check manuell bestätigt (30,5 s Sample, `readyState=4`, Mirror-Link „Original“).

### E4. Shorts-Darstellung
Serverseitig korrekt (`is_short`, Dauer). Erledigt in v0.7.0: Darstellung, laufende HLS-Wiedergabe und Seek per Playwright geprüft.

### E5. Doku-Pflege
Bei jeder Plugin-Version `plugin.json`-Version, Wiki und dieses Dokument mitziehen.

### E6. yt-dlp-Ausbau (Roadmap)
- **Erledigt (v0.6.0):** yt-dlp ist Metadaten-Engine für Importe; `direct` akzeptiert beliebige yt-dlp-URLs; Health-Endpoint; Retry/Backoff. Manuell verifiziert: YouTube-URL über `direct` importiert → Titel/Dauer aus yt-dlp, `hls-synth`, Mirror „Original“, Testvideo danach gelöscht.
- **Metadaten-Backfill beim Refresh:** Erledigt in v0.7.0; vorhandene Resolver-Metadaten werden ohne zusätzliche Extraktion genutzt.
- **Kapitel:** yt-dlp-Kapitel landen in `chapters`; Darstellung und Kapitelsprung in v0.7.0 per Playwright geprüft.
- **Frontend:** Import-Dialog liest Label/Help aus der API — kein Rebuild nötig. Admin-System-Settings verwenden seit v0.7.0 dieselben Provider-Definitionen wie der Importdialog.
- **Fehlerklassifikation:** Erledigt in v0.7.0: permanent ohne automatisches Retry, transient bis vier Versuche, unbekannt höchstens zwei; persistenter Stopp verhindert Scheduler-Schleifen.
- **Auth/Geo:** Twitch/Instagram o. Ä. brauchen ggf. Cookies oder Login; vor Aktivierung klären.

## 5. Betriebs-KnowHow / Fallstricke

- **Plugin reinstall:** `./tools/dev/reinstall-plugin.sh` (baut ZIP, disable → install → enable → `queue:restart`). Manuell: `POST /api/admin/plugins` (multipart `package`) → `enable`; **immer** `docker exec crozztube-web php artisan queue:restart`, sonst `__PHP_Incomplete_Class` in Jobs.
- **Nach Host-Reboot:** `web`/`worker`/`cron` können mit Exit 127 scheitern (Bind-Mount-Fehler am externen Laufwerk). Fix: `docker compose -f docker/compose.yml --profile runtime up -d web worker cron`. Danach laufen die Wartungs-Jobs und erneuern abgelaufene URLs automatisch.
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

1. Nur Streaming, keine Video-Datei-Uploads (auch nicht Admin), keine Video-API-Keys; Bildhoster mit eigenen lokalen Keys ✓
2. Direkt-URL via yt-dlp = Pflicht-Primärpfad, eigener Player ✓
3. Embed als Mirror-Fallback + umschaltbarer Button unter dem Video ✓ (provider-neutral)
4. Auto-Refresh bei Ablauf/Abbruch, Banner für alle Nutzer, Reload wenn möglich ✓
5. Kein Download-Button in der UI ✓ (Endpoint bleibt)
6. Gestaffelte Nacht-/Expiry-Jobs ohne Serverlast ✓
7. Test-Harness, das der Agent selbst komplett ausführen kann ✓
