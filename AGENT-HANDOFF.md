# CrozzTube — Agent-Handoff (Stand: 5. Nov 2026)

> Kompakte Übergabe für den nächsten Agenten. Ausführliche Historie:
> `HANDOFF.md` · Wiki (klickbar): `Wiki/index.html` · Tests: `Wiki/tests.html`

---

## 1. Auftrag & Konzept

CrozzTube ist eine **Streaming-Only**-Instanz von PlayHub 0.9.13.

- **Keine Video-Datei-Uploads** (auch nicht Admin), keine Video-API-Keys. Bildhoster nutzen eigene lokal hinterlegte Keys.
- **yt-dlp ist die zentrale Video-Engine**: Metadaten beim Import + Direkt-URL für die Wiedergabe.
- **Direkt-URL ist der Primärpfad** (HLS bevorzugt, MP4-Fallback, eigener Player).
- **Provider-Embed ist nur Mirror/Fallback** (provider-neutral umschaltbar).
- **Kein Download-Button in der UI** (Endpoint existiert als Passthrough, wird nicht angeboten).
- Abgelaufene URLs werden automatisch erneuert (Single-Flight, Banner, Auto-Reload).
- Vimeo/Dailymotion/Twitch sind deaktiviert; LiveKit ist aus (Re-Enable dokumentiert).

---

## 2. Zugang & Umgebung

| Sache | Wert |
|---|---|
| URL | `https://crozztube.local:8443` (self-signed) |
| Admin | `benny` / `meZVRW0bYEbpHsJc` |
| Projekt | `/mnt/intern-1tb/Projekte - Vibe Coding/crozztube` |
| Repo | `https://github.com/benjarogit/crozztube` (privat), Branch `main` |
| Docker-Projekt | `crozztube-local`, Compose: `docker/compose.yml` |
| MySQL | `docker exec crozztube-mysql mysql -ucrozztube -pcrozztube-local-db crozztube` (Host-Port 3308) |
| phpMyAdmin | `http://127.0.0.1:8083` |
| MailHog | `http://127.0.0.1:8026` |

**Container:** `crozztube-web` (PHP 8.3 + FFmpeg + yt-dlp), `-mysql`, `-redis`, `-worker`, `-cron`, `-phpmyadmin`, `-mailhog`.
**Nicht anfassen:** `crozztube-audit-*` (separate Audit-Instanz).

Falls nach einem Host-Reboot `web`/`worker`/`cron` mit Exit 127 stehen (Bind-Mount-Fehler):

```bash
cd "/mnt/intern-1tb/Projekte - Vibe Coding/crozztube"
docker compose -f docker/compose.yml --profile runtime up -d web worker cron thumbnail-worker
```

---

## Responsive Oberfläche (5. November 2026)

