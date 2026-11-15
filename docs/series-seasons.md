# Serien und Staffeln / Series context

Series extend existing playlists. They do not introduce another video identity,
media store, player, or ordering system. The authoritative implementation scope
and acceptance remain step 8 in `harness/plan.json` / `harness/progress.json`.
This document describes behavior, not a claim that the step has been accepted.

## Data and migration

`2026_11_14_000001_add_series_context_to_playlists.php` adds:

- `playlists.is_series`, default `false` for existing playlists;
- nullable `playlist_video.season_number`, `episode_number`, `episode_title`;
- a unique playlist/season/episode-number index.

A video can carry different episode information in different playlists. Original
`videos.playlist_title`, creator titles, descriptions, provider metadata and
playback progress are preserved. Disabling series preserves episode metadata.
Rollback removes only the newly added fields/index; it does not delete playlists,
videos or memberships. Apply the migration in the intended environment through
the normal reviewed rollout process; setup tests do not migrate a running site.

`playlist_video.sort_order` remains the sole manual order, with pivot ID as a
stable tie-break. Season grouping is derived from those memberships. Episode
numbers do not silently reorder entries. Existing playlist membership, sharing,
owner/editor/viewer permissions and playback queue remain in use.

## Shared Web and V1 API

Both `/api` and `/api/v1` register the same `SeriesPlaylistController`, backed by
`SeriesPlaylists`. Existing playlist responses additionally expose `is_series`.

`GET /playlists/{playlist}/series` returns:

```json
{
  "playlist_id": 12,
  "is_series": true,
  "series_revision": null,
  "can_manage_series": false,
  "episodes": [
    {
      "video_id": 34,
      "sort_order": 0,
      "season_number": 1,
      "episode_number": 1,
      "episode_title": null,
      "available": true,
      "video": {"id": 34, "slug": "example", "title": "Example", "duration_seconds": 600}
    }
  ],
  "seasons": [{"number": 1, "video_ids": [34]}]
}
```

The example is illustrative. Managers receive a 64-character revision token;
other viewers receive `null` so hidden membership changes are not exposed through
a revision oracle. Unavailable episodes are omitted for ordinary viewers. The
owner can see membership metadata with `available:false` and `video:null` to
manage an entry without disclosing inaccessible source details.

`PUT /playlists/{playlist}/series` is owner-only and accepts:

```json
{
  "expected_revision": "the actual 64-character owner token",
  "is_series": true,
  "episodes": [{"video_id": 34, "season_number": 1, "episode_number": 1, "episode_title": "Opening"}]
}
```

`episodes` is a partial metadata update, not replacement membership or order.
An entry must belong to the playlist. Season and episode numbers must both be
set or both empty; season permits 0–10000, episode 1–100000. Titles are optional,
trimmed and limited to 160 characters. Duplicate season/episode pairs and duplicate
video IDs are rejected. At most 1000 episode updates are accepted per request.

`GET /playlists/{playlist}/series/next?after=34&queue[]=56` returns
`{"next_episode": ...}` or `{"next_episode": null}`. The current video must
still belong to the accessible series and be available. At most 50 distinct
positive integer queue IDs are considered in the supplied order. The first
available explicit queue item wins, including an accessible item outside the
current playlist, with `from_queue:true`; otherwise the next available membership
in manual order has `from_queue:false`. External queue items have null episode
metadata. There is no wraparound or invented recommendation at series end.

## Access, freshness and concurrency

- Refresh the route-bound playlist before applying `PlaylistPolicy`, including
  public-to-private and owner-profile changes. Optional bearer viewers on the
  legacy read API use the existing `VideoSales::viewer` resolution.
- Reuse `VideoAccessService`, user blocking, watch bans and purchase checks.
  Skip non-ready, unapproved, future-scheduled, inaccessible private, blocked and
  unpaid locked videos. Queued candidates receive the same fresh checks; skipped
  candidates do not return source titles or URLs.
- Series metadata updates lock the playlist first, recheck owner policy, compare
  the full membership/order/metadata revision and write within one transaction.
  A stale revision returns 409; reload before retrying.
- Metadata reads use the same playlist-first transaction lock, so episode fields
  and their revision cannot straddle an intervening write and silently overwrite
  it on a subsequent owner save.
- Within those transactions, revision fields and episode rows use locking/current
  reads. MySQL's default REPEATABLE READ can otherwise retain a snapshot created
  by policy/scope queries before waiting for the playlist lock. The real parallel
  test reproduced two successful competing edits before this correction; the
  stale writer must now receive 409.
- Number swaps clear numbering inside that transaction before applying the
  validated final assignments. The unique database index remains a backstop.
- Shared-playlist reorder and both legacy/V1 full-playlist updates use the same
  playlist-first lock protocol. The legacy writer keeps requested manual order
  while filtering eligible IDs, and updates scalar fields plus memberships
  atomically. A failed sync rolls the scalar update back too.

The resolver suggests a target; it neither starts playback nor changes autoplay
preferences. The client preserves an explicit personal queue, consumes a queued
target in its own context, retains the mounted player/draft, and respects the
existing autoplay switch. A final video request still applies normal video
access and provider resolution.

Ordered series entries use the Watch route, including short-format entries, to
retain the series context. Explicit queued items are fetched through the normal
authorized video endpoint and keep their existing Shorts/live-replay routing.
Episode suggestions intentionally show only title, position and known duration;
the minimal authorized DTO does not invent thumbnails, channels or view counts.

Shuffle records an explicit queue order and carries `list_order=shuffle`; after
that order is exhausted, playback stops instead of repeating the normal series
sequence. Late next-episode responses are discarded after route, autoplay or
queue changes. The miniplayer carries the context and uses the same fresh next
resolver, preserving expansion back into the series and stopping at its end.

The eight new series messages and the video-loading error retain the nine
EN/DE regional references. The 2026-11-15 batch adds 43 regional catalogs:

- Romance: ca, es_ar, es_cl, es_co, es_cr, es_do, es_ec, es_es, es_gt, es_mx,
  es_pe, es_pr, es_uy, es_ve, fr_be, fr_ca, fr_fr, gl_es, it_it, pt_ao, pt_br,
  pt_pt, pt_pt_ao90, ro_ro.
- Other European group: nl_be, nl_nl, da_dk, sv_se, nb_no, nn_no, fi, cs_cz,
  sk_sk, pl_pl, hr, sl_si, bs_ba, sr_rs, hu_hu, el, tr_tr, ru_ru, uk.

Reference snapshot: 1ff4666445906cb4c5e6eaaf1a8f25b5756eafb5. For each listed
locale, each of the following nine complete message keys receives an editorial
pass and a second reading against the named code context, plus PHP/structural
validation. This records AI-agent editorial review, not native-speaker signoff.

- Series; Season; Episode: PlaylistSeries owner form/disclosure labels.
- Next episode: Watch/miniplayer series suggestion heading.
- Playlist changed. Reload before saving.: stale revision, owner save conflict.
- Choose videos from this playlist.: invalid playlist membership.
- Set both season and episode numbers, or leave both empty.: paired fields.
- An episode number can only appear once in each season.: duplicate numbering.
- Unable to load videos.: unavailable video loading and retry guidance.

The existing browser runner now loads actual allowlisted PHP catalogs for all
52 batch/reference locales, not an English substitute. It checks selected HTML
language (including pt-PT-ao1990), stored preference, actual catalog request,
Series/Season/Episode labels, preserved original video title, 390px overflow and
Next episode in both themes. These are focused owner context checks for the four
labels; the other five messages still need runtime error-path context coverage.
The full controlled German scenarios remain. No live provider or user content
is translated by this test. Publication results belong in the journal/PR.

At checkpoint d8d4704, 73 catalogs still lacked some or all of these messages.
The continuation below records the next bounded editorial/runtime increment;
neither increment is full language acceptance or native-speaker certification.

## Continuation after d8d4704

