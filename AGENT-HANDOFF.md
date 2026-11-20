# CrozzTube — Agent-Handoff (Stand: 20. Nov 2026)

> Kompakte Übergabe für den nächsten Agenten.
> Verbindlich: `AGENTS.md`, `harness/plan.json`, `harness/progress.json`, `docs/product-backlog.md`.
> Ausführliche Historie weiter unten und in `HANDOFF.md`. Wiki: `Wiki/index.html`.
> Der Abgleich zu PR #4, den Sprachpaketen, CI, Migration und Runtime-Hook steht in `docs/series-seasons-reconciliation.md`.

## Aktueller Auftrag

1. Vor Implementierung `node tools/harness/cli.mjs status` lesen.
2. Produktideen und Wettbewerbsentscheidungen nur in `docs/product-backlog.md` pflegen; den Harness nicht umgehen.
3. Aktiver Schritt: **8 — Serien und Staffeln**. Implementierung und Design-Nachlauf sind als `45c9030`/`442e179` auf `main` veröffentlicht. Der Checkpoint `e20b2ba` enthält den Duplikat-/Lock-Fix, die Pending-Migration und die aktualisierte Nachweislage. Der Projekt-Hook ist inzwischen über `.opencode/plugins/crozztube-harness/` in der aktiven OpenCode-Runtime geladen; ein echter `deny-probe` wurde blockiert (`runtimeHookVerified: true`, Digest `0e9245008415…`). `complete` und `begin 9` bleiben dennoch gesperrt: unabhängige Nachprüfung der Korrekturen und des Adapters, Freigabe der Pending-Migration, schwere Serienprüfungen auf einem Runner sowie die Sprachprüfung sind offen.
4. Schritt 8 baut auf bestehenden Playlists (`kind='series'`); keine zweite Serienstruktur. Nächste Folge/Playlist-Priorität bleiben serverseitig zugangsgeprüft bzw. clientseitig als reine JS-Regel.
5. Die UI-Verfeinerung (Startseite, Karten, Navigation, Seriendetail, Watch) ist eine abgegrenzte Designarbeit, keine Schritt-9-Arbeit und keine Abnahme von Schritt 8.
6. Rollengrenze: normale Nutzer/Creator empfehlen nur; Curator/Admin importieren und veröffentlichen. Persönliche Playlists dürfen nur freigegebene CrozzTube-Videos sammeln.
7. Keine Videobytes über CrozzTube/PHP; keine generischen Video-Datei-Uploads.

## Offene Punkte nach dem Checkpoint