- Verbindliche Gestaltung: Navigation B. Ab 1280 px gedockte Seitenleiste, darunter Overlay-Menü ohne Inhaltsabstand; Besucher-Bottom-Nav bis 860. Desktoppräferenz bleibt getrennt. `resources/js/responsiveLayout.js` besitzt Grenzen, Shells geben `data-navigation-mode` aus.
- `responsive-layout.css` besitzt die öffentliche Geometrie; `responsive-content.css` Inhaltscontainer/240px-Videoraster/max1680, mobile Home-Karte, Querformat-Player und lokale Filter-Scroller. Überholte Sidebar- und Rasterregeln aus styles.css entfernt.
- `ResponsiveOverlays.jsx` koordiniert Fokus/Tab/Escape/Fokusrückgabe, inert-Hintergrund und Locks; `OverlayPortal` erhält Admin-Theme außerhalb geclippter Container. Dialoge passen in den sichtbaren Viewport, einschließlich kurzer Fenster. Unter900pxInhaltsbreite kompakte Settings-Auswahl, unter 1000 px Verwaltungskarten.
- Bestehende Miniplayer-Sitzungen überstehen Resize bis 320 px mit unverändertem MediaPlayer; mobile Aktionen 44 px. Neue Miniplayer-Sitzungen werden weiterhin über die Desktop-Bedienung gestartet.
- Impeccable 4.8.0 projektlokal gepinnt unter `.agents/skills/impeccable`, Upstreamcommit c8891a9ae865d6ee311836ae5199bfce0fe115b1. PRODUCT.md/DESIGN.md lesen. Keine globalen Hooks installiert; `scripts/impeccable detect` ist lokal ausführbar.
- Neue Harness-Suite `responsive`: **442 Fälle grün**: 100 Route-/Zustandsvarianten × 3 Chromiumbreiten, Inventarprüfung, 9 Interaktionen und je 66 Firefox-/WebKit-Fälle. Fixtures speichern keine API-Inhalte/Schlüssel, faken Queues und rollen GET-DB-Änderungen zurück; Browser-Schreibanfragen/Medien gesperrt. Ein Worker.
- WebKit kann lokal Bibliotheken vermissen. `npm run test:responsive` / Harness nutzen dann automatisch einen eigenen offiziellen Dockerbrowser (1 CPU / 1 GiB, read-only Projektmount, eigene Aufräumlogik). Bestehender Server über PW_WEBKIT_WS_ENDPOINT. Keine dauerhaften Testcontainer stehen lassen.
- Build: `npm run build -- --emptyOutDir=false` erhält ältere Chunks für offene Tabs. Keine Plugin-Neuinstallation für reine Frontendänderungen nötig. Kuratierte Vorher-/Nachherbilder: `/tmp/crozztube-tests/responsive-screenshots/`; Wiki: `Wiki/responsive.html`.
- Nachweisgrenze: Browser-Engines/Viewport-/synthetische Touchtests sind keine physischen iPhone-/Android-Tests. 200%-Reflow entspricht halbierter CSS-Breite; echter OS-/Browserzoom und echte Bildschirmtastaturen gesondert offen kennzeichnen.

## 3. Aktueller Stand

- **Plugin `crozztube.source-resolver` v0.7.1** installiert und aktiv.
- **yt-dlp 2026.10.26**, ffprobe 5.1.9 im Web-Image; cron aktualisiert yt-dlp täglich.
- **Vollständiges Harness: 103 PASS, 0 FAIL, 0 SKIP. Darin Thumbnail-Suite mit 91 PHP-Einzelprüfungen, 9 Wiedergabe-/Resolver-Browsertests und 442 responsive Browserfälle grün** (5. November 2026).
- **3 Videos**, alle Quellen `fresh`: ID 1–3, echte YouTube-Imports (`kind=hls-synth`).
- ID 4 (veraltetes Direct-MP4-Testsample) wurde entfernt; die Direct-Suite verwendet einen temporären MDN-Import.
- Auto-Refresh hat nach längerer Stack-Downtime alle abgelaufenen Quellen wieder erneuert.
- Arbeitsbaum sauber, `main...origin/main`.

Letzte Commits:

| Commit | Inhalt |
|---|---|
| `7504660` | yt-dlp-Metadaten-Engine, generische URLs, Health, Retry (v0.6.0) |
| `32175ad` | Doku + yt-dlp-Roadmap |
| `0464621` | Doku v0.5.3 |
| `5f973db` | ffprobe-Dauer für direkte Dateien |

---

## 4. Architektur (kurz)

### yt-dlp
- **Core:** `app/Support/YtDlp.php` — `-J`, keyless, 10-min-Cache. Liefert Titel, Beschreibung, Tags, Thumbnail, Dauer, Kapitel, Autor, Maße.
- **Plugin:** `docker/plugin-src/crozztube.source-resolver/src/Resolver.php` — löst die abspielbare Direkt-URL (HLS-Manifest → muxed MP4 → synthetisches HLS für YouTube → Fehler).

### Import
- `POST /api/video-imports` (`VideoController::import`).
- Provider `direct` heißt in der API **„Video URL (direct or yt-dlp)“** und akzeptiert **jede von yt-dlp verstandene URL** (MP4/WebM, YouTube, TikTok, X, Reddit, SoundCloud …).
- Provider-spezifische Metadaten (YouTube-Seite/API, oEmbed) haben Vorrang; yt-dlp füllt Lücken.
- Nach dem Import wird `ResolveImportSource` dispatcht (auch für `direct`).