Reference source: d8d4704848042b3f967f3b2be34cd4e12a0a1001. The same nine-key
matrix above remains authoritative for message/context review. This increment
adds 498 entries in 60 catalogs: 230 in the Asian group, 187 in the remaining
European group, 72 in ar/ary/ckb/fa_af/fa_ir/he_il/ur/sw and nine in the existing
ru/russian.php catalog. The Russian file is not renamed or duplicated; the owner
separately approved only its canonical path exception and required parent
integration with the existing gate checks retained.

Each written message has a whole-message editorial reading and a second source
context reading, with independent review. Original catalog bytes and existing
entries are preserved. These are AI editorial checks; native/dialect nuance
remains unverified. The reports' concrete per-message pending decisions are
preserved here through the following IDs: S=Series, T=Season, E=Episode,
N=Next episode, R=reload stale playlist, M=choose playlist members,
P=paired numbering, D=duplicate number per season, L=video loading/retry.
Historical inventory before the follow-up below (65c0487): only these IDs were absent/unaccepted:

- am: S, T, E, N, R, M, P, D, L.
- arg: S, T, E, N, R, M, P, D, L.
- as: T, R, M, P, D, L.
- azb: S, T, E, N, R, M, P, D, L.
- bo: S, T, E, N, R, M, P, D, L.
- cy: T, P, D.
- dzo: S, T, E, N, R, M, P, D, L.
- fur: N, R, M, P, D, L.
- gd: T, R, M, P, D, L.
- jv_id: S, T, E, N, R, M, P, D, L.
- lo: R, M, P, D, L.
- my_mm: T, R, M, P, D, L.
- oci: R, M, P, D, L.
- ps: S, T, E, N, R, M, P, D, L.
- sah: S, T, E, N, R, M, P, D, L.
- si_lk: R, M, P, D, L.
- snd: S, T, E, N, R, M, P, D, L.
- szl: S, T, E, N, R, M, P, D, L.
- tah: S, T, E, N, R, M, P, D, L.
- ug_cn: S, T, E, N, R, M, P, D, L.
- yor: S, T, E, N, R, M, P, D, L.

Source inventory: 104 catalogs contain all nine messages; 21 are partial or open,
with 159 missing messages. Missing TV-season terminology, register or complete
validation phrasing is not guessed. Partial packages are not counted as fully
translated, and English fallback is not called acceptance. No historical full
language-review record is silently repurposed.

The routed PHP fixture now checks real stale-revision, invalid membership,
paired-number and duplicate-number failures through both APIs using language
headers and exact actual PHP strings, including Vary and nonmutation. It retains
the generic v1 validation summary contract and verifies localized field details.
A reproduced client weakness was that this generic summary hid the useful field
explanation. Only the series editor now prioritizes a valid localized field
message; global API behavior and the public envelope are unchanged. Focused
helper regressions cover both envelopes plus conflict/network/malformed errors.

The browser fixture discovers only catalogs with all nine messages, requires all
52 previously checked locales to remain present, and uses real metadata for the
existing text direction. It additionally exercises generic v1-shaped validation
payloads, preserved drafts, localized controlled load failures and retry. These
are controlled UI-response tests, not a claim that the backend emits the
frontend-only loading key. Existing RTL is preserved; its removal stays deferred.
Actual source-bound execution/publication results are recorded in the journal
and PR, not inferred from this test wiring. Genuine Cursor handoff and production
integration remain separately unverified. No main merge, deployment or step 9.

## Prüfungen und Grenzen

```sh
bash tools/test/step-08-series.sh
node --test tools/test/series-seasons.test.mjs
node tools/test/ui/series-seasons.mjs
SERIES_BROWSER=firefox node tools/test/ui/series-seasons.mjs
bash tools/test/step-08-series.sh --mysql
# Require the isolated MySQL checks as part of this local source-bound gate:
SERIES_TEST_MYSQL_REQUIRED=1 node tools/harness/cli.mjs series-verify
```

The PHP suite builds a fresh Laravel/Capsule SQLite in-memory database without
application bootstrap or `.env` loading. Where host PHP lacks required extensions,
the script uses the existing test image with `--network none` and a read-only
mount of this checkout. It does not access the running application database,
accounts, media providers or publishing services.

The suite includes migration/rollback/reapply, metadata isolation, owner and
viewer rights, stale revisions, unique numbering and swaps, manual order and
tie-breaks, next-episode access and purchases, private/profile transitions, hidden
revision protection and explicit-queue priority. It also executes the actual
legacy transactional mutation block in the isolated fixture and injects a pivot
write failure to test rollback. This is deterministic transaction/optimistic
concurrency evidence. The explicit MySQL mode also runs these checks on a fresh
MySQL 8.0.46 database, then four concurrent-process pairs with 24 assertions.
Distinct connections and `performance_schema.data_lock_waits` establish actual
InnoDB lock waits before the controlling transaction is released. Cases cover
stale edits, consistent metadata/revision reads, concurrent reorder and rollback.
This is real isolated MySQL execution, not production HTTP/MySQL coverage.
The SQLite fixture additionally registers the actual legacy and v1 routes in a
Laravel router and dispatches JSON requests through implicit bindings, Sanctum
bearer authentication and project middleware. It checks access, token abilities,
validation, stale revisions and the actual v1 error/response serialization.
The routed fixture passed 160 assertions alongside the retained 69 SQLite
checks; the controlled Chromium browser passed 124 assertions. This is
in-process routed HTTP evidence; it does not bootstrap the production
kernel, cookie/session/CSRF stack or a real web server. It never loads `.env`.
Hosted CI currently uses the SQLite/browser path; local MySQL evidence
must not be described as a hosted MySQL check.

The explicit runner uses an already available `mysql:8.0.46` image, a temporary
memory-backed database, no published ports and a network namespace with no
external network. PHP joins only that namespace with a read-only source mount.
Random database names and an isolation marker are checked before fixture creation.
Cleanup removes the test container on success/failure/interruption. No running
application database, environment file, credentials or application container is
used or migrated. Unknown arguments fail before starting tests.

The Node suite checks queue/context selection. Browser checks use the real built
UI with controlled API fixtures and a tiny synthetic native-video test fixture.
They cover narrow/desktop layouts, light/dark rendering, manual queue behavior,
autoplay and preserved player state. Controlled responses and test-worker media
are not live provider/purchase/database integration. Do not infer production
acceptance or runtime-hook enforcement from these checks.

## Integration and next actions

Changes are prepared on the isolated `codex/series-seasons` branch. The scoped
`series-verify` / `series-checkpoint` harness commands test and publish only the
approved step-8 snapshot; they do not accept step 8, begin step 9, merge main,
grant hook trust or deploy a migration. Keep existing lazy release chunks for
mounted forms/players. Promotion into local/main and any live migration remain
separate reviewed integration actions; an open PR alone does not update the
existing running main checkout.

Use the live Project Manager report and harness status for current reviews, CI,
publication, approvals and next actions. Before claiming full acceptance, attach
source-bound regression evidence, resolve independent findings, check the actual
PR CI and perform any outstanding integration/operational checks. The original
feature checkout and its unrelated `Archiv.zip` remain untouched by this worktree.

## Coordination with GitHub quality PR #5 (reviewed 2026-11-14)

Reviewed source: PR #5 at `70a4902768926b4292868537cffe988f5c385802`, against
the published ChatDev PR #3 and series PR #4. This is an integration review,
not an alternative authority for feature progress or a merge approval.

- PR #5 adds two issue forms, a PR template, Dependabot configuration and setup
  documentation. It adds no workflow and has no changed-path overlap with #3/#4.
  Its templates preserve the authoritative harness, existing hooks and explicit
  merge/deployment boundaries. No competing progress store is introduced.
- Its three YAML files passed local parser/shape checks. No hosted check ran for
  that PR head; an empty checks list is not a successful CI result. The existing
  `contracts` workflow's path filter does not cover the five governance files.
  Use that existing workflow if governance validation is integrated later; do
  not create a second general PR gate or make a nonexistent check mandatory.