- **Runtime-Hook:** Der projektlokale Adapter `.opencode/plugins/crozztube-harness/index.ts` ist geladen; der aktive Agent hat `node tools/harness/cli.mjs deny-probe` ausgelöst, der Hook hat den Aufruf blockiert und einen `probe-blocked`-Receipt mit aktuellem Digest geschrieben (`runtimeHookVerified: true`). Der Adapter bildet nur `deny-probe` und `begin N` über `permission.evaluate` ab; `SessionStart`, `UserPromptSubmit`, `Interrupt` und `Stop` aus `.codex`/`.cursor` sind **nicht** auf OpenCode abgebildet und werden nicht beansprucht. Die unabhängige Prüfung des Adapters steht aus. `hookDigest` umfasst jetzt Adapter- und Plugin-Dateien; Änderungen daran machen ältere Receipts ungültig.
- **PR #4** (`https://github.com/benjarogit/crozztube/pull/4`, Draft/`DIRTY`): Die `is_series`-Implementierung ist durch das `kind`-Modell überholt und soll nicht gemergt werden. Der PR bleibt als Träger der Sprachprüfungen offen.
- **Sprachpakete:** 31 neue Serien-Schlüssel (inkl. `Episode numbers must be unique within a season.`) sind in 9 von 125 Paketen vollständig (`de_at`, `de_ch`, `de_de`, `en`, `en_au`, `en_ca`, `en_gb`, `en_nz`, `en_za`); 116 Pakete fehlen. PR #4 deckt die 4 geteilten Schlüssel in 111 Paketen ab, 14 Pakete bleiben teilweise/leer (`am arg azb bo dzo jv_id my_mm ps sah snd szl tah ug_cn yor`). Fehlende Pakete offen führen, nicht als geprüft zählen.
- **CI:** Der Workflow `.github/workflows/chatdev.yml` ist erweitert: `push` auf `main`, aktualisierter Pfadfilter (u. a. `.opencode/**`, `SeriesStructure.php`, Serien-Checks, `tools/i18n/batches/**`, `tools/migrations/**`; PR-#4-Pfade entfernt) und ein runner-tauglicher Node-/JSON-Schritt (`series-playback.test.mjs`, JSON-Parse). Die bestehenden ChatDev-Tests bleiben unverändert. Actions-Läufe `44738754868` auf `5ce1846` und `44746357942` auf `268c369` (Push auf `main`) sind **grün**: Harness/ChatDev 214/214 und der neue Runner-Step bestanden. Die acht schweren Serienschritte wurden wegen fehlendem `head_ref` übersprungen; PHP/DB/Browser der Serie liefen dort **nicht**. Der erste Lauf `44738089027` auf `3a8edff` scheiterte an einer veralteten Pfad-Erwartung im ChatDev-Test; behoben in `5ce1846`. Keine Runner-Evidenz für `step-08-series.sh` (weiterhin `docker exec`).
- **Migration:** `2026_11_16_000002` ist auf `crozztube-web`/`crozztube-mysql` als Batch 18 ausgeführt; die PR-Migration `2026_11_14_000001` lief nie. Der Unique-Index-Entwurf `tools/migrations/pending/2026_11_17_000001_add_unique_episode_number_to_playlist_video.php` ist **nicht auf der bestehenden Instanz ausgeführt**. Er wurde am 20. Nov 2026 in einer wegwerfbaren `mysql:8.0.46`-Testdatenbank mit synthetischen Daten geprüft: `up` erzeugte den Unique-Index, lehnte Duplikate ab, erlaubte mehrere `NULL`s und den Zwei-Phasen-Swap; `down` stellte den alten nicht-unique Index wieder her. Die bestehende Instanz blieb unverändert (alter Index, Batch 18). Grenzen: minimale synthetische Tabelle, keine HTTP-/Policy-Prüfung, kein Backup-/Restore-Test. Vor einem echten Apply: schriftliche Freigabe, `mysqldump` + Restore-Probe in getrennter DB, `migrate --pretend`, Change-Fenster, `SHOW INDEX` und `series-checks.php`; Datei erst dann nach `database/migrations/` verschieben. Keine automatische Rücksetzung, keine weitere Produktivmigration ohne Freigabe.
- **Live-Test:** Plugin-/Mirror-Änderungen sind ohne `TEST_PASSWORD` nicht live geprüft und dürfen nicht als live getestet behauptet werden.

## Verifizierte Prüfungen (Arbeitsbaum nach `e20b2ba`, Runtime-Adapter vom 20. Nov 2026)

- `bash tools/test/step-08-series.sh`: 61 PHP-Checks, 6 prozessübergreifende Lock-Checks, 8 Node-Tests, `series-ui-check.mjs`.
- `node tools/test/ui/viewer-quality.mjs` (520 Checks), `node tools/test/ui/series-ui-check.mjs`.
- `npx playwright test specs/viewer-experience.spec.ts --project=chromium --grep-invert "webkit:"`: 10/10. WebKit scheitert lokal an Host-Bibliotheken (`libicu74`, `libxml2`, `libflite1`), kein Codefehler.
- `node tools/test/ui/layout-shots.mjs <label>`: 5 Bereiche × de/en × hell/dunkel × Desktop/Mobil = 40 Bilder je Lauf, keine Seitenüberläufe, keine Writes, keine Fehler. Die Navigation wird als Sidebar-Element fotografiert; die Überlaufprüfung gilt für die vier Seitenbereiche.
- `npm run build`.
- Screenshots (nicht im Repo): `/tmp/opencode/shots/before` (aus `35461c1` in detached Worktree, temporärer Build-Tausch) und `/tmp/opencode/shots/after`, je 40 Bilder.
- `node --test --test-reporter=spec tools/test/harness.test.mjs`: 20/20; enthält Forwarding-Auswahl, echte Deny-/Receipt-Kette, Fail-closed-Verhalten und die Bindung des Receipts an Adapter-/Plugin-Dateien.
- Aktiver OpenCode v2.3.2: Projekt-Plugin automatisch geladen; `node tools/harness/cli.mjs deny-probe` wurde vor Ausführung mit `CROZZTUBE_HARNESS_PROBE_BLOCKED` abgelehnt; `runtimeEvidence` ist für Digest `0e9245008415…` wahr. Kein simulierter oder kopierter Receipt.
- **Review-Stand:** Ein unabhängiger Reviewer-Durchgang meldete zwei blockierende Befunde (Publikationsaussage, fehlender `/playlists`-Einstieg) und nicht-blockierende Hinweise; beide Blocker sind behoben und die betroffenen Checks erneut gelaufen. Die unabhängige Nachprüfung genau dieser Korrekturen konnte nicht abgeschlossen werden, weil das Reviewer-Modell sein Free-Usage-Limit erreichte. Ein zweiter Versuch für den Runtime-Adapter scheiterte am selben Limit. Es wird keine unabhängige Freigabe behauptet; Schritt 8 bleibt offen.

Die folgenden Abschnitte beschreiben den älteren Betriebs-/Resolver-Stand vom 5. Nov 2026 und sind Historie, keine zweite Roadmap.

---

# Historischer Agent-Handoff (Stand: 5. Nov 2026)

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
| Admin | `benny` / `lokal hinterlegt; nicht versionieren` |
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
- Neue Harness-Suite `responsive`: **455 Fälle grün**: 100 Route-/Zustandsvarianten × 3 Chromiumbreiten, Inventarprüfung, 9 Interaktionen, 13 Sidebar-Fälle und je 66 Firefox-/WebKit-Fälle. Fixtures speichern keine API-Inhalte/Schlüssel, faken Queues und rollen GET-DB-Änderungen zurück; Browser-Schreibanfragen/Medien gesperrt. Ein Worker.
- WebKit kann lokal Bibliotheken vermissen. `npm run test:responsive` / Harness nutzen dann automatisch einen eigenen offiziellen Dockerbrowser (1 CPU / 1 GiB, read-only Projektmount, eigene Aufräumlogik). Bestehender Server über PW_WEBKIT_WS_ENDPOINT. Keine dauerhaften Testcontainer stehen lassen.
- Build: `npm run build -- --emptyOutDir=false` erhält ältere Chunks für offene Tabs. Keine Plugin-Neuinstallation für reine Frontendänderungen nötig. Kuratierte Vorher-/Nachherbilder: `/tmp/crozztube-tests/responsive-screenshots/`; Wiki: `Wiki/responsive.html`.
- Nachweisgrenze: Browser-Engines/Viewport-/synthetische Touchtests sind keine physischen iPhone-/Android-Tests. 200%-Reflow entspricht halbierter CSS-Breite; echter OS-/Browserzoom und echte Bildschirmtastaturen gesondert offen kennzeichnen.

## Bildanhänge und Sidebar-Footer (5. November 2026)

- `SiteFooter variant="sidebar"` besitzt eine kompakte, einspaltige Variante in `sidebar-footer.css`. Logo, Beschreibung, konfigurierte Links, Sprache und beide Copyright-Texte bleiben erhalten. Geöffnete Sidebars gehören jetzt zur allgemeinen Überlaufprüfung; zusätzlich 13 DE/EN-/Breiten-/Kurzfensterfälle.
- Bilder in Nachrichten (Compose/Chat), Community-Beiträgen/Event-Covern und Community-/Video-/Shorts-Kommentaren samt Antworten gehen sofort über begrenzten RAM zu Directupload → FastPic → Postimages. Erstes geeignetes Ergebnis innerhalb insgesamt 10 s; keine weiteren Tageskopien. FastPic-Vorschauen zählen nur bei Video-Thumbnails, nicht bei Fotoanhängen.
- `app/Support/Images/ImageUploads.php`, neue Tabellen `image_uploads`/`image_upload_mirrors`, additive Migration `2026_11_05_000003`. Authentifizierte Raw-Body PUT/GET/DELETE `/api/image-uploads/{uuid}` mit getrennten HTTP-Zählern (PUT für Web/V1 gemeinsam), dieselben Routen in V1 mit `engagement:write`. Zweck und Ziel als Query; `X-Image-Name` URI-kodiert. Absenden verwendet nur `image_ids`; alte Multipart-Bildfelder sind gesperrt. Maximal vier Bilder, fünf MiB bei Nachrichten/Kommentaren, acht bei Community-Beiträgen; JPEG/PNG/WebP/GIF.
- Browser-Queue `imageUploadQueue.js` überträgt nacheinander; UUID-Wiederholung fragt zuerst Status ab. Offene Entwürfe behalten Text/Dateien bei Fehlern. Pending/fehlerhafte Bilder blockieren Absenden, Retry/Remove sind erreichbar. Entfernen/Unmount tombstonen die UUID; erfolgreich zugeordnete Bilder werden nicht versehentlich verworfen. Kein Dateiinhalt in Datenbank/Queue; PHP-Request-Puffer auf `/dev/shm`, Apache-Routenlimit acht MiB.
- Eigentümer/Zweck/Ziel/Frist und Berechtigungen werden vor Transfer und beim atomaren Claim erneut geprüft. UUID- und globale Transfersperren, eigentümergebundene Hash-Wiederverwendung, nachberechtigter Request-Batchcache gegen N+1. API-Metadaten bleiben kompatibel, plus `image_id`/`image_candidates`; Anzeige und Galerien nutzen dieselbe Browser-Fallbackkomponente. Kauf-/Nachrichten-/Sichtbarkeitssperren bleiben gültig. Weitergegebene Hoster-Links sind ausdrücklich öffentlich, auch bei Nachrichten/gekauften Fotos.
- `NightImageMaintenance` verarbeitet BEIDE Bildarten auf der vorhandenen Queue/Worker: insgesamt 20 Checks + fünf Kopierversuche + 60 s pro Lauf, 02:00–vor05:00 Berlin, globale Hoster-Pausen und Kontingente. Auch Vorbereitung stoppt am Fensterende. Nur angehängte Bilder spiegeln; Entwürfe nach 24 h begrenzt bereinigen, verwaiste Zuordnungen ausschließen. Reparatur nur aus noch funktionierender Kopie; bei Totalverlust neue Dateiauswahl nötig. Externe Komplettlöschung ohne Hoster-Schnittstelle nicht zugesichert.
- Statusbefehl `crozztube:thumbnails:status` ergänzt Anhangzustände, Spiegel und gemeinsame Budgets. Suite `images` prüft Service/Rennen/API/Berechtigungen und Browserformulare ohne echte Uploads/Nachrichten/Beiträge; Datenbankänderungen werden zurückgerollt. Details: `Wiki/images.html`. Der gezielte `images`-Nachlauf prüft zusätzlich mit realem Laravel-Throttler die getrennten Status-/Upload-/Abbruchlimits.
- Migration ausgeführt, Frontend mit erhaltenen Alt-Chunks gebaut, Apache-Konfiguration validiert und graceful neu geladen; Worker per `queue:restart` neu eingelesen. Resolver-Plugin bleibt v0.7.1, da diese Änderung den Anwendungskern betrifft.
- Web-SAPI-Fix: Auf dieser Apache-Instanz ist `PHP_BINARY` leer. `RemoteImages` startet DNS-Prüfprozesse ausdrücklich mit `PHP_BINDIR.'/php'` und `--` vor Hostargumenten. Die `images`-Suite prüft den echten Web-SAPI-Weg zusätzlich mit einem kurzlebigen, zufällig geschützten PHP-Testhelfer und DNS für alle drei Provider (keine Bildübertragung). Nach dem Fix: neutraler 32×32-Raw-Upload per echtem HTTP erfolgreich zu Directupload, Hotlink HTTP 200; eigene Testmetadaten entfernt, andere Entwürfe erhalten. Betroffene Suiten anschließend 17 PASS / 0 FAIL.

## 3. Aktueller Stand

- **Plugin `crozztube.source-resolver` v0.7.1** installiert und aktiv.
- **yt-dlp 2026.10.26**, ffprobe 5.1.9 im Web-Image; cron aktualisiert yt-dlp täglich.
- **Vollständiges Harness: 114 PASS, 0 FAIL, 0 SKIP. PHP-Suiten mit insgesamt 200 Einzelprüfungen (91 Thumbnails + 43 Uploadservice + 25 Edge + 41 API); 9 Wiedergabe-, 27 Anhangs- und 455 responsive Browserfälle grün** (5. November 2026).
- **3 Videos**, alle Quellen `fresh`: ID 1–3, echte YouTube-Imports (`kind=hls-synth`).
- ID 4 (veraltetes Direct-MP4-Testsample) wurde entfernt; die Direct-Suite verwendet einen temporären MDN-Import.
- Auto-Refresh hat nach längerer Stack-Downtime alle abgelaufenen Quellen wieder erneuert.
- Arbeitsbaum sauber, `main...origin/main`.

Ausgewählte vorherige Commits (aktuellen Stand mit `git log -1` prüfen):

| Commit | Inhalt |
|---|---|
| `36b095e` | Responsive Oberfläche und drei Browser-Engines |
| `8a40a22` | Directupload über geprüftes öffentliches Formular |
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
5. Offen bleibt E1 mit einer mehrstündigen Nutzer-URL. E4/Shorts und E6 sind bereits umgesetzt/geprüft; physische iOS-/Android-Tests bleiben gesondert auszuweisen.

---

## 10. Definition of Done (für Änderungen)

- [ ] Harness grün (`./tools/test/run.sh all`)
- [ ] Plugin-Version in `plugin.json` erhöht (bei Plugin-Änderungen)
- [ ] `queue:restart` nach Reinstall
- [ ] `HANDOFF.md` + betroffene Wiki-Seite aktualisiert
- [ ] Commit + Push nach `origin/main`