### Wiedergabe
- `VideoResource::videoSources()` bevorzugt die Resolver-Quelle.
- `Video::getVideoUrlAttribute()` bevorzugt für `direct` ebenfalls die aufgelöste URL, mit Fallback auf die Roh-URL.
- YouTube-Sonderfall: Plugin baut ein **synthetisches HLS-Master-Manifest** und proxied `*.googlevideo.com` same-origin (kein Re-Hosting, kein Speichern).

### Mirror
- `src/Support/Mirror.php` liefert `mode: iframe|link`, `embed_url`, `external_url`, `label`.
- Frontend enthält **keine Provider-Logik**; Assets: `assets/watch-enhancer.js|css`.
- Overlay sitzt in `.watch-player-stage`; Stage bekommt `position: relative`.

### Refresh & Jobs
- `BaseSourceJob`: Cache-Lock (600 s), DB-State `refreshing`, `force`, `requestedAt`-Koaleszenz, Retry (4 Versuche, Backoff 20/90/240 s), sauberer `error`-Zustand.
- Status: `GET /api/source-resolver/status/slug/{slug}`.
- Health: `GET /api/source-resolver/health` (Plugin-/yt-dlp-/ffprobe-Version, Quellenzahlen).
- `crozztube:sources:refresh-expiring` (alle 5 Min, Queue `maintenance`).
- `crozztube:sources:probe` (02:00–05:00, HEAD/1-Byte-Range).
- Beide respektieren `storage/framework/crozztube-maintenance.pause` (nur mit `--scheduled`).

### Kern-Patches (nicht überschreiben)
1. `app/Models/Video.php` → `getVideoUrlAttribute`
2. `app/Http/Resources/VideoResource.php` → `videoSources`
3. `app/Http/Controllers/Api/VideoController.php` → Import-Dispatch + yt-dlp-Metadaten
4. `app/Support/VideoImportSettings.php` → generischer `direct`-Provider, `downloaderCommand()`

---


