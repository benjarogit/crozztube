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

## Current follow-up: complete Welsh/Occitan contexts and scoped Asian copy

Source reference: 12cc4c9 (2026-11-15). Thirteen additional whole messages have
an editorial proposal pass, an independent whole-message/context pass and a
separate bounded review. This is AI editorial evidence, not native certification
or acceptance of all existing text in a language pack.

- cy: paired-number and per-season-duplicate rules complete the nine series keys.
  Rho/gad and Paid â are personal singular commands. The paired rule explicitly
  names both numeric fields or both empty fields; season 0 is not forbidden.
  The duplicate rule permits S1/E1 plus S2/E1 and rejects two S1/E1 entries.
- oci: the load/retry message completes the nine series keys. A newly inspected
  [Lo Congrès Languedocian article](https://locongres.org/actualitats/linstitucion-e-los-sons-socis/revirada-lo-traductor-automatic-occitan-del-congres)
  attests feminine la vidèo. Las vidèos is regular editorial plural formation;
  neither that source nor Mozilla is claimed to certify this entire new sentence.
- as: revision/reload, current-playlist selection and loading/retry are supplied
  as whole personal instructions. U+0027 is retained in the video and negative-past
  word forms, matching the existing spelling. This sign has an orthographic role,
  not merely decorative punctuation; the [Unicode Assamese ad-hoc report](https://www.unicode.org/L2/L2018/18205-n4999-assamese-adhoc.pdf)
  describes multiple representations, not canonical equivalence or permission
  for global smartquote rewriting. The TV-season noun and both season-dependent
  validators remain unaccepted; no guessed term or internal placeholder is added.
- my_mm: current-playlist selection and data-retrieval/retry are supplied. The
  precise media-playlist term is attested by [Kodi message 559](#source-as-kodi-my-mm-po)
  and used only in the new selection message;
  the legacy broader wording is not silently rewritten. Normal gentle imperative
  particles are not treated as formal customer honorifics. Retrieval does not
  promise a file download or assert a codec/network cause. Sentence-level
  editorial confidence is moderate; there is no claim of native certification.
  Season, revision/reload and both season-dependent validators remain open.
- si_lk: revision/reload, selection, paired fields, per-season duplicates and
  load/retry complete the nine series keys. Ordinary Sinhala imperatives and
  colloquial explanations avoid customer honorifics. The duplicate paraphrase
  prohibits two episodes with the same number inside the same season; it does
  not impose global uniqueness, equal paired numbers or gapless numbering.

Current inventory: **141 absent/unaccepted messages in 18 partial/open catalogs**;
107 of 125 catalogs contain all nine scoped series messages. Since the historical
154-message inventory, cy, oci and si_lk have no missing series keys; as retains
T/P/D and my_mm retains T/R/P/D. Every other previously listed missing message
is unchanged. Older 159/154 counts above are historical review snapshots.

Tests retain every previously required locale and add complete CY/OCI/SI
requirements. The actual routed test now runs each available partial error
without pretending all four are present: new AS R/M and MY M are individually
required. Full and partial coverage are reported separately. The browser fixture
uses a deduplicated language list, type-specific numeric-field queries and
explicit known-key maps; unresolved Season labels in AS/MY are not called
translated. Targeted flows exercise only reviewed messages, preserve creator
input, show loading/retry states, check narrow layout in both themes and capture
rendered context images. Actual commands/counts/publication are recorded in the
journal and CI; the presence of a test alone is not a passed execution.

PR #3 was freshly inspected at ac406456 without rerunning unchanged tests.
Its source-bound local evidence and hosted run 43710076509 show 131 passes;
actual GitHub state is open/non-draft/mergeable/clean, with no unresolved threads.
CodeRabbit's neutral rate-limit comment is not substantive approval. The current
API cannot read branch-protection/ruleset details (403); these requirements are
unknown, never called passed. Technical READY does not supply an explicit owner
merge authorization. PR #3 remains before #4; no merge or deployment is performed.
No Orqen installation or migration infrastructure is added to CrozzTube.

## Current follow-up: Friulian and Lao contexts

Source reference: 1146c49 (2026-11-15). Six Friulian and five Lao whole messages
receive a proposal reading, independent contextual readings and bounded source
checks. Existing values remain intact. Current count: **130 absent/unaccepted
series messages in 16 partial/open catalogs**; 109 of 125 catalogs contain all
nine scoped series keys. The earlier 159/154/141 inventories are historical.
Since the preceding batch, only fur/N/R/M/P/D/L and lo/R/M/P/D/L are resolved.
Aragonese's nine messages remain open: the fresh review did not establish
sufficient confidence in normative TV terminology and whole-message register.

For Friulian, independent review found and corrected the omitted definite
article in the both-fields construction. The final phrase retains the article
before cjamps, supported by [University of Udine language-center prose](https://cirf.uniud.it/cors/an-2021/cors-di-lenghe-furlane-par-dipendents-de-aministrazion-publiche).
The noun plural videos is chosen using the [Friulian dictionary's video entry](https://dizionarifurlan.eu/headword/video).
ARLeF also uses the invariant UI variant video; this variation is acknowledged,
not mislabeled a certain language error. The dictionary's invariant adjective
sense is not proof that its noun is always invariant. Singular imperatives,
feminine list agreement, before-saving order, both-empty fields and per-season
uniqueness are checked as whole sentences. No sta with optional a is supported
by [ARLeF grammar](https://arlef.it/app/uploads/materiali/sff_gramm_visintin_zanello.pdf);
the reviewers distinguish indexed excerpts from a full PDF read, which exceeded
tool limits. Accent variation between sources is not invented into an error.

The original Friulian proposer reread the corrected sentences. The independent
reviewer supplied M/P/L corrections; those corrections were separately checked
by the parent and original proposer, rather than counted as that reviewer's
independent approval of their own wording. Another reviewer explicitly declined
a Friulian grammar sign-off beyond semantic checks; that is not counted as a
language acceptance. These are bounded AI editorial judgments with moderate
confidence, not native certification or full existing-catalog review.

For Lao, accepted playlist/video/season/episode terminology is retained.
Commands omit formal customer address without adding rude or forced slang.
Reloading precedes saving; selection is from this particular playlist; paired
numbers may differ and season zero remains valid; duplicates are restricted to
the same season. The loading/retry statement names no network/codec cause or
successful recovery promise. Complete sentences, script and exact placeholder
sets were read independently; moderate confidence for the two validators is
explicit, with no native or whole-language certification claim.

Fur and lo are added to retained complete-key and real API-error requirements.
Their targeted browser cases require all nine actual entries, distinguish
numeric fields by role, preserve input through conflict/validation errors and
exercise load/retry in both themes at narrow width. Known AS/MY partial mappings
remain unchanged. Missing keys cannot be hidden by English fallback or by a
checkbox accidentally matching a numeric-field label. Actual commands, counts,
review hashes and publication are recorded after execution, not inferred here.

### Separate operational hook-evidence finding

During the current security recheck, the active PreToolUse hook really blocked
the harmless negative probe before command execution. The existing strict
runtime-check nevertheless rejected the private local receipt log because one
line was malformed. A fresh well-formed blocked event had a session and matching
hook digest; this did not override rejection of the malformed log. No receipt
content, identifiers or raw lines are copied here; no receipt was edited,
deleted, fabricated or manually repaired. The cause of corruption is not proven.
The existing writer reads and rewrites its bounded JSONL file without a lock;
this observation is not a claimed concurrency reproduction.

This actual evidence-integrity gap is separate from optional editor compatibility.
It remains not verified for step completion unless the existing gate accepts a
legitimate fresh runtime record. Hook/gate changes or recovery need their own
applicable approval; no trust or safety requirement is weakened. Ordinary
independent copy work continues, but no step-8 completion/main merge/deployment
or step 9 is asserted. No Orqen installation/migration infrastructure is added.

## Follow-up: receipt race diagnosis and manual PR #3 decision

Reference: published series checkpoint 3909088 and tooling head ac406456.
The original receipt writer was exercised unchanged in an isolated temporary
repository with two real child processes and an injected deterministic I/O
schedule: A-open, B-open, A-write, B-shorter-write. Both hooks exited successfully,
but the shared file contained one malformed JSONL line. This reproduces a concrete
read/truncate/write race; the historical scheduling of the actual damaged log
cannot be reconstructed and is not claimed proven. No active receipt was changed.

Independent source review also found that the existing catch block can replace
an already computed PreToolUse/legacy denial or Stop block with only a generic
systemMessage after receipt I/O fails. This inherited defect predates PR #3.

A repair draft outside the repository serializes the entire read/retention/write
operation under an exclusive bounded lock, publishes complete JSONL by atomic
rename, keeps restrictive permissions and preserves computed denials/Stop blocks.
It refuses silent parsing repair and stale-lock removal. Explicit corruption
recovery archives the exact original bytes privately, creating no replacement
runtime evidence; a fresh real trusted probe remains required. The strict
runtimeEvidence parser, session/digest checks, hook configuration and trust remain
unchanged. Hard-crash durability is not asserted from rename alone.

The root and a separate reviewer each actually ran ten isolated regression
checks, including 24 concurrent writers, retention, corruption rejection, archive
preservation, fresh fixture evidence versus changed digest, lock contention,
injected publication failure, malformed input and bounded Stop behavior. These
are fixture results, never live-runtime proof. Independently reviewed draft hook
SHA-256: 867a98a0c074d401273892f84a71d7c4f0581ef643ec3e421d6e9531a95a54ac.

Publication is genuinely blocked by both current path gates: neither the series
scope nor ChatDev scope permits codex-hook.mjs changes. The repair instruction
does not silently rewrite those allowlists. A concrete narrowly bounded scope
extension is prepared for separate authorization: exact reviewed hook blob and
historical blob checks, dedicated regression test required in tooling verification
and CI, existing-path documentation, plus exactly one canonical fourth parent
integration bound to the final published feature and repair heads. Existing three
merges, all checks, strict evidence rules, plan/progress and trust remain intact.
No active hook, log, gate or parent integration has been changed for this draft.

PR #3 was freshly inspected at ac406456: clean/published, 131 hosted contracts
tests passed, mergeable/CLEAN, no formal review or unresolved thread. CodeRabbit's
neutral/rate-limit walkthrough is not code approval. The workflow uses pinned
actions, read-only contents permission and no persisted checkout credentials.
Branch-protection and ruleset APIs both return 403; their requirements are unknown
and need confirmation in GitHub's UI before an authorized manual merge. Technical
READY is advisory; explicit owner merge permission is absent. Recommendation:
resolve and verify the inherited hook defect before the final merge decision.
After separately authorized exact-head rechecks, use an ancestry-preserving merge
for #3 before #4. Do not squash/rebase away the parent history or automatically
merge, retarget, deploy, accept step 8 or begin step 9.

## Current follow-up: Assamese media term and Gaelic revision message

Reference: 3909088. Three Assamese and one Scottish Gaelic messages are added
without replacing existing entries. Current inventory: **126 absent/unaccepted
series messages in 15 partial/open catalogs**. 110 of 125 packs contain the nine
scoped keys; this count is not full-catalog linguistic certification.

For Assamese, two directly read publisher sources attest numbered TV seasons:
[Assam TV9](https://www.assamtv9.com/entertainment/these-stars-can-replace-salman-khan-in-bigg-boss-ott-4-2080062.html)
uses ছিজন, ছিজনৰ and ছিজনত in its own prose, while
[Hindusthan Samachar Assamese](https://assamese.hindusthansamachar.in/Encyc/2026/8/7/trailer-The-Traitors-India-Season-2-released.php)
also distinguishes season and episode. These sources attest TV terminology,
not the validators as complete sentences or the only valid orthography. A
Google Play search-index example was supplementary only: direct access returned
403, so no inspected UI or current-runtime acceptance is claimed from it.

The whole-message proposal and separate contextual readings check the singular
personal instructions, both distinct numbers or both empty, season zero and
duplicate episode numbers only within the same season. The Assamese apostrophe
in হ'ব remains ASCII U+0027, consistent with existing reviewed spelling; it is
not stripped or smart-quoted. Whole-message judgments are bounded AI editorial
review with moderate confidence, not native certification.

The Gaelic revision message says the playlist changed and asks the reader to
reload the list before saving. Its explicit noun avoids an uncertain object
pronoun. Singular Luchdaich and thu address the user personally. Existing pinned
Mozilla Gaelic sources inform the language context; they do not supply a blanket
grammar endorsement. The whole instruction received a proposer reading and a
separate root reading. No Gaelic season or video-spelling question is resolved
by accepting this one message; five Gaelic messages remain open.

The fresh Myanmar review leaves all four outstanding messages open. Newly read
official Kodi matrix/nexus resources still leave Season/Seasons empty; this does
not prove that the language has no suitable term. A Refresh loan alone cannot
justify an entire reload-current-playlist instruction. The possible distinction
between retrieve/restore and refetch remains a concrete whole-message review
need. The remaining Silesian/Aragonese proposals were also declined where the
reviewer could not support the complete grammar/register; neighboring-language
substitutions are not accepted.

The routed and browser fixtures retain all earlier checks, require all nine AS
entries and explicitly require the one new Gaelic conflict message while leaving
its unresolved keys partial. Both-theme narrow editor contexts retain typed
content through the real conflict/validator paths. Execution counts, source
review and publication are recorded after actual checks in the existing journal.

## Javanese loading-only follow-up

Reference: published 27de4ee. One new whole message is added in jv_id:
`Ora bisa ngemot video. Coba maneh.` It expresses a neutral video-list loading
failure and a personal retry action, with no invented network/codec cause or
recovery guarantee. Existing Video/Videos and Coba maneh terminology and plain
orthography are retained. Existing catalog entries are not replaced.

The proposing reviewer, an independent second reviewer and the root read the
complete two-sentence message in its Watch error/retry context. The official
[WordPress Javanese 4.9.35 package](https://downloads.wordpress.org/translation/core/4.9.35/jv_ID.zip)
attests ngemot video and retry constructions. Package SHA-256:
1978c90f3483af3a7003bec854d97ed5cd3bcecfcab69eafda40b495d04e2752.
This old official resource supports the vocabulary, not current native or whole
catalog certification. The judgment has bounded moderate editorial confidence.

The other proposed Javanese instruction remains unaccepted: its broader video
playlist term differs from the existing song-list-specific Dhaptar lagu. This
pass does not silently migrate a shared glossary. Javanese therefore still has
eight unresolved scoped messages. Current total: **125 absent/unaccepted series
messages in 15 partial/open packs**, with 110 complete nine-key sets.

The browser fixture explicitly requires only this real loading key for jv_id.
It checks language/catalog selection, light/dark 390px loading failure, retry,
overflow and unchanged creator title. No missing Series/Season/Episode/Next
episode label is called translated. Existing complete/partial editor and routed
API requirements remain unchanged; this client loading fallback is not counted
as a newly translated server validator. Actual execution and publication results
belong to the existing journal/PR, not a guessed acceptance from key count.

## Pashto selection and loading follow-up

Reference: published 6b984b29. Two complete messages are added to `ps`:
`له دې پلی لیست څخه ویډیوګانې غوره کړه.` for selecting videos from this
playlist, and `ویډیوګانې لوډ نه شوې. بیا هڅه وکړه.` for loading failure and
retry. The proposing reviewer, a separate reviewer and the integrating editor
read both whole messages against their membership-validation and Watch-list
error contexts. Plural videos, the specific source playlist and personal singular
commands are retained; no cause or recovery guarantee is invented.

The pinned official [VLC Pashto catalog](https://github.com/videolan/vlc/blob/5ff4fb8562e6df80bb7312b279893cbc32696957/po/ps.po)
(SHA-256 `a8e66d82093879bbd690debf30bc1aaeecd233defb47cf3e24845d9f67e4c4c8`)
supports media vocabulary only. Its empty Load entry does not certify the new
loading sentence. `لوډ` is an explicitly bounded software-loan decision; the
whole-message review has moderate AI editorial confidence, not native-speaker
or full-catalog certification. Existing Playlist terminology is preserved.

Seven Pashto series messages remain open: S/T/E/N/R/P/D. Current total:
**123 absent/unaccepted scoped messages in 15 partial/open packs**, with 110
complete nine-key sets. The fixture requires only the two reviewed keys in this
pack, including narrow light/dark RTL, unchanged creator input and retry.
It does not call fallback series/season/episode labels translated. Actual test,
review and publication results are recorded after execution in the journal.

## Scottish Gaelic loading-only follow-up

Reference: published fd32b22. One complete loading/retry message is added in
`gd`: `Cha b’ urrainn dhuinn na bhideothan a luchdadh. Feuch ris a-rithist.`
The whole message received proposer, independent and integrating readings for
neutral video-list loading failure and a personal singular retry. The complete
sentence is a bounded editorial synthesis with moderate AI confidence, not a
verbatim primary-source translation or native/full-catalog certification.

Pinned [Mozilla preferences](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/gd/browser/browser/preferences/preferences.ftl)
attest the past-inability construction; [Mozilla content-loading controls](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/gd/browser/browser/newtab/newtab.ftl)
attest the exact personal retry and loading vocabulary. The official
[Bòrd na Gàidhlig board papers](https://www.gaidhlig.scot/wp-content/uploads/2021/06/BnG-01-2122-00-Paipearan-Fosgailte-2.pdf),
PDF page 21 / printed page 73, directly attest `na bhideothan`, resolving the
inherited e-spelling concern without changing shared terminology. None of these
sources individually certifies the whole new message.

Four other Gaelic scoped messages remain open. The updated scoped inventory is
**122 absent/unaccepted messages in 15 partial/open packs**, with 110 complete
nine-key sets. Existing reviewed S/E/N/R messages and creator content remain.
The targeted fixture now requires this real loading key and checks narrow
light/dark error display and retry; it does not claim missing season/numbering
messages are translated. Actual checks and publication must be recorded after
execution; adding the key alone does not finish its contextual acceptance.

## Approved stable-tooling continuation for this feature

The selected feature base remains published
`544b702620f3955c60e7414dcc1fe93f0791fa44`, with the five existing canonical
integrations unchanged. At the loading-only packet the approved tooling runtime was
`8cdbe72bc3dde6b3b8074b7446d7918ed8c682cb`; its actual 202-test harness and
hosted run 43730717269 passed, and fresh exact-main-ref review plus explicit
private runtime acceptance were observed. This does not accept step 8 or merge
either PR. Feature checkpoint and hosted results remain separate evidence.

The feature intentionally retains historical tooling files from its selected
base. Use the reviewed published external runtime for authoritative ChatDev
context, Project Manager, PR audit and series checkpoints, rather than its older
inherited audit CLI. From the feature directory, with `CT_TOOLING_ROOT` pointing
to the existing reviewed tooling checkout:

```sh
node "$CT_TOOLING_ROOT/tools/chatdev/cli.mjs" context
node "$CT_TOOLING_ROOT/tools/chatdev/cli.mjs" project-manager
node "$CT_TOOLING_ROOT/tools/chatdev/stable-series.mjs" status
node "$CT_TOOLING_ROOT/tools/chatdev/stable-series.mjs" verify
node "$CT_TOOLING_ROOT/tools/chatdev/stable-series.mjs" checkpoint --message "..."
```

The directory variable only locates the script; no base/policy override exists.
Runtime provenance, source/index, fresh policy and revocation checks remain
mandatory. Ordinary upstream documentation needs an exact independent relevance
review; code changes need a reviewed runtime release, not an automatic feature
parent merge. The legacy local series gate remains strict. Actual GitHub target
refs and merge coverage are reviewed separately from the immutable feature base.
No sixth integration, main merge/retarget, deployment or step advance is allowed.

## Scottish Gaelic current-playlist membership follow-up

Reference: published b8caca6. One complete membership instruction is added:
`Tagh bhideothan bhon liosta-cluiche seo.` Proposer, independent and integrating
readings checked the entire statement against selection of one or multiple
members of this current playlist. It adds no owner-only, upload, create,
select-all or reordering restriction. Personal singular `Tagh`, plural videos
and the specific source playlist are retained. Confidence is bounded moderate
AI editorial judgment, not native or full-catalog certification.

The official [Fèisean nan Gàidheal page](https://www.feisean.org/proiseactan/ico-iomairt-chiuil-na-h-oigridh/)
directly attests the retained `liosta-cluiche` spelling for embedded media; its
observed source SHA-256 is
`fef85427ec28bfde0e0ece8e6c7108176bcc8f7f9baf1d609073c42f5cb2cc3c`.
[National Museums Scotland's Gaelic language plan](https://files.nms.ac.uk/production/Documents/About-us/Gaelic%20language%20plan/gaelic-language-plan-2023-2028-gaelic.pdf)
and the [RSHP Gaelic instruction](https://rshp.scot/wp-content/uploads/2021/09/Part-1.-Stereotypes-and-Equality-Gaelic.pdf)
attest personal select-from-list constructions. Source hashes respectively:
`7aa348f43ad033aa9fd3bcd82eab6209d3b3f198408e871a53471f0b0f80e22e`
and `c95353656190e271275b897554d665f42da8da91a214752b6a5dbc30fb33badd`.
These support the construction and terminology, not the full synthesized
sentence verbatim. No accepted shared term is silently migrated.

Gaelic still lacks season and the paired/duplicate-number messages. The source
inventory is now **121 absent/unaccepted scoped messages in 15 partial/open
packs**, with 110 complete nine-key sets. The new fixture requires membership
display and unchanged creator input in narrow light/dark flows. Real API,
full-checkpoint and publication results must be recorded after execution; key
presence alone does not certify acceptance.

For this membership packet the reviewed published runtime is
`aa7fec7ec4c77f2e28784edb7ea9a56ed3d828b5`. Its actual 209-test harness,
hosted run 43734594544 and fresh explicit private runtime acceptance passed.
The feature base remains `544b7026…`, with the same five integrations. The
external CLI commands above use this accepted runtime in the existing tooling
checkout; no tooling files are imported into the feature.

Its schema2 audit derives the current PR target only from the exact independently
read branch ref. `reportedBaseSha` separately preserves GitHub's historical
opening base, as described in the [official GitHub documentation](https://docs.github.com/en/pull-requests/how-tos/create-pull-requests/changing-the-base-branch-of-a-pull-request).
Reviews/tests/dispositions bind the actual current target. Old schema1 evidence
is not silently reinterpreted, and an unreadable ref never falls back to the
historical value. Source/index, trusted hooks, secrets, before/after/pre-write
ref checks, independent review, actual CI and confirmed publication remain
mandatory. The normal tested-commit/push/GitHub/PM cycle already exists in
AGENTS.md and CORE.md and is reused; no parallel project management is added.

## Scottish Gaelic numbered-season and paired-field follow-up

Reference: published c9abfb5. This bounded packet adds `Seusan` for the numbered
TV-season field and the complete instruction to enter both numbers or leave
both fields empty. The existing `Sreath` and `Prògram` terms are preserved.
The quoted `Seusan`/`Prògram` labels identify only the two number fields, not
the separate creator-authored title. Season zero counts as a supplied number;
the numbers need not be equal, sequential or globally unique.

The proposer, separate whole-message reviewer and integrating reading checked
the complete instruction against the editor and service's XOR-null validation.
Personal singular entry/leave commands retain both alternatives. This is
bounded moderate editorial confidence, not native or whole-language approval.

The active [GNOME Totem Gaelic catalog](https://raw.githubusercontent.com/GNOME/totem/master/po/gd.po)
directly uses the proposed season noun with numbered TV seasons/episodes and
a television-series example. Observed source SHA-256:
`2a9ba9c1c1e923c5379cbc7694b4ea1d0de9409d9d56dbd50911cbb7ceca7ba5`.
Its 2018 revision is terminology evidence, not proof of current universal usage;
its alternative episode/media spellings are not imported.
The [pinned Mozilla form-validation source](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/gd/dom/chrome/dom/dom.properties)
attests field and personal number-entry terminology; SHA-256:
`158734edbad8e0bf4335a98c385397fa435be67ff490deb668adfdf29210ff69`.
The [Gaelic adjective lesson](https://www.teanga.info/gaidhlig/ionnsachadh/ECG/19.html)
supports the predicative empty state; SHA-256:
`7c5a0f32cfcaebcb2341cbbf11de220d78c0d31ba5a67f959dcc652b82e89dcd`.
These are component/grammar sources; the complete paired-field instruction is
reviewed editorial synthesis, not a verbatim source quotation.

At this two-message review the duplicate-number instruction remained unaccepted. Its grammar/meaning
review found no identified defect, but the additional school-PDF instruction
could not be directly retrieved and verified. An indexed excerpt is not treated
as a read primary source. The packet does not reduce this unresolved item.

The prospective inventory is **119 absent/unaccepted scoped messages in 15
partial/open packs**, with 110 complete nine-key sets. Actual narrow light/dark
field/error context, source-bound full gate, publication and current-target CI
must be observed before this packet is reported as verified and published.

## Gaelic uniqueness and Aragonese loading evidence follow-up

Before publication, further primary research resolved the Gaelic instruction's
component-source gap. A different, genuine [Stòrlann exercise PDF](https://www.storlann.co.uk/PDFs/goireasan/criomagan-litreachais_final.pdf)
is directly readable at PDF page 19 / printed page 17, exercise D. It supplies
a positive personal instruction with an explicit once-only condition. Observed
source SHA-256:
`511932da80335e773d4de7fec52315fa921b7aaacd58feda80f43bf88b9b8ca5`.
The proposer, independent reviewer and integrating reading observed this
source; the old Glowscotland PDF remains unverified, not retrospectively read.

The complete new duplicate-number text is editorial synthesis, not a quotation
from that exercise. Its singular negative instruction forbids more than one
episode sharing a number **within each season**. Existing `Prògram` and `Seusan`
terms are retained. Different seasons may reuse an episode number; season zero,
gaps and unnumbered videos keep the existing service rules. The school exercise's
requirement to use every word is not imported as compulsory video numbering.
The complete sentence received separate moderate editorial readings; no native
or all-dialect certification is asserted.

The Aragonese loading message is a whole attempted-load failure plus personal
retry, without inventing network, playback, format or expiry causes. The
[pinned Mozilla add-on source](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/an/toolkit/toolkit/about/aboutAddons.ftl)
attests the impersonal failure construction with a plural object; SHA-256:
`248584b61c39d26a8a23465779d85e29f505cf78bb410c56e496252471174b5f`.
The [pinned new-tab source](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/an/browser/browser/newtab/newtab.ftl)
supplies the personal retry, web-video noun and loading vocabulary; SHA-256:
`f6327b965f15bbee082f6afc7435c25a8b99ba3b8b76dd79c407e7008d212c25`.
Whole-message proposer, independent and integrating readings support bounded
moderate confidence. The Silesian candidate remains pending: its noun form has
an additional direct usage example, but the complete loading complement still
needs a reliable idiomatic reading. No cognate-only acceptance is made.

The final combined packet changes four messages relative to c9abfb5. Its
prospective inventory is **117 absent/unaccepted scoped messages in 14 partial
packs**, with 111 complete nine-key sets. This completes the nine scoped Gaelic
keys, not the Gaelic catalog as a whole. The earlier two-message source had
3838 HTTP/API and 4228 Chromium checks; those are historical observations of
that intermediate snapshot. The combined final source needs its own full
verification, trusted-hook observation, checkpoint, confirmed push and CI.

## Silesian metadata terminology and complete loading follow-up

Reference: published ed7c6b05. This packet adds only the numbered TV-season
label `Sezōn`, whole-video episode label `Ôdcinek`, and a complete metadata-list
loading failure with personal retry. It does not translate provider chapters,
author titles or a series-wide next-episode instruction. Season zero and the
existing number/nullability rules remain unchanged.

New primary evidence materially resolves the earlier loading-complement hold.
The [official pinned Kodi Silesian catalog](https://raw.githubusercontent.com/xbmc/translations/59806e24e28680e82a489bd7c602e9296769bd92/kodi-translations/kodi-main/merged-langfiles/kodi.core/resource.language.szl/resources/strings.po)
has SHA-256 `3cef07ffe6ebffd70298e3d61712f26e1db51e17273c225835cdc184ca0b7c06`.
Its active metadata error #500 attests the whole nominalized-loading failure
frame; #19236/#19238 attest plural media complements, and #12011/#20349 identify
the video's plural noun form. This is stronger context than a Polish cognate
or an isolated noun match. Active TV metadata labels #20373/#20359 directly
attest the two proposed field names, rather than weather or chapter meanings.

The exact personal retry comes from the [pinned Mozilla new-tab source](https://raw.githubusercontent.com/mozilla-l10n/firefox-l10n/7317b23fbede38547681b3a61f569fdf18821bcb/szl/browser/browser/newtab/newtab.ftl),
SHA-256 `b60d9807e823217591e5b0db361901d78313919bda2765b2bc671cbf17fe1bb7`.
Proposer, separate whole-message reviewer and integrating readings support
bounded moderate confidence for the complete load/retry pair. It is editorial
synthesis, not an upstream verbatim whole sentence or native/full-pack approval.
It gives no invented network, codec, expiry, empty-result or playback cause.

The whole-series heading remains pending: the attested TV-specific noun does
not yet reliably cover generic creator/nonfiction web series. The complete
next-episode phrase remains missing too. The fixture checks the new fields and
load/retry in narrow light/dark flows while explicitly preserving English
fallback for those unreviewed headings; earlier targeted translation keys and
assertions remain required. This is not acceptance of the fallback as Silesian.

The prospective inventory is **114 absent/unaccepted scoped messages in 14
partial packs**, with 111 complete nine-key sets. PHP, structural, whole-context,
full-gate, publication and current-target CI evidence must be observed on this
final source before reporting the packet as verified and published.

## Owner-approved PR4 main alignment

PR3 was merged as ed068c9f7f0afb5708effb688fbcf31915b94349. The owner then
separately approved changing only PR4's target from codex/chatdev-integration
to main at unchanged published feature head ed7c6b05. PR4 remains open/draft.
No feature merge, rebase, history rewrite or sixth parent integration occurred.
The stable feature base stays 544b7026 and the accepted external runtime stays
frozen at aa7fec7; these are distinct from the PR's actual target branch.

The previous target aa7fec7 and main ed068c9 have identical tree c7c79ede;
both merge bases with ed7c6b05 are 544b7026. Complete --binary --full-index
three-dot diffs were byte-identical (SHA-256
`b734032399fedcf27fd03cd62341a35d4deb7ecb7bbf4ac169dd79d878cb0340`).
Both computed merge trees are 047e9b5788ab02e75a9327c4b8850dc85a326198.
The old AA7/ED7 hosted run43740867210 is retained as historical evidence,
not silently rebound to main. New reviews and CI must bind the actual main
ref and the next genuine language checkpoint's exact head/merge checkout.

The existing pull_request workflow defaults to opened/synchronize/reopened;
a base-only edited event does not guarantee a fresh run. A previous-run rerun
keeps its original SHA/ref, and manual dispatch would skip the head_ref-gated
series jobs. The next genuine language commit uses the existing synchronize
path; no synthetic commit, workflow patch or PR close/reopen is introduced.
No merge, deployment or step transition is authorized by this alignment.
