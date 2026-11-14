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

New series strings have reviewed English and German regional reference entries.
The remaining installed languages still need linguistic review of these new
messages; the pre-existing complete language acceptance remains deferred. A
structural catalog audit is not a claim of linguistic completeness.

## Prüfungen und Grenzen

```sh
bash tools/test/step-08-series.sh
node --test tools/test/series-seasons.test.mjs
node tools/test/ui/series-seasons.mjs
SERIES_BROWSER=firefox node tools/test/ui/series-seasons.mjs
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
concurrency evidence, not a claim of parallel MySQL execution or full HTTP token
middleware coverage.

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