- Dependabot currently covers only the root npm manifest, missing the separate
  `/tools/test/ui` project that pins Playwright. Add that manifest location before
  describing browser-dependency coverage as complete. GitHub supports multiple
  manifest locations via `directories`; avoid duplicate overlapping update jobs.
  The configured limit controls version-update PRs, not a total PR/billing cap;
  security-update PRs have a separate limit. Source:
  https://docs.github.com/en/code-security/reference/supply-chain-security/dependabot-options-reference
- Dependency PRs must run checks relevant to their actual changed dependencies.
  Current hosted series integration is conditional on `codex/series-seasons`;
  green unconditional tooling contracts alone do not validate a future npm or
  Composer upgrade. No scheduler, CodeQL, repository setting or paid service was
  enabled by this review.
- Read-only repository inspection confirmed default Actions token permission
  `read`, no Actions approval of PR reviews, disabled automatic merge and an
  available ancestry-preserving merge-commit option. The rulesets API returned
  403 with a private-repository plan-upgrade requirement. No setting or plan was
  changed; unavailable rulesets must not be described as enabled protection.

The mandatory dependency is **#3 before #4**. Recommended later integration order
is **#5 → #3 → #4**, once each PR's own corrections, checks and approvals are
complete; #5 is independent and can otherwise be scheduled separately. Preserve
#3's ancestry when integrating it, so #4 can subsequently target `main` without
reintroducing tooling changes or rewriting a published branch. Reassess actual
diff/CI after retargeting. No merge, rebase, release or branch retarget is performed
by this plan. Cursor unavailability remains an explicit runtime blocker. Ordinary
code, schema, isolated database and browser checks can proceed independently;
the genuine cross-client run and explicit merge/production approvals cannot be
substituted by fixtures or an agent's role label. Step 8 remains active; step 9
must wait for the existing completion gate.

## Evidence review after 418c101 (2026-11-15)

All 159 absent messages were reviewed against targeted primary-source evidence.
No new complete translation was sufficiently supported for acceptance in this
pass. Existing 125 catalog files remain unchanged. A conditional Welsh term
candidate is recorded below without applying it. These are language-review
questions, not new mandatory production/native-certification gates. A competent
language review can resolve them; certification or paid translation is not an
automatic requirement.

The message IDs S/T/E/N/R/M/P/D/L retain the exact English keys listed above.
For each row, the named source establishes at most its stated boundary; the
question identifies the still-required whole-message/context verification.
PHP syntax, another language's wording and isolated word matches do not grant
approval. Reference commit: `418c101a1560839ef20fdf16ca4db520c34e6807` (source of the reviewed messages).

### am: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: TV-Staffel-/Folgenbegriffe und natürliche persönliche Verbformen sind nicht belegt; Musiklistenwort ist keine Freigabe für die Videoauswahl. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-am](#source-root-vlc-am).

### arg: 9 messages remain unaccepted

