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

The remaining 73 catalogs still lack these new messages; translation and
linguistic/context review stay open. The canonical ru/russian.php catalog remains open because the existing series
path gate excludes that filename; it was neither renamed nor forced through.
No historical tools/i18n/reviews record is
silently repurposed. This message/locale/context matrix is the scoped review
record for this batch, not complete language acceptance or native verification.

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