### Thumbnails und Shorts (v0.7.1)
- Core: `app/Support/Thumbnails/`, Konfiguration `config/thumbnails.php`. Kein Bild-/Video-Dateispeicher für diese Workflows; Thumbnail-Bytes maximal 5 MiB im RAM. PHP-Body-Spooling liegt auf `/dev/shm`.
- Original-URL bleibt in `videos.original_thumbnail_url` (TEXT). Aktuelle Quelle und Version separat; `thumbnail_mirrors` enthält Kopien und den Original-Prüfstatus, `thumbnail_providers` globale Ausfälle. Inhaltshashes vermeiden erneute Uploads bei geänderten signierten URLs.
- Reihenfolge: Directupload (öffentlicher Formular-Adapter, ohne Key) → FastPic → Postimages → Original → UI-Platzhalter. `FASTPIC_API_KEY` und `POSTIMAGES_API_KEY` in `.env`; **beide lokal hinterlegt; echte Uploads am 5. Nov 2026 erfolgreich geprüft (je HTTP 200, direkte Bild-URL erreichbar)**. Postimages verwendet den vom Nutzer freigegebenen inoffiziellen ShareX-Weg mit eigenem Key und XML-Hotlink.
- Beim Import: erste erfolgreiche Kopie innerhalb insgesamt 10 s; keine weiteren Kopien tagsüber. Kein verfügbarer Anbieter/Fehler/Lock: Original bleibt aktiv. Eigene Bilder via Raw-Body `PUT /api/videos/{slug}/thumbnail`, keine Multipart-Datei; bei Totalausfall bisheriges Bild/Platzhalter, keine Bytes für spätere Jobs speichern. Native API verwendet denselben Service (Raw-Body POST).
- Browser bekommt `thumbnail_candidates`; Fallback benötigt keine Backend-Anfrage. Private Video-Thumbnails sind absichtlich extern abrufbar.
- `MaintainThumbnails` auf eigener Queue `thumbnails`, eigener Container `crozztube-thumbnail-worker` (1 Worker, 0.25 CPU, 256 MiB). Nur 02:00 bis **vor** 05:00 Europe/Berlin; auch Jobs prüfen das Fenster. Alle 5 Min maximal 20 Prüfungen, 5 Kopierversuche, 60 s. Eine globale Transfersperre schützt zusätzlich gleichzeitige Importe.
- Gesunde Bilder nach 7 Tagen; Fehler nächstes Nachtfenster, dann Backoff bis 7 Tage. Drei verschiedene Netzwerk-/Serverfehler öffnen den Hoster-Circuit für 30 Min; danach eine Probe. 404 löst keine globale Sperre aus. Bestehende lokale Dateien erst nach verifizierter Kopie löschen.
- Status/Rückstand: `docker exec crozztube-web php artisan crozztube:thumbnails:status`. Wartung respektiert die bestehende Pause-Datei. Lokale Upload-Budgets und Provider-Konfiguration siehe `Wiki/thumbnails.html`.
- Shorts: <=180 s, positive Maße, Höhe >= Breite. Maße werden beim Import/Refresh gemessen und gespeichert; SQL-Feeds und API stimmen überein. HLS nutzt auch in Shorts Vidstack.
- E6: Resolver-Metadaten ohne zweiten yt-dlp-Aufruf nachziehen; Nutzereingaben/geleerte Felder und deaktivierte Kapitel erhalten. Permanente und ausgeschöpfte unbekannte Fehler stoppen automatische Refreshs dauerhaft; Retry-Button kann explizit neu starten.
- **Korrektur zur alten Übergabe:** Watch-/Shorts-React-Quellcode ist in `resources/js/main.jsx` vorhanden. Änderungen dort erfordern `npm run build`; Resolver-Mirror/Banner bleiben Plugin-Assets. Alte Vite-Chunks behalten (offene Tabs).

---

## 5. Wichtige Befehle

```bash
cd "/mnt/intern-1tb/Projekte - Vibe Coding/crozztube"

# Komplettes Harness
./tools/test/run.sh all                 # 102 Harness-Checks + 9 Browser-Tests
./tools/test/run.sh api direct          # gezielt
SLOW=0 ./tools/test/run.sh jobs         # ohne yt-dlp-Roundtrip
UI_SKIP=1 ./tools/test/run.sh all       # ohne Browser
KEEP_TEST_VIDEOS=1 ./tools/test/run.sh api

# Plugin neu bauen + installieren (ZIP, disable/install/enable, queue:restart)
./tools/dev/reinstall-plugin.sh

# Queue neu starten (nach Plugin-Änderungen Pflicht)
docker exec crozztube-web php artisan queue:restart

# Stuck refresh zurücksetzen
docker exec crozztube-web php artisan tinker --execute="CrzSourceResolver\Jobs\BaseSourceJob::recoverStale(<videoId>);"

# Health
curl -sk https://crozztube.local:8443/api/source-resolver/health | jq

# Logs
docker logs --tail 50 crozztube-worker
docker logs --tail 50 crozztube-web

# Git
git add -A && git commit -m "..." && git push origin HEAD
```

---

## 6. Wo der Code liegt

| Pfad | Inhalt |
|---|---|
| `docker/plugin-src/crozztube.source-resolver/` | **Quelle** des Plugins (installierbar via ZIP) |
| `storage/app/plugins/crozztube.source-resolver/` | **Runtime-Kopie** (nicht direkt editieren) |
| `…/src/Resolver.php` | yt-dlp-Aufruf, Format-Auswahl, ffprobe |
| `…/src/Jobs/BaseSourceJob.php` | Lock, Debounce, Retry, `requestedAt` |
| `…/src/ResolverController.php` | Status, Refresh, Manifest/Playlist, Health |
| `…/src/Support/Mirror.php` | provider-neutraler Mirror |
| `…/assets/watch-enhancer.js|css` | Banner, Polling, Auto-Reload, Mirror-Toggle |
| `app/Support/YtDlp.php` | zentrale Metadaten-Engine |
| `app/Support/VideoImportSettings.php` | Provider-Definitionen |
| `tools/test/` | Harness + Playwright |
| `tools/dev/reinstall-plugin.sh` | Plugin-Deploy |
| `HANDOFF.md`, `Wiki/` | ausführliche Doku |