- **S** — Welche kurze UI-Bezeichnung steht hier für die gesamte Serie, wenn derselbe Editor daneben Staffel- und Episodennummer getrennt zeigt? Prüfbedarf: Gesamtserie muss von ihrer nummerierten Staffel und von einer einzelnen Episode unterschieden werden. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography), [EU-arg_media_language_limit](#source-eu-arg-media-language-limit).
- **T** — Welches Label benennt hier genau die nummerierte TV-Staffel? Funktioniert es neben dem vorhandenen Gesamtserien-Label und in den zwei zugehörigen Fehlersätzen? Prüfbedarf: Gesucht ist die nummerierte TV-Staffel innerhalb einer Gesamtserie, nicht eine Jahreszeit, Sportspielzeit oder die ganze Serie. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography), [EU-arg_media_language_limit](#source-eu-arg-media-language-limit).
- **E** — Welche kurze Feldbezeichnung meint die vollständige Folge und passt zum Wort für Staffel, ohne Kapitel/Segment zu behaupten? Prüfbedarf: Benötigt wird eine einzelne vollständige Folge; Buchkapitel, Videosegment und Gesamtserie sind nicht dasselbe. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography), [EU-arg_media_language_limit](#source-eu-arg-media-language-limit).
- **N** — Wie lautet die natürliche kurze Überschrift Nächste Folge mit korrekter Adjektiv-/Nominalform, ohne daraus Jetzt abspielen oder Autoplay zu machen? Prüfbedarf: Heading für die nächste zugängliche Folge, keine Zusage sofortiger oder automatischer Wiedergabe; Vorwärts im Browser ist kein Beleg. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography), [EU-arg_media_language_limit](#source-eu-arg-media-language-limit).
- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_save](#source-eu-arg-save), [EU-arg_orthography](#source-eu-arg-orthography).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_save](#source-eu-arg-save), [EU-arg_orthography](#source-eu-arg-orthography).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_orthography](#source-eu-arg-orthography).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. Die amtliche Orthographie ist als Referenz gefunden. TV-Terminologie und vollständige persönliche Formulierungen sind in den geprüften Passagen nicht belegt; Spanisch/Katalanisch/Aranesisch sind keine Ersatzfreigabe. Belege: [EU-arg_reader](#source-eu-arg-reader), [EU-arg_orthography](#source-eu-arg-orthography).

### as: 6 messages remain unaccepted

- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Numbered TV/video season within a series, not weather/time; label must also support the season-number phrases in the missing validators. Belege: [AS-vlc-as.po](#source-as-vlc-as-po), [AS-jellyfin-as.json](#source-as-jellyfin-as-json).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-totem-as.po](#source-as-totem-as-po), [AS-firefox-as.ftl](#source-as-firefox-as-ftl).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-vlc-as.po](#source-as-vlc-as-po), [AS-firefox-as.ftl](#source-as-firefox-as-ftl).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-vlc-as.po](#source-as-vlc-as-po), [AS-jellyfin-as.json](#source-as-jellyfin-as-json), [AS-firefox-as.ftl](#source-as-firefox-as-ftl).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-vlc-as.po](#source-as-vlc-as-po), [AS-jellyfin-as.json](#source-as-jellyfin-as-json).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-vlc-as.po](#source-as-vlc-as-po), [AS-firefox-as.ftl](#source-as-firefox-as-ftl).

### azb: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Südaserbaidschanische Schreibweise und persönlicher Register müssen bestätigt werden; nördliches aserbaidschanisches Latein und Persisch sind keine Ersatzfreigabe. Belege: [ROOT-JF](#source-root-jf).

### bo: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Noun shared by disclosure and toggle: a serial video collection, not only fictional TV, an ordered-series action, or a promise to reorder automatically. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Numbered TV/video season within a series, not weather/time; label must also support the season-number phrases in the missing validators. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Episode in playlist membership, distinct from a chapter/segment inside a video. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Next episode in the selected series playlist; pagination Next or generic continuation is insufficient. WordPress Next is explicitly pagination/find-replace, not a serialized-media phrase. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-wordpress-bo.zip](#source-as-wordpress-bo-zip), [AS-firefox-bo.ftl](#source-as-firefox-bo-ftl).

### cy: 3 messages remain unaccepted

- **T** — Welches Label benennt hier genau die nummerierte TV-Staffel? Funktioniert es neben dem vorhandenen Gesamtserien-Label und in den zwei zugehörigen Fehlersätzen? Prüfbedarf: Gesucht ist die nummerierte TV-Staffel innerhalb einer Gesamtserie, nicht eine Jahreszeit, Sportspielzeit oder die ganze Serie. Nummerierte Fernsehstaffel als cyfres ist nun primär belegt. Die Verwendung neben dem schon vorhandenen Gesamtserien-Schalter ist eine konkrete UI-Terminologiefrage; die beiden ganzen Validierungen sind weiterhin nicht belegt. Belege: [EU-cy_season](#source-eu-cy-season), [EU-cy_production](#source-eu-cy-production).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Nummerierte Fernsehstaffel als cyfres ist nun primär belegt. Die Verwendung neben dem schon vorhandenen Gesamtserien-Schalter ist eine konkrete UI-Terminologiefrage; die beiden ganzen Validierungen sind weiterhin nicht belegt. Belege: [EU-cy_season](#source-eu-cy-season), [EU-cy_numbering](#source-eu-cy-numbering).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Nummerierte Fernsehstaffel als cyfres ist nun primär belegt. Die Verwendung neben dem schon vorhandenen Gesamtserien-Schalter ist eine konkrete UI-Terminologiefrage; die beiden ganzen Validierungen sind weiterhin nicht belegt. Belege: [EU-cy_season](#source-eu-cy-season), [EU-cy_numbering](#source-eu-cy-numbering).

Conditional candidate only: S4C documents a numbered TV edition with the term `Cyfres`. The existing overall-series label is also `Cyfres`; verify their distinction together in the editor before applying the Season value or deriving either complete validator.

### dzo: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Noun shared by disclosure and toggle: a serial video collection, not only fictional TV, an ordered-series action, or a promise to reorder automatically. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Numbered TV/video season within a series, not weather/time; label must also support the season-number phrases in the missing validators. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Episode in playlist membership, distinct from a chapter/segment inside a video. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Next episode in the selected series playlist; pagination Next or generic continuation is insufficient. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Need choose between general/music-list and video-qualified list noun before inflecting the current-playlist phrase. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-totem-dz.po](#source-as-totem-dz-po), [AS-wordpress-dzo.zip](#source-as-wordpress-dzo-zip).

### fur: 6 messages remain unaccepted

- **N** — Wie lautet die natürliche kurze Überschrift Nächste Folge mit korrekter Adjektiv-/Nominalform, ohne daraus Jetzt abspielen oder Autoplay zu machen? Prüfbedarf: Heading für die nächste zugängliche Folge, keine Zusage sofortiger oder automatischer Wiedergabe; Vorwärts im Browser ist kein Beleg. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_programme](#source-eu-fur-programme).
- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_save](#source-eu-fur-save).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_programme](#source-eu-fur-programme), [EU-fur_save](#source-eu-fur-save).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_programme](#source-eu-fur-programme).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_programme](#source-eu-fur-programme).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. Eigene primäre TV-Seite und gepflegte Browserformulierungen sind vorhanden. Eine nächste Episode und vollständige Bedingungen sind damit noch keine direkt belegten Sätze; italienische Paralleltexte bleiben ausgeschlossen. Belege: [EU-fur_reader](#source-eu-fur-reader).

### gd: 6 messages remain unaccepted

- **T** — Welches Label benennt hier genau die nummerierte TV-Staffel? Funktioniert es neben dem vorhandenen Gesamtserien-Label und in den zwei zugehörigen Fehlersätzen? Prüfbedarf: Gesucht ist die nummerierte TV-Staffel innerhalb einer Gesamtserie, nicht eine Jahreszeit, Sportspielzeit oder die ganze Serie. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_programme](#source-eu-gd-programme).
- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_save](#source-eu-gd-save).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_programme](#source-eu-gd-programme), [EU-gd_save](#source-eu-gd-save).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_programme](#source-eu-gd-programme).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_programme](#source-eu-gd-programme).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. BBC-Präzedenz belegt Programmreihe und einzelne Programme. Eine weitere Staffel-Ebene und die grammatisch abhängigen vollständigen UI-Sätze brauchen gezielte Prüfung, nicht eine Vermutung aus dem Wetterwort season. Belege: [EU-gd_reader](#source-eu-gd-reader).

### jv_id: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Noun shared by disclosure and toggle: a serial video collection, not only fictional TV, an ordered-series action, or a promise to reorder automatically. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Numbered TV/video season within a series, not weather/time; label must also support the season-number phrases in the missing validators. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Episode in playlist membership, distinct from a chapter/segment inside a video. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Next episode in the selected series playlist; pagination Next or generic continuation is insufficient. Attested Uwisé/Lanjuté must not be mechanically joined to an unverified Episode loan. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-wordpress-jv_ID.zip](#source-as-wordpress-jv-id-zip).

### lo: 5 messages remain unaccepted

- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-wordpress-lo.zip](#source-as-wordpress-lo-zip), [AS-firefox-lo.ftl](#source-as-firefox-lo-ftl).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-wordpress-lo.zip](#source-as-wordpress-lo-zip), [AS-firefox-lo.ftl](#source-as-firefox-lo-ftl).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-wordpress-lo.zip](#source-as-wordpress-lo-zip), [AS-firefox-lo.ftl](#source-as-firefox-lo-ftl).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-wordpress-lo.zip](#source-as-wordpress-lo-zip).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Need settle video spelling consistently with existing catalog while using an actual loading/error predicate. Belege: [AS-wordpress-lo.zip](#source-as-wordpress-lo-zip), [AS-firefox-lo.ftl](#source-as-firefox-lo-ftl).

### my_mm: 6 messages remain unaccepted

- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Numbered TV/video season within a series, not weather/time; label must also support the season-number phrases in the missing validators. Belege: [AS-vlc-my.po](#source-as-vlc-my-po), [AS-jellyfin-my.json](#source-as-jellyfin-my-json), [AS-kodi-my_mm.po](#source-as-kodi-my-mm-po).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-kodi-my_mm.po](#source-as-kodi-my-mm-po), [AS-totem-my.po](#source-as-totem-my-po), [AS-firefox-my.ftl](#source-as-firefox-my-ftl).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-kodi-my_mm.po](#source-as-kodi-my-mm-po), [AS-vlc-my.po](#source-as-vlc-my-po), [AS-firefox-my.ftl](#source-as-firefox-my-ftl).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-vlc-my.po](#source-as-vlc-my-po), [AS-jellyfin-my.json](#source-as-jellyfin-my-json), [AS-firefox-my.ftl](#source-as-firefox-my-ftl).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-vlc-my.po](#source-as-vlc-my-po), [AS-kodi-my_mm.po](#source-as-kodi-my-mm-po).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-totem-my.po](#source-as-totem-my-po), [AS-firefox-my.ftl](#source-as-firefox-my-ftl).

### oci: 5 messages remain unaccepted

- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. Primäre Programm-/Produktionstexte und Browserkatalog sind in Okzitanisch vorhanden. Der Browser verwendet hier teils Infinitive; das belegt keine persönliche Singular-Form der kompletten Aufforderung. Belege: [EU-oci_save](#source-eu-oci-save).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. Primäre Programm-/Produktionstexte und Browserkatalog sind in Okzitanisch vorhanden. Der Browser verwendet hier teils Infinitive; das belegt keine persönliche Singular-Form der kompletten Aufforderung. Belege: [EU-oci_programme](#source-eu-oci-programme), [EU-oci_save](#source-eu-oci-save).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Primäre Programm-/Produktionstexte und Browserkatalog sind in Okzitanisch vorhanden. Der Browser verwendet hier teils Infinitive; das belegt keine persönliche Singular-Form der kompletten Aufforderung. Belege: [EU-oci_programme](#source-eu-oci-programme).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Primäre Programm-/Produktionstexte und Browserkatalog sind in Okzitanisch vorhanden. Der Browser verwendet hier teils Infinitive; das belegt keine persönliche Singular-Form der kompletten Aufforderung. Belege: [EU-oci_programme](#source-eu-oci-programme).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. Primäre Programm-/Produktionstexte und Browserkatalog sind in Okzitanisch vorhanden. Der Browser verwendet hier teils Infinitive; das belegt keine persönliche Singular-Form der kompletten Aufforderung. Belege: [EU-oci_reader](#source-eu-oci-reader).

### ps: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Leere TV-Metadatenbegriffe im geprüften VLC-Snapshot; Fall-/Verbformen für beide Nummern und die Staffelgrenze brauchen kompetente Prüfung. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ps](#source-root-vlc-ps).

### sah: 9 messages remain unaccepted

- **S** — Welche kurze UI-Bezeichnung steht hier für die gesamte Serie, wenn derselbe Editor daneben Staffel- und Episodennummer getrennt zeigt? Prüfbedarf: Gesamtserie muss von ihrer nummerierten Staffel und von einer einzelnen Episode unterschieden werden. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_publisher_usage](#source-eu-sah-publisher-usage), [EU-sah_broadcaster](#source-eu-sah-broadcaster).
- **T** — Welches Label benennt hier genau die nummerierte TV-Staffel? Funktioniert es neben dem vorhandenen Gesamtserien-Label und in den zwei zugehörigen Fehlersätzen? Prüfbedarf: Gesucht ist die nummerierte TV-Staffel innerhalb einer Gesamtserie, nicht eine Jahreszeit, Sportspielzeit oder die ganze Serie. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_broadcaster](#source-eu-sah-broadcaster), [EU-sah_publisher_usage](#source-eu-sah-publisher-usage).
- **E** — Welche kurze Feldbezeichnung meint die vollständige Folge und passt zum Wort für Staffel, ohne Kapitel/Segment zu behaupten? Prüfbedarf: Benötigt wird eine einzelne vollständige Folge; Buchkapitel, Videosegment und Gesamtserie sind nicht dasselbe. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_broadcaster](#source-eu-sah-broadcaster), [EU-sah_publisher_usage](#source-eu-sah-publisher-usage).
- **N** — Wie lautet die natürliche kurze Überschrift Nächste Folge mit korrekter Adjektiv-/Nominalform, ohne daraus Jetzt abspielen oder Autoplay zu machen? Prüfbedarf: Heading für die nächste zugängliche Folge, keine Zusage sofortiger oder automatischer Wiedergabe; Vorwärts im Browser ist kein Beleg. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_video](#source-eu-sah-video), [EU-sah_publisher_usage](#source-eu-sah-publisher-usage).
- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_save](#source-eu-sah-save).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_save](#source-eu-sah-save), [EU-sah_video](#source-eu-sah-video).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_broadcaster](#source-eu-sah-broadcaster).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_broadcaster](#source-eu-sah-broadcaster).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. Sakha-Mediensteuerung ist vorhanden; Support-/Archivseiten des Senders sind teils Russisch. Flektierte Serienbezeichnung im Publisher-Kanal ist kein kompletter Terminologiesatz und keine Validierung. Belege: [EU-sah_reader](#source-eu-sah-reader), [EU-sah_video](#source-eu-sah-video).

### si_lk: 5 messages remain unaccepted

- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Playlist revision changed concurrently; reload current playlist before saving. No actor, lost-data promise, automatic saving or network-cause invention. Belege: [AS-firefox-si.ftl](#source-as-firefox-si-ftl), [AS-kodi-si_lk.po](#source-as-kodi-si-lk-po).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Select videos already belonging to THIS playlist, not videos owned by the viewer, the entire library, or an instruction to create/add a new playlist. Belege: [AS-kodi-si_lk.po](#source-as-kodi-si-lk-po), [AS-vlc-si.po](#source-as-vlc-si-po), [AS-totem-si.po](#source-as-totem-si-po).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Fill both distinct season and episode numbers, or leave BOTH empty. Not equal values, either field, or a requirement for every video to have numbers. Belege: [AS-vlc-si.po](#source-as-vlc-si-po), [AS-firefox-si.ftl](#source-as-firefox-si-ftl).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Episode number must be unique WITHIN EACH season. Same number in another season is allowed; do not impose global uniqueness. Belege: [AS-vlc-si.po](#source-as-vlc-si-po), [AS-firefox-si.ftl](#source-as-firefox-si-ftl).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Video-list/loading failure plus retry action, as current EN says. Not a codec/playback failure or an invented connectivity/server cause. Belege: [AS-totem-si.po](#source-as-totem-si-po), [AS-firefox-si.ftl](#source-as-firefox-si-ftl).

### snd: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Sindhi-Schrift, persönliche Imperative und Kasus der nummerierten Staffel/Folge sind in den geprüften Medienquellen nicht abgesichert; Urdu ist kein Ersatz. Belege: [ROOT-JF](#source-root-jf).

### szl: 9 messages remain unaccepted

- **S** — Welche kurze UI-Bezeichnung steht hier für die gesamte Serie, wenn derselbe Editor daneben Staffel- und Episodennummer getrennt zeigt? Prüfbedarf: Gesamtserie muss von ihrer nummerierten Staffel und von einer einzelnen Episode unterschieden werden. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **T** — Welches Label benennt hier genau die nummerierte TV-Staffel? Funktioniert es neben dem vorhandenen Gesamtserien-Label und in den zwei zugehörigen Fehlersätzen? Prüfbedarf: Gesucht ist die nummerierte TV-Staffel innerhalb einer Gesamtserie, nicht eine Jahreszeit, Sportspielzeit oder die ganze Serie. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **E** — Welche kurze Feldbezeichnung meint die vollständige Folge und passt zum Wort für Staffel, ohne Kapitel/Segment zu behaupten? Prüfbedarf: Benötigt wird eine einzelne vollständige Folge; Buchkapitel, Videosegment und Gesamtserie sind nicht dasselbe. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **N** — Wie lautet die natürliche kurze Überschrift Nächste Folge mit korrekter Adjektiv-/Nominalform, ohne daraus Jetzt abspielen oder Autoplay zu machen? Prüfbedarf: Heading für die nächste zugängliche Folge, keine Zusage sofortiger oder automatischer Wiedergabe; Vorwärts im Browser ist kein Beleg. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **R** — Formuliere den ganzen Revisionskonflikt mit persönlicher Singular-Aufforderung und eindeutiger zeitlicher Reihenfolge. Ist das Pronomen bzw.die Objektflexion für Playlist korrekt? Prüfbedarf: Zwei vollständige Aussagen: jemand hat die Playlist geändert; deshalb vor einem weiteren Speicherversuch neu laden. Darf nicht klingen wie erfolgreich selbst gespeichert. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save).
- **M** — Wie lautet die komplette persönliche Auswahlaufforderung für mehrere Videos aus genau dieser Playlist mit korrekter Von-/Aus-Flexion? Prüfbedarf: Die Auswahl muss aus genau dieser vorhandenen Playlist stammen; keine beliebige Playlist, kein Upload und keine Providerwahl. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save).
- **P** — Formuliere die gesamte Entweder-beide-Nummern-oder-beide-leer-Regel persönlich und idiomatisch. Worauf beziehen sich beide und leer, und stimmen Staffel-/Episodenbegriffe und Flexion? Prüfbedarf: Logisches Oder zwischen zwei vollständigen Zuständen: beide Nummern vorhanden ODER beide Felder leer. Nicht nur irgendein Feld ausfüllen und leer nicht mit0 verwechseln. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **D** — Formuliere den ganzen Satz so, dass genau ein Vorkommen derselben Nummer jeStaffel erlaubt ist, mit korrekt flektierten Fachwörtern. Kann ein Leser ihn fälschlich als globale Eindeutigkeit verstehen? Prüfbedarf: Die gleiche Episodennummer ist in verschiedenen Staffeln erlaubt, aber innerhalb einer Staffel nur einmal. Formulierung wie darf sich einmal wiederholen wäre falsch, da sie zwei Vorkommen erlauben könnte. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_save](#source-eu-szl-save), [EU-szl_reader](#source-eu-szl-reader).
- **L** — Wie lautet ein neutraler vollständiger Videos-Ladefehler mit persönlichem Versuch-es-erneut? Sind Video-Mehrzahl und Objekt-/Genitivform korrekt, ohne eine Fehlerursache zu erfinden? Prüfbedarf: Die vollständige aktuelle Referenz enthält zusätzlich einen erneuten Versuch. Mehrere Videos/Listenmetadaten dürfen nicht mit einer einzelnen kaputten Mediendatei oder garantiertem Netzwerkfehler verwechselt werden. Mozilla belegt eigenständige schlesische Rechtschreibung und Bedienformulierungen. Das ist weder eine Freigabe polnischer TV-Nomen noch ein Beleg für die vollständigen Fachbedingungen. Belege: [EU-szl_reader](#source-eu-szl-reader).

### tah: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Tahitische TV-Serienebenen und vollständige Bedingungs-/Fehlersätze fehlen in den geprüften Quellen; französische Nachbartexte nicht als Tahitisch übernehmen. Belege: [ROOT-JF](#source-root-jf).

### ug_cn: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Ein Playlistnomen ist belegt, Staffel-/Folgenfelder im geprüften VLC sind leer; uigurische Schrift, Suffixe und komplette Bedingungen bleiben ungeprüft. Belege: [ROOT-JF](#source-root-jf), [ROOT-VLC-ug](#source-root-vlc-ug).

### yor: 9 messages remain unaccepted

- **S** — Welcher kurze Begriff bezeichnet die gesamte Videoserie und bleibt neben der nummerierten Staffel verständlich? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **T** — Welcher Begriff meint die nummerierte TV-Staffel einschließlich Staffel 0 (Specials), nicht Wetter oder Kalenderzeit? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **E** — Welcher Begriff meint eine einzelne Folge der Serie, nicht ein Kapitel oder einen Dateiausschnitt? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **N** — Wie lautet die kurze nächste-Folge-Beschriftung mit demselben Episodenbegriff und natürlicher Wortstellung? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **R** — Wie wird persönlich erklärt: Die Playlist wurde zwischenzeitlich geändert; vor dem Speichern neu laden, ohne automatisches Speichern zu versprechen? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **M** — Wie wird persönlich auf Videos genau dieser bestehenden Playlist eingeschränkt, ohne einen Import oder eine andere Playlist zu erlauben? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **P** — Wie lautet die vollständige Alternative: Staffel- und Folgennummer beide angeben oder beide leer lassen, ohne Staffel 0 zu verbieten? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **D** — Wie wird die Eindeutigkeit nur innerhalb derselben Staffel erklärt: S1/E1 zweimal verboten, S1/E1 und S2/E1 erlaubt? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).
- **L** — Wie wird ein Laden der Videoliste mit Wiederholen erklärt, ohne Codec-/Wiedergabefehler, erfundene Ursache oder garantierte Wiederherstellung zu behaupten? Prüfbedarf: Yoruba-Tonzeichen, Medienbedeutung von Staffel/Folge und direkte ganze Sätze sind nicht abgesichert; vorhandenes musikbezogenes Listenwort nicht blind auf Video übertragen. Belege: [ROOT-JF](#source-root-jf).

### Primary-source registry and limits

<a id="source-eu-arg-save"></a>
- **EU-arg_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/an/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-arg-reader"></a>
- **EU-arg_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/an/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: about-reader-load-error. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-fur-save"></a>
- **EU-fur_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/fur/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-fur-reader"></a>
- **EU-fur_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/fur/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: about-reader-load-error. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-gd-save"></a>
- **EU-gd_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/gd/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-gd-reader"></a>
- **EU-gd_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/gd/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: about-reader-load-error. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-oci-save"></a>
- **EU-oci_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/oc/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-oci-reader"></a>
- **EU-oci_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/oc/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: about-reader-load-error. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-sah-save"></a>
- **EU-sah_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/sah/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-sah-reader"></a>
- **EU-sah_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/sah/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: entire22-line file; no about-reader-load-error entry. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-szl-save"></a>
- **EU-szl_save**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/szl/browser/browser/menubar.ftl>). Fundstelle: menu-file-save-page .label. Grenze: Nur ein Browsermenü zum Speichern einer Webseite; weder der komplette Revisionskonflikt noch seine Zeitrelation vor dem Speichern, das Bezugspronomen der Playlist oder die persönliche Anrede des Zielsatzes sind damit geprüft.
<a id="source-eu-szl-reader"></a>
- **EU-szl_reader**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/szl/toolkit/toolkit/about/aboutReader.ftl>). Fundstelle: about-reader-load-error. Grenze: Ein Artikel ist keine Mehrzahl von Videos und keine Serien-API-Liste. Nicht belegt sind Video-Objektflexion, Listen-/Metadatenbedeutung und die zusätzliche persönliche Retry-Aufforderung; kein einfacher Nomenaustausch freigegeben.
<a id="source-eu-arg-orthography"></a>
- **EU-arg_orthography**: [source](<https://www.boa.aragon.es/cgi-bin/EBOA/BRSCGI?CMD=VEROBJ&MLKOB=1272612550808>). Fundstelle: BOA20230420039; agreement2/2023,pages1–2 and spelling annex. Grenze: Die Veröffentlichung einer Rechtschreibnorm liefert keine Übersetzung für TV-Staffel, Episodennummer oder einen vollständigen persönlichen Validierungssatz. Keine Aussage, dass Mozilla pauschal normwidrig sei.
<a id="source-eu-arg-media-language-limit"></a>
- **EU-arg_media_language_limit**: [source](<https://lenguasdearagon.org/canal-audio/>). Fundstelle: Canal Audio, main navigation and archive descriptions. Grenze: Spanische Archivbegriffe oder katalanisches capítol sind kein Aragonisch-Nachweis. Aragonesisch arg darf nicht mit Aranesisch oder Katalanisch aus ähnlich benannten Suchtreffern verwechselt werden.
<a id="source-eu-cy-season"></a>
- **EU-cy_season**: [source](<https://www.s4c.cymru/media/media_assets/Comisiynau_Rhagfyr_2020_.pdf>). Fundstelle: page2: ENID A LUCY row, numbered season and4×60episodes; page4:Sgwrs Dan y Lloer second-series row. Grenze: Belegt die Bedeutung im Fernsehen, nicht automatisch die beste Unterscheidung von Gesamtserie und Staffel im CrozzTube-Paar. Das vorhandene Series-Label und ein mögliches identisches Season-Label müssen im selben Editor gelesen werden. Keine Freigabe der zwei vollständigen Validierungen.
<a id="source-eu-cy-production"></a>
- **EU-cy_production**: [source](<https://pac.s4c.cymru/help/cy/programmeforms/>). Fundstelle: sections Cyfres and Penodau o fewn Cyfres. Grenze: Produktionsdokumentation unterscheidet hier nicht dieselben zwei Ebenen wie der CrozzTube-Schalter plus separate Season-Nummer. Die Dokumentation verwendet außerdem eine andere Anrede; ihre Anweisungen werden nicht pauschal kopiert.
<a id="source-eu-cy-numbering"></a>
- **EU-cy_numbering**: [source](<https://pac.s4c.cymru/help/cy/contributors/>). Fundstelle: Ychwanegu Cyfranwyr i Benodau and completion sections. Grenze: Sie enthält keine Regel: genau beide Nummern oder beide leer. Sie enthält auch keine einmalige Episodennummer pro Staffel. Das sind fachlich andere Bedingungen.
<a id="source-eu-fur-programme"></a>
- **EU-fur_programme**: [source](<https://ghiti.it/video/maman-seste-stagjon-episodi-16/>). Fundstelle: programme heading, season6 navigation, related-episode list. Grenze: Belegt das TV-Datenmodell und einzelne Nomen, aber nicht die vollständige Next-episode-Aktion, Änderungs-/Ladefehler, Zulässigkeitswahl oder Zweifeld-/Eindeutigkeitsregel. Keine Ableitung aus parallel angezeigtem Italienisch.
<a id="source-eu-gd-programme"></a>
- **EU-gd_programme**: [source](<https://downloads.bbc.co.uk/alba/foghlam/tidseir/pdf/2003/fiream_faram.pdf>). Fundstelle: page2: programme-series introduction; page3 onward:numbered programme entries. Grenze: Material zu einer Reihe von Radiosendungen, keine eigenständige Staffelebene und keine UI-Validierung. Keine Freigabe eines nur kalendarisch belegten Season-Wortes.
<a id="source-eu-oci-programme"></a>
- **EU-oci_programme**: [source](<https://www.octele.com/article/produit/photo/dossier3410/Crida_a_projectes_SITCOM.pdf>). Fundstelle: Occitan-language pages4–5, especially composition-of-dossier section. Grenze: Die französischen Seiten1–3 und Höflichkeitsformen der Ausschreibung werden nicht als persönlicher UI-Satz behandelt. Zwei erste Episoden sind keine zwei voneinander abhängigen Nummernfelder; folgt daraus keine Tuple-Regel.
<a id="source-eu-sah-video"></a>
- **EU-sah_video**: [source](<https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/sah/toolkit/toolkit/global/videocontrols.ftl>). Fundstelle: videocontrols-error-aborted, -network, -decode, -src-not-supported, -generic. Grenze: CrozzTube lädt hier Serien-/Listenmetadaten. Die Mozilla-Sätze bezeichnen einzelne Medienwiedergabe oder konkrete Ursachen. Sie dürfen nicht als allgemeiner Videos-Ladefehler plus Retry übernommen werden.
<a id="source-eu-sah-broadcaster"></a>
- **EU-sah_broadcaster**: [source](<https://nvk-online.ru/support/>). Fundstelle: appFAQ, Russian-language headings/body. Grenze: Die gelesene Supportseite ist Russisch. Russischer Inhalt eines Sakha-Anbieters ist kein Beleg für Sakha-Staffel-/Episoden-UI. Ein anderer Artikel und die Homepage waren im direkten Abruf nicht zugänglich; diese wurden nicht sprachlich freigegeben.
<a id="source-eu-sah-publisher-usage"></a>
- **EU-sah_publisher_usage**: [source](<https://t.me/s/nvk_sakha/23109>). Fundstelle: publisher post advertising its own app, starting with mobile-app announcement. Grenze: Lexikalischer Indizbeleg, kein vollständiger eigenständiger Singular-UI-Titel und keine Staffel-/Episodenvalidierung. Suchauszug/wechselnde Telegram-Kontextanzeige reicht nicht für Freigabe einer neuen Gesamtformulierung. Identität nicht durch eine Browser-Anmeldung oder native Prüfung attestiert.
<a id="source-as-vlc-as-po"></a>
- **AS-vlc-as.po**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/as.po>). Fundstelle: PO revision2017-09-22; repository current at pinned commit. File names src/input/meta.c75/76 for Season/Episode.. Grenze: Whether কাল is natural for a numbered TV/video season rather than period/time; no requested complete conflict/validation/fetch-retry message.
<a id="source-as-totem-as-po"></a>
- **AS-totem-as.po**: [source](<https://github.com/GNOME/totem/blob/2bfe631b2b3f3b370e73c42188235f6ef6f4f768/po/as.po>). Fundstelle: PO revision2014-08-18.. Grenze: Full reload-before-save construction or current community register.
<a id="source-as-firefox-as-ftl"></a>
- **AS-firefox-as.ftl**: [source](<https://github.com/mozilla-l10n/firefox-l10n/blob/7317b23fbede38547681b3a61f569fdf18821bcb/as/browser/browser/preferences/preferences.ftl>). Fundstelle: 7317b23fbede38547681b3a61f569fdf18821bcb. Grenze: Personal peer-tone replacement or correct media-season terminology; source register cannot be copied blindly.
<a id="source-as-jellyfin-as-json"></a>
- **AS-jellyfin-as.json**: [source](<https://github.com/jellyfin/jellyfin-web/blob/9ac5ac07aff14fa929ccfdb9021966e7b6710b2a/src/strings/as.json>). Fundstelle: 9ac5ac07aff14fa929ccfdb9021966e7b6710b2a. Grenze: Absence from all Assamese usage or permission to invent an English loan.
<a id="source-as-wordpress-bo-zip"></a>
- **AS-wordpress-bo.zip**: [source](<https://downloads.wordpress.org/translation/core/5.8-beta/bo.zip>). Fundstelle: HTTP 200. Grenze: TV series/season/episode terminology or any complete requested conflict/paired/uniqueness message.
<a id="source-as-wordpress-dzo-zip"></a>
- **AS-wordpress-dzo.zip**: [source](<https://downloads.wordpress.org/translation/core/4.7.2/dzo.zip>). Fundstelle: HTTP 200. Grenze: TV series/season/episode terminology or any complete requested conflict/paired/uniqueness message.
<a id="source-as-wordpress-jv-id-zip"></a>
- **AS-wordpress-jv_ID.zip**: [source](<https://downloads.wordpress.org/translation/core/4.9.35/jv_ID.zip>). Fundstelle: HTTP 200. Grenze: TV series/season/episode terminology or any complete requested conflict/paired/uniqueness message.
<a id="source-as-wordpress-lo-zip"></a>
- **AS-wordpress-lo.zip**: [source](<https://downloads.wordpress.org/translation/core/6.7.11/lo.zip>). Fundstelle: HTTP 200. Grenze: TV series/season/episode terminology or any complete requested conflict/paired/uniqueness message.
<a id="source-as-firefox-bo-ftl"></a>
- **AS-firefox-bo.ftl**: [source](<https://github.com/mozilla-l10n/firefox-l10n/blob/7317b23fbede38547681b3a61f569fdf18821bcb/bo/browser/browser/preferences/preferences.ftl>). Fundstelle: 7317b23fbede38547681b3a61f569fdf18821bcb. Grenze: That this politeness level matches CrozzTube community tone; not a reviewed TV lexicon; English Save is not native attestation.
<a id="source-as-totem-dz-po"></a>
- **AS-totem-dz.po**: [source](<https://github.com/GNOME/totem/blob/2bfe631b2b3f3b370e73c42188235f6ef6f4f768/po/dz.po>). Fundstelle: PO revision2007-05-22.. Grenze: Whether the music-related playlist label is best for generic video playlists; no TV season/episode labels or requested full validations.
<a id="source-as-firefox-lo-ftl"></a>
- **AS-firefox-lo.ftl**: [source](<https://github.com/mozilla-l10n/firefox-l10n/blob/7317b23fbede38547681b3a61f569fdf18821bcb/lo/browser/browser/preferences/preferences.ftl>). Fundstelle: 7317b23fbede38547681b3a61f569fdf18821bcb. Grenze: Reloading a current playlist with optimistic concurrency, long two-number validation, or desired non-formal community register.
<a id="source-as-kodi-my-mm-po"></a>
- **AS-kodi-my_mm.po**: [source](<https://github.com/xbmc/translations/blob/d96e67e0e9db0d1c7991f99303e9842b5848c065/kodi-translations/kodi-main/merged-langfiles/kodi.core/resource.language.my_mm/resources/strings.po>). Fundstelle: Translation snapshot repository last commit2021; PO revision placeholder, not a native sign-off.. Grenze: TV-season label, current consistent tone, or complete requested validators.
<a id="source-as-vlc-my-po"></a>
- **AS-vlc-my.po**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/my.po>). Fundstelle: PO revision2013-12-08.. Grenze: A Myanmar season term; an empty msgstr is not evidence for an English fallback.
<a id="source-as-jellyfin-my-json"></a>
- **AS-jellyfin-my.json**: [source](<https://github.com/jellyfin/jellyfin-web/blob/9ac5ac07aff14fa929ccfdb9021966e7b6710b2a/src/strings/my.json>). Fundstelle: 9ac5ac07aff14fa929ccfdb9021966e7b6710b2a. Grenze: Season or numbered-season syntax.
<a id="source-as-totem-my-po"></a>
- **AS-totem-my.po**: [source](<https://github.com/GNOME/totem/blob/2bfe631b2b3f3b370e73c42188235f6ef6f4f768/po/my.po>). Fundstelle: PO revision2024-02-05.. Grenze: A whole cross-field validator or authoritative choice among sentence endings.
<a id="source-as-firefox-my-ftl"></a>
- **AS-firefox-my.ftl**: [source](<https://github.com/mozilla-l10n/firefox-l10n/blob/7317b23fbede38547681b3a61f569fdf18821bcb/my/browser/browser/preferences/preferences.ftl>). Fundstelle: 7317b23fbede38547681b3a61f569fdf18821bcb. Grenze: That a browser-tab action can replace stale-playlist wording; no blanket informal-register certification.
<a id="source-as-kodi-si-lk-po"></a>
- **AS-kodi-si_lk.po**: [source](<https://github.com/xbmc/translations/blob/d96e67e0e9db0d1c7991f99303e9842b5848c065/kodi-translations/kodi-main/merged-langfiles/kodi.core/resource.language.si_lk/resources/strings.po>). Fundstelle: d96e67e0e9db0d1c7991f99303e9842b5848c065. Grenze: Long validator syntax, register acceptance or permission to copy trailing whitespace.
<a id="source-as-vlc-si-po"></a>
- **AS-vlc-si.po**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/si.po>). Fundstelle: PO revision2017-02-06.. Grenze: A reason to replace already accepted CrozzTube Season/Playlist wording or a complete per-season uniqueness statement.
<a id="source-as-totem-si-po"></a>
- **AS-totem-si.po**: [source](<https://github.com/GNOME/totem/blob/2bfe631b2b3f3b370e73c42188235f6ef6f4f768/po/si.po>). Fundstelle: PO revision2007-10-08.. Grenze: A settled single playlist term, current error/retry wording or whole language quality.
<a id="source-as-firefox-si-ftl"></a>
- **AS-firefox-si.ftl**: [source](<https://github.com/mozilla-l10n/firefox-l10n/blob/7317b23fbede38547681b3a61f569fdf18821bcb/si/browser/browser/preferences/preferences.ftl>). Fundstelle: 7317b23fbede38547681b3a61f569fdf18821bcb. Grenze: That removing polite imperatives mechanically is natural; no per-season numbering validator in this source.
<a id="source-as-jellyfin-si-json"></a>
- **AS-jellyfin-si.json**: [source](<https://github.com/jellyfin/jellyfin-web/blob/9ac5ac07aff14fa929ccfdb9021966e7b6710b2a/src/strings/si.json>). Fundstelle: 9ac5ac07aff14fa929ccfdb9021966e7b6710b2a. Grenze: Universal absence or acceptance of existing terms.
<a id="source-root-jf"></a>
- **ROOT-JF**: [source](<https://github.com/jellyfin/jellyfin-web/tree/9ac5ac07aff14fa929ccfdb9021966e7b6710b2a/src/strings>). Fundstelle: Inspected directory snapshot and ug.json at this revision. Grenze: No relevant TV season/episode/next keys in the inspected 26-key ug pack; no matching am/azb/ps/sd/snd/ty/tah/yo/yor files under the inspected names. This is not proof that no translation exists elsewhere.
<a id="source-root-vlc-am"></a>
- **ROOT-VLC-am**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/am.po>). Fundstelle: Season/Episode metadata entries and Playlist entry. Grenze: Season and Episode msgstr are empty in this snapshot. Playlist vocabulary alone does not verify any complete requested statement; an audio/music-oriented noun is not automatically a video-playlist instruction.
<a id="source-root-vlc-ps"></a>
- **ROOT-VLC-ps**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/ps.po>). Fundstelle: Season/Episode metadata entries and Playlist entry. Grenze: Season and Episode msgstr are empty in this snapshot. Playlist vocabulary alone does not verify any complete requested statement; an audio/music-oriented noun is not automatically a video-playlist instruction.
<a id="source-root-vlc-ug"></a>
- **ROOT-VLC-ug**: [source](<https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/ug.po>). Fundstelle: Season/Episode metadata entries and Playlist entry. Grenze: Season and Episode msgstr are empty in this snapshot. Playlist vocabulary alone does not verify any complete requested statement; an audio/music-oriented noun is not automatically a video-playlist instruction.

### Remaining integration evidence and real client boundary

The existing guarded MySQL runner now executes the real registered routes,
Sanctum/account middleware and four localized error paths on the isolated MySQL
database too. The direct run passed 3,593 routed assertions, 69 service checks
and 24 assertions across four actual InnoDB lock waits. The database marker,
private network namespace, memory-backed storage and cleanup safeguards are
unchanged. This closes the earlier routed-HTTP/MySQL coverage gap within the
fixture; production TCP, full kernel/cookie/CSRF and live rollout are distinct
boundaries, not extra requirements invented from missing claims in a journal.

The active Codex PreToolUse hook actually intercepted the harmless deny-probe
before execution. The existing runtime-check then passed and status reported
runtimeHookVerified=true. No hook trust, definition or receipt was synthesized
or edited; the session-bound receipt stays outside Git. This observation is
not inferred from configuration and does not establish another client's runtime.

Cursor inventory still provides only the GUI launcher; no usable Cursor-agent
command, running agent session or Cursor-specific controlling connector was
available. A real roundtrip still needs three observed legs in the same existing
checkout/journal: Cursor reads the current core/skill and writes a controlled
handoff; a fresh Codex process recovers and updates that exact revision; a fresh
Cursor process recovers the Codex change. Existing Codex-only restart evidence
is retained and not relabeled as Cursor execution. No login/install/trust bypass
or model-role simulation is substituted.

The three functional plan criteria for step 8 already have source/service/router,
MySQL/concurrency, built-UI and CI evidence. The remaining translation questions
and cross-client acceptance remain open. Normal completion still requires the
existing plan-branch verification/publication path and any explicit integration
permission. A series checkpoint does not grant main merge, deployment or step 9.

## Scoped Welsh/Occitan follow-up (2026-11-15)

Five messages receive a new whole-message editorial reading and independent
context pass: cy/T and oci/R/M/P/D. Remaining count: **154 missing messages
in 21 partial/open catalogs**; 104 catalogs still contain all nine scoped keys.
Current differences from the historical 159-message inventory: cy has only P/D
open; oci has only L open. All other listed missing-message IDs remain unchanged.
The historical per-message questions above are retained as the review trail.

Welsh Season uses Cyfres, attested in numbered S4C TV editions. The overall
Series checkbox/disclosure and numeric Season field have distinct roles; using
the same normal Welsh noun is not by itself an error. The added browser context
checks distinguish checkbox and spinbutton explicitly rather than accidentally
matching the checkbox as a numeric field. Welsh paired/duplicate validation
sentences are still unaccepted.

Occitan messages keep existing lista de lectura, sason and episòdi terminology.
R describes the revision change then asks to reload before saving; M restricts
selection to this playlist; P explicitly pairs the two number fields or leaves
both empty (season 0 remains valid); D prohibits a second occurrence only within
the same season. Both editorial passes checked complete messages and actual
controller rules, with no placeholders added or removed. This is scoped AI
editorial/context review, not native certification or full-catalog acceptance.
The independent second pass additionally checked void against
[Lo Congrès Dicodòc](https://dicodoc.eu/oc/diccionaris?dic%5B0%5D=BASIC&dic%5B1%5D=CANTAL&q=ja&start=300&submit=Cercar&type=oc-oc),
which attests void as a synonym/gloss of vuèg; this lexical support does not claim
the entire validation sentence was copied from that source.

Occitan L stays open: the candidate definite plural las vidèos needs register-
specific checking. Institutional Gascon usage supports feminine video plurals
([Lo Congrès](https://locongres.org/actualitats/naveras-ressorsas/oc-vod-plataforma-navera-de-video-a-la-demanda)),
but is not silently treated as a complete Languedocian error/retry sentence.
No unavailable or uncertain message is relabeled as accepted.

The fixture adds only these two partial contexts to language selection; the
complete-catalog suite remains separate. Occitan's four actual routed API errors
are now included by the existing dynamic server test. New browser cases cover
its four editor messages and retained drafts, plus Welsh labels in both themes
and narrow layout. Counts and publication evidence belong to actual test logs
and the journal, not inferred from the presence of these tests.

Per the owner's separately approved CD-006 correction, editor interoperability
is a nonblocking, not-verified Orqen concern. Historical Cursor-blocker statements
in this document are superseded for product acceptance. Real Codex hooks and
all product/harness/merge requirements remain unchanged.
