# Series and seasons: PR #4 reconciliation

Date: 2026-11-16
Canonical revision: `main` at `35461c1` ("Add series and seasons on top of playlists with access-aware next episode")
Reviewed branch: `origin/codex/series-seasons` at `35cb80079a0cc6f86ca9c0e3ab0395ee20e2467b` (PR #4, draft, `DIRTY`, 22 commits ahead / 8 behind `main`, merge base `544b702`).

This document records what was carried over, what is obsolete and what remains open. It does not accept step 8, does not replace the language review and does not authorize a merge of PR #4.

## Model decision

A series is an existing playlist with `kind = series` and metadata on `playlist_video`. Main implements this in `app/Support/SeriesStructure.php`, `PlaylistResource`, `routes/api.php` and `resources/js/seriesPlayback.js`.

PR #4 implements a parallel model (`is_series`, `app/Support/SeriesPlaylists.php`, `SeriesPlaylistController`, dedicated `/series` endpoints, `PlaylistSeries.jsx`, `series-context.js`, migration `2026_11_14_000001`). That model is obsolete and must not be merged on top of `main`.

## File-by-file comparison

| PR #4 path | State on `main` (`35461c1`) | Decision |
| --- | --- | --- |
| `app/Support/SeriesPlaylists.php` | Not present. `SeriesStructure` owns numbering, ordering, payload, next/previous episode and access checks. | Obsolete. Reuse `SeriesStructure`. |
| `app/Http/Controllers/Api/SeriesPlaylistController.php` | Not present. `routes/api.php` handles `GET /api/playlists/{playlist}/next-episode` and the playlist update applies `episodes` through `SeriesStructure`. | Obsolete. |
| `app/Http/Resources/PlaylistResource.php` | Emits `kind`, `series_status`, `series.seasons`, `season_number`, `episode_number`, `episode_label`, `episode_title`, `episode_description`, `aired_at`. | Already superseded. |
| `app/Http/Resources/Api/V1/PlaylistSummaryResource.php` | No series fields. PR adds only `is_series`, not seasons or episode metadata. | Not adopted. Optional follow-up for native clients, built on `SeriesStructure`. |
| `app/Models/Playlist.php` | `isSeries()` delegates to `SeriesStructure`; no `is_series` column. | Already superseded. |
| `database/migrations/2026_11_14_000001_add_series_context_to_playlists.php` | Never ran. Canonical migration is `2026_11_16_000002_add_series_structure_to_playlists.php`. | Do not merge. |
| `resources/js/components/PlaylistSeries.jsx` | Not present. The editor is part of `NewPlaylistModal` in `resources/js/main.jsx`. | Obsolete; no second editor. |
| `resources/js/series-context.js` | Not present. `resources/js/seriesPlayback.js` implements queue -> next episode -> playlist rest -> discovery. | Obsolete; keep one playback rule. |
| `resources/js/styles/playlist-series.css` | Not present. Series chrome uses `resources/js/styles/viewer-experience.css` and existing tokens. | Obsolete. |
| `resources/js/main.jsx` | Contains the integrated series UI (badge, season headings, next-episode card, single autoplay switch) plus the 2026-11-16 design refinement. | Keep `main`; the PR diff is an alternative editor/context. |
| `routes/api.php`, `routes/api_v1.php` | Canonical web route for next episode. PR's v1 `/series` and `/series/next` endpoints are absent. | Not required for step 8. Optional follow-up if native clients need series. |
| `build/manifest.json`, `build/assets/*` | Generated from `main`. | Not adopted. |
| `harness/plan.json` | Already contains `step-08-series` in checks and step 8 acceptance. | No change needed. |
| `docs/series-seasons.md` | Documents the obsolete model. | Not merged. |
| `tools/test/php/series-playlist-checks.php`, `tools/test/php/series-seasons-checks.php`, `tools/test/series-seasons.test.mjs`, `tools/test/step-08-series.sh`, `tools/test/ui/series-seasons.mjs` | Main runs `tools/test/step-08-series.sh` (57 PHP checks, 8 Node tests, `series-ui-check.mjs`). | Keep `main`; the PR variants test the obsolete model. |

## Language inventory

- The 30 new reference messages live in `tools/i18n/batches/series-and-seasons.json`.
- They are complete in 9 of 125 packages: `de_at`, `de_ch`, `de_de`, `en`, `en_au`, `en_ca`, `en_gb`, `en_nz`, `en_za`.
- They are missing in the other 116 packages. This is an open language review, not an accepted translation.
- PR #4 carries four shared keys (`Series`, `Season`, `Episode`, `Next episode`) in 111 of 125 packages. Fourteen packages are partial or empty: `am`, `arg`, `azb`, `bo`, `dzo`, `jv_id`, `my_mm` (only `Season` missing), `ps`, `sah`, `snd`, `szl` (missing `Series`, `Next episode`), `tah`, `ug_cn`, `yor`. The PR description itself reports unreviewed messages in 14 partial packages.
- These drafts are not linguistic acceptance and do not replace the open checks for the other packages. Any later import goes through the normal i18n batch review, not by merging the series implementation.
- The 2026-11-16 design copy adds no i18n key. It reuses `Continue`, `Play all`, `Season`, `All`, `Watch progress`, `Duration`, `{percent}% watched`, `Library` and existing labels. The new navigation group uses the existing `Library` key so German keeps its translated label.

## Checks and CI

- Local checks for `main`/step 8: `bash tools/test/step-08-series.sh` (57 PHP checks, 8 Node tests, `series-ui-check.mjs`) and `node tools/test/ui/viewer-quality.mjs` (520 checks). Both were re-run against the design changes and passed.
- Browser evidence for the series/watch design: `node tools/test/ui/series-shots.mjs`, which renders the real pages against read-only API responses and a playlist fixture (the local database has no published multi-season series). It checks English/German, light/dark, desktop/mobile and reports no overflow, no writes and no page errors.
- GitHub Actions: the repository has one workflow, `.github/workflows/chatdev.yml`. It runs on `pull_request` and `workflow_dispatch`; the series tooling steps are gated on `github.head_ref == 'codex/series-seasons'`. There is no run for `35461c1`. The most recent successful runs are on the PR branch (`35cb800`, 2026-11-15). No GitHub-CI result is claimed for `main`.

## Migration evidence

- Instance: `crozztube-web` with `crozztube-mysql`, served at `https://crozztube.local:8443`.
- Applied: `2026_11_16_000002_add_series_structure_to_playlists`, batch 18.
- Data at review time: 1 playlist (`id 4`, private, `kind = playlist`, `series_status = NULL`), 1 `playlist_video` row (`playlist_id 4`, `video_id 2`, `sort_order 0`, `season_number 1` from the column default, `episode_number = NULL`, `episode_title = NULL`), 6 videos.
- Not applied: PR #4 migration `2026_11_14_000001`. It has no row in `migrations`.
- No reset, rollback or further production migration is part of this change. A new migration needs explicit approval.

## Open correctness gap

Explicit duplicate episode numbers are currently accepted. `SeriesStructure::numberSeason()` records explicit numbers in `$taken` but does not reject a duplicate; `episode_number` is nullable and the index `playlist_video_episode_index` is not unique; `applyEpisodes()` updates pivot rows one by one without a transaction or row lock. A failed write can therefore leave a partially renumbered season, and two concurrent saves can interleave.

PR #4's revision/lock/unique-index approach points in the right direction, but it belongs to the obsolete `is_series` schema and its migration never ran. Suggested follow-up on `main`, after approval:

1. Validate explicit numbers as unique within each season (request validation plus a final server-side check).
2. Wrap `applyEpisodes()` in a transaction and lock the playlist pivot rows while renumbering.
3. Add a reviewed unique index migration for `(playlist_id, season_number, episode_number)` after existing data is checked.
4. Keep owner/admin access and the existing effective order unchanged.

## PR #4 recommendation

Keep PR #4 open and draft as the carrier for the language review, but do not merge its implementation. If the translations are approved, extract only the reviewed locale strings through the i18n batch workflow. Close the PR only when the language review is finished or the drafts are explicitly discarded; do not close it merely because the series feature already exists on `main`.

## Runtime hook status (step 8 gate)

- Active runtime: OpenCode v2.3.0. The repository has no `opencode.json(c)` and no `.opencode/` directory. The global OpenCode configuration loads one unrelated plugin.
- The repository hooks are `.codex/hooks.json` and `.cursor/hooks.json`, both calling `tools/harness/codex-hook.mjs`. Neither file is loaded by OpenCode, so no project hook has been observed in this runtime. Existing receipts predate the current `hookDigest`; `runtimeHookVerified` stays `false`.
- OpenCode V2 does expose a usable interface: a server plugin under `.opencode/plugins/` can register `ctx.permission.hook("evaluate")` (action `shell`, resource = the command string) and set `effect: "deny"`; `ctx.shell.hook("create.before")` and `ctx.tool.hook("execute.before")` provide the observation point.
- Minimal next step: add a reviewed project plugin that forwards real shell/tool events to `codex-hook.mjs` using its `beforeShellExecution` contract, translates the returned deny into `permission.evaluate`, loads it through `opencode.json`, and performs one real `node tools/harness/cli.mjs deny-probe` run. Only a receipt from that observed run may change `runtimeHookVerified`. Until then `complete` for step 8 and `begin 9` remain blocked. Do not weaken the gate and do not emulate the receipt.