---

## 7. Fallstricke

- **Plugin-Update:** nur `disable` → ZIP-Upload → `enable` → `queue:restart`. Sonst `__PHP_Incomplete_Class` in Jobs.
- **plugin.json:** `capabilities[]` max. **80 Zeichen**, sonst schlägt die Installation fehl.
- **Zeitfalle:** App = `Europe/Berlin`, MySQL-Session = UTC → **kein SQL `NOW()`** in Plugin-Code/Tests. Zeitstempel aus PHP oder feste Werte.
- **Cache-Store:** `database`, Prefix `crozztube_cache_`. Für Tests ggf. `Cache::forget('source-resolver:req.{id}')`.
- **Throttle:** jede Plugin-Route hat ein eigenes Präfix; `POST /api/admin/plugins` limitiert auf 10/min (bei Reinstalls ggf. 60 s warten).
- **Symfony Process:** `isSuccessful()`, nicht `successful()`.
- **googlevideo + fopen hängt** → curl-Passthrough nicht zurückändern.
- **Scheduler pausieren:** `touch storage/framework/crozztube-maintenance.pause`.
- **Playwright:** Token via `localStorage['playhub_token']`; `npx playwright test` in `tools/test/ui`.

---

## 8. Offene Aufgaben

### E1. Mehrstündiges Video
Bisher max. ~9 Min. getestet. Master-Manifest, Playlist-Länge, Buffering und Resume mit einem mehrstündigen Video prüfen. **User muss eine URL liefern.**

### E4 / E6 / Aufräumen
Erledigt: Shorts-Browsercheck inkl. Wiedergabe/Seek, einheitliche 180-s-Regel, Kapitelanzeige/Kapitelsprung, Metadaten-Backfill, Fehlerklassifikation, Admin-Provider-Labels aus gemeinsamer Definition. Testvideo ID 4 entfernt.

Offen bleibt E1 (mehrstündige Nutzer-URL fehlt). Alle drei Hoster sind live geprüft; am bestehenden Video ID 3 sind drei Kopien hinterlegt. Browserprüfung: Directupload (1280 px) → FastPic (400 px) → Postimages (1024 px) → YouTube-Original (1280 px), jeweils durch Blockieren der vorherigen Quellen. Weitere Bestandskopien bleiben für die Nachtwartung vorgemerkt. Directupload verwendet seit v0.7.1 den tatsächlich im sichtbaren Browser geprüften öffentlichen Formularversand, keine offizielle API. Auth-/Geo-Provider bleiben deaktiviert.

---

## 9. Erste Schritte für den nächsten Agenten

1. `git log --oneline -5` und `HANDOFF.md` lesen.
2. Stack prüfen: `docker ps`, `curl -sk https://crozztube.local:8443/api/source-resolver/health | jq`.
3. `./tools/test/run.sh api direct` als Smoke-Test.
4. Bei Plugin-Änderungen: Version in `plugin.json` bumpen, `./tools/dev/reinstall-plugin.sh`, Tests, Doku (HANDOFF + Wiki) aktualisieren, committen + pushen.
5. Offene Punkte mit dem User priorisieren (E1 braucht eine URL; E4 ist ein Browser-Check).

---

## 10. Definition of Done (für Änderungen)

- [ ] Harness grün (`./tools/test/run.sh all`)
- [ ] Plugin-Version in `plugin.json` erhöht (bei Plugin-Änderungen)
- [ ] `queue:restart` nach Reinstall
- [ ] `HANDOFF.md` + betroffene Wiki-Seite aktualisiert
- [ ] Commit + Push nach `origin/main`
