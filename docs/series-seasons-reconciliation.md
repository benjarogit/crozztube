# Series and seasons: PR #4 reconciliation

Date: 2026-11-17
Canonical revision: `main` at `442e179` (design follow-up on the step-8 implementation `35461c1`, "Add series and seasons on top of playlists with access-aware next episode")
Reviewed branch: `origin/codex/series-seasons` at `35cb80079a0cc6f86ca9c0e3ab0395ee20e2467b` (PR #4, draft, `DIRTY`, 22 commits ahead / 10 behind `main`, merge base `544b702`).

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
| `tools/test/php/series-playlist-checks.php`, `tools/test/php/series-seasons-checks.php`, `tools/test/series-seasons.test.mjs`, `tools/test/step-08-series.sh`, `tools/test/ui/series-seasons.mjs` | Main runs `tools/test/step-08-series.sh` (61 PHP checks, 6 cross-process lock checks, 8 Node tests, `series-ui-check.mjs`). | Keep `main`; the PR variants test the obsolete model. |

## Language inventory

- The 31 new reference messages live in `tools/i18n/batches/series-and-seasons.json`. The 31st is the server-side duplicate guard (`Episode numbers must be unique within a season.`), which the playlist editor also shows before submitting.
- They are complete in 9 of 125 packages: `de_at`, `de_ch`, `de_de`, `en`, `en_au`, `en_ca`, `en_gb`, `en_nz`, `en_za`.
- They are missing in the other 116 packages. This is an open language review, not an accepted translation.
- PR #4 carries four shared keys (`Series`, `Season`, `Episode`, `Next episode`) in 111 of 125 packages. Fourteen packages are partial or empty: `am`, `arg`, `azb`, `bo`, `dzo`, `jv_id`, `my_mm` (only `Season` missing), `ps`, `sah`, `snd`, `szl` (missing `Series`, `Next episode`), `tah`, `ug_cn`, `yor`. The PR description itself reports unreviewed messages in 14 partial packages.
- These drafts are not linguistic acceptance and do not replace the open checks for the other packages. Any later import goes through the normal i18n batch review, not by merging the series implementation.
- The 2026-11-16 design copy adds no i18n key. It reuses `Continue`, `Play all`, `Season`, `All`, `Watch progress`, `Duration`, `{percent}% watched`, `Library` and existing labels. The new navigation group uses the existing `Library` key so German keeps its translated label.

## Checks and CI

- Local checks for `main`/step 8: `bash tools/test/step-08-series.sh` (61 PHP checks, 6 cross-process lock checks, 8 Node tests, `series-ui-check.mjs`) and `node tools/test/ui/viewer-quality.mjs` (520 checks). `tools/test/ui/specs/viewer-experience.spec.ts` runs 10/10 Chromium tests covering discovery order, queue sections, touch cards, resize, native playback and the hero/trending focus feedback.
- Browser evidence for the series/watch design: `node tools/test/ui/series-shots.mjs`, which renders the real pages against read-only API responses and a playlist fixture (the local database has no published multi-season series). It checks English/German, light/dark, desktop/mobile and reports no overflow, no writes and no page errors.
- Five-area evidence: `node tools/test/ui/layout-shots.mjs <label>` writes home, video cards, navigation, series detail and watch for de/en, light/dark and desktop/mobile (40 images per label). Navigation is captured as the sidebar element; the overflow check covers the four page areas. The `before` set was built from `35461c1` in a detached worktree and served from a temporary build swap; the `after` set comes from the current checkout. Both sets use the same script and the same synthetic shelves. The pair compares the step-8 base with the refined tree, not only the latest fix round. Watch shots show the series chip and next-episode card; the player surface stays empty because no media is downloaded. Images stay in `/tmp/opencode/shots/{before,after}` and are not committed.
- WebKit: the spec also contains WebKit-labelled cases, but the host lacks `libicu74`, `libxml2` and `libflite1`. The Chromium project runs the same assertions; no WebKit pass is claimed.

### GitHub Actions

- The repository has one workflow, `.github/workflows/chatdev.yml`. It now also runs on `push` to `main` (with the same path filter), in addition to `pull_request` and `workflow_dispatch`.
- The existing ChatDev harness tests stay unconditional and unchanged. A new runner-safe step runs `node --test tools/test/series-playback.test.mjs` plus a JSON-only parse of `harness/plan.json`, `harness/progress.json`, `tools/i18n/batches/series-and-seasons.json`, `.codex/hooks.json` and `.cursor/hooks.json`.
- The nine heavy series steps now run in an unconditional `series` job on `ubuntu-latest` with a MySQL 8.0.46 service, PHP 8.5 including `pdo_mysql`, `composer install`, both `npm ci` installs, Chromium, `ffmpeg`, a generated `.env` with application key and `npm run build`. No `github.head_ref` gate remains; a push to `main` executes the same backend and browser checks as the feature branch.
- `tools/test/step-08-series.sh` is runner-agnostic: `CROZZTUBE_PHP_CONTAINER` defaults to `crozztube-web` for the compose stack and can be set empty to execute PHP in the current shell. With local PHP and no `BASE_URL`, the script creates the missing `storage/framework/{sessions,views,cache}` and `storage/logs` directories of a fresh checkout (otherwise Laravel answers HTTP with "Please provide a valid cache path."), starts the application itself on `127.0.0.1:8010` through the repository root entry point (this deployment serves the root, not an empty `public/`) and waits on `/up`. `CROZZTUBE_UI_FIXTURE=1` creates the single public upload video the existing browser check reads; it is only permitted with local PHP, so the compose stack keeps its catalogue.
- `tools/test/ui/fixtures/readonly-layout.ts` runs its read-only API fixtures through `docker exec` by default and through the local `php` binary with `LAYOUT_PHP_LOCAL=1`. It also accepts a controlled `respond` answer for one API path, used by the series check for the resolver status route.
- The path filter now includes the step-8 files that actually change (`app/Support/SeriesStructure.php`, `tools/test/php/series-checks.php`, `tools/test/php/series-concurrency-checks.php`, `tools/test/ui/specs/viewer-experience.spec.ts`, `resources/js/styles/**`, `tools/i18n/batches/**`, `tools/migrations/**`, `.opencode/**`, the adapter and this document) and no longer lists the obsolete PR #4 paths. `tools/test/chatdev-series.test.mjs` asserts the new filter, the removal and the ungated runner job.
- Local runner evidence (20/21 Nov 2026), both modes, fresh application database for the runner mode (`crozztube_ci` on the local MySQL 8.0.46, full migration chain from an empty schema): `61` PHP checks, `6` cross-process lock checks, `8` Node tests and `series-ui-check.mjs` all pass; the compose-stack mode passes the same four blocks. A fresh-checkout simulation without `bootstrap/cache/*.php` and without `storage/framework/*` first answered HTTP 500 ("Please provide a valid cache path."); the script now creates the missing runtime directories before it starts the application, and the simulation then passed completely. A negative probe with an injected write still fails. The full ChatDev/harness suite passes locally (214/214).
- Actual Actions evidence: run [44888104336](https://github.com/benjarogit/crozztube/actions/runs/44888104336) on commit `c7eef782481c7e4f6bbb759c60c5c1cfb42f060f` (push to `main`) completed successfully. `contracts` passed 214/214 harness/ChatDev tests and 8/8 runner-safe playback/JSON checks. The `series` job applied the complete migration chain on a fresh MySQL 8.0.46 service, including `2026_11_16_000002_add_series_structure_to_playlists`, then logged `Series checks: 61 passed, 0 failed.`, `Series concurrency checks: 6 passed, 0 failed.`, `# pass 8 / # fail 0` and `Series UI fixture: chip, next-episode card, single autoplay toggle and no overflow at 1440/375.` (2m26s). This is runner evidence for PHP, database, cross-process lock and browser checks, not only for the Node contracts.
- Earlier runs: [44738754868](https://github.com/benjarogit/crozztube/actions/runs/44738754868) on `5ce1846` also passed, but the eight heavy steps of that revision were skipped because `head_ref` is empty on a push. The first run on `3a8edff` (`44738089027`) failed because the ChatDev contract test still required the removed PR #4 path; that assertion was corrected in `5ce1846`.

### Browser fixture and the source-resolver refresh (separate finding)

- The series browser check injects a synthetic series payload into a real watch page and asserts `writes == []`. On the live instance it failed with `POST /api/source-resolver/refresh/476` even though the series markup was correct. Cause: `storage/app/plugins/crozztube.source-resolver/assets/watch-enhancer.js` resolves the video id from `/api/source-resolver/status/slug/<slug>` and refreshes any import whose source is not fresh. Video `476` is an `import`/`auf1` entry with `playback_pending` and no `video_sources`, so the plugin issued a legitimate refresh. The live catalogue contains six public standard videos, all imports, so no neutral catalogue entry existed.
- This finding says nothing about whether that refresh for video `476` is correct or faulty; it is documented separately and the product resolver was not changed.
- The check now uses a controlled upload payload (`source_type: upload`, no import provider, no pending playback, fixture video sources) and answers the resolver status route for that slug with the application's own `404` for a non-imported video. `writes == []` stays unchanged, no write is ignored and no success response is fabricated for a write. A negative probe with the same file plus one injected `POST /api/source-resolver/refresh/1` failed with exit 1 and reported the recorded write, so unexpected writes are still detected. Both modes pass with the fixture.


## Build artifacts

- `build/manifest.json` is tracked, but `/build/assets/*` is git-ignored (files are added selectively for release commits). The manifest therefore always names hashes that a fresh clone does not contain; `npm run build` is required before serving a checkout.
- `45c9030` repeated that pattern. The current working tree changes nothing about it; the manifest is regenerated locally and is not part of the reviewed change set.

## Migration evidence

- Instance: `crozztube-web` with `crozztube-mysql`, served at `https://crozztube.local:8443`.
- Applied: `2026_11_16_000002_add_series_structure_to_playlists`, batch 18.
- Data at review time: 1 playlist (`id 4`, private, `kind = playlist`, `series_status = NULL`), 1 `playlist_video` row (`playlist_id 4`, `video_id 2`, `sort_order 0`, `season_number 1` from the column default, `episode_number = NULL`, `episode_title = NULL`), 6 videos.
- Not applied: PR #4 migration `2026_11_14_000001`. It has no row in `migrations`.
- Pending draft, not applied: `tools/migrations/pending/2026_11_17_000001_add_unique_episode_number_to_playlist_video.php` (unique index on `(playlist_id, season_number, episode_number)`, replaces the redundant non-unique `playlist_video_episode_index` and restores it on rollback, NULLs stay valid, documented `down()`). The preflight query returns 0 duplicate groups on the local instance (1 unnumbered row of 1 total). It is outside `database/migrations/`, so neither `php artisan migrate` nor the `migrate --force` in `step-08-series.sh` can execute it. `applyEpisodes()` already clears affected numbers before writing finals, so the draft no longer risks a transient duplicate during renumbering.
- Isolated migration test (20 Nov 2026): a throwaway `mysql:8.0.46` container on `127.0.0.1:13306` with its own `crozztube_migration_test` database, no mounted volume, synthetic `playlist_video` table (old non-unique `playlist_video_episode_index`) and seven rows covering numbered episodes, two `NULL`s, another playlist and another season. The draft ran via `php artisan migrate --path=tools/migrations/pending/… --realpath --force` from a one-off app container. After `up`: only `playlist_video_episode_unique` remains on the three columns, a duplicate numbered row is rejected with MySQL error 1062, several `NULL` rows are accepted, a direct `1 <-> 2` swap is rejected, and the application's two-phase write (clear to `NULL`, then assign) succeeds and produces the swapped values. `migrate:rollback --path=… --realpath` removed the unique index, restored the non-unique index, allowed duplicate numbers again and left `migrations` empty. The container was then deleted.
- The existing `crozztube-mysql` instance was only read for its schema and migration status: `playlist_video_episode_index` is still non-unique and the latest row is batch 18 (`2026_11_16_000002`). Nothing was applied, reset or rolled back there.
- Limits of that test: it used a minimal synthetic table, not the full application migration chain, and did not exercise HTTP, policies or `applyEpisodes()` against the test database. It proves the draft's index/rollback mechanics, not a production apply.
- Before any real apply: take a `mysqldump` of the target database, restore it into a separate database once, run `migrate --pretend` for the exact file, agree a change window, apply, verify `SHOW INDEX` plus `series-checks.php`, and keep the backup until acceptance. The draft still needs explicit written approval and a reviewed move into `database/migrations/`. No reset, rollback or further production migration is part of this change.

## Episode numbering integrity

Resolved in this change set (published with the checkpoint that contains this document):

- `SeriesStructure::applyEpisodes()` now runs in one `DB::transaction`, locks the `playlists` row and the pivot rows (`lockForUpdate()`) before renumbering, and rejects explicit duplicate episode numbers within a season with a `ValidationException` on the `episodes` key. The transaction retries a detected deadlock up to three times.
- Playlist create and update routes wrap playlist creation, pivot sync and episode application in one transaction; update locks and refreshes the playlist row first, so two editors serialize instead of interleaving.
- Before writing the final numbers, `applyEpisodes()` clears the affected pivot rows to `episode_number = NULL`. That keeps a future unique index safe for swaps such as `1 <-> 2`, which would otherwise collide mid-save.
- Episode titles are clipped to the column width (160), and the client marks every duplicate episode input, shows the same message inline and disables saving until the duplicate is fixed.
- Evidence: `tools/test/php/series-checks.php` (61 checks) covers duplicate rejection, unchanged rows after rejection, a failing write rolled back and the effective order. `tools/test/php/series-concurrency-checks.php` (6 checks) starts a second PHP process, holds the playlist lock in the parent, observes the child still running after it reaches the save call, and verifies one complete final order.
- Remaining storage-level gap: the index `playlist_video_episode_index` is still non-unique. The reviewed unique index exists only as the pending draft above; until it is approved and applied, a writer that bypasses `applyEpisodes()` can still store a duplicate.

## PR #4 recommendation

Keep PR #4 open and draft as the carrier for the language review, but do not merge its implementation. If the translations are approved, extract only the reviewed locale strings through the i18n batch workflow. Close the PR only when the language review is finished or the drafts are explicitly discarded; do not close it merely because the series feature already exists on `main`.

## Runtime hook status (step 8 gate)

- Active runtime: OpenCode v2.3.2. The project now ships a project-local server plugin at `.opencode/plugins/crozztube-harness/index.ts`; OpenCode auto-discovers it. No global configuration and no Cursor requirement were added.
- The plugin registers `ctx.permission.hook("evaluate")`. For shell actions it forwards only the gate-critical commands (`deny-probe` and `cli.mjs begin N`) to `tools/harness/codex-hook.mjs` through its `beforeShellExecution` contract and maps a returned denial to `effect: "deny"`. It never writes an allow back, so configured permissions stay authoritative. All other commands and actions are untouched.
- Real active-runtime evidence: on 20 Nov 2026 the active session ran `node tools/harness/cli.mjs deny-probe`; the plugin blocked it before execution with `CROZZTUBE_HARNESS_PROBE_BLOCKED` and appended a `beforeShellExecution` / `probe-blocked` receipt to the local receipt log. `runtimeEvidence` is `true` for digest `0e9245008415…`, which includes the adapter, the plugin tree and the existing hook files. No receipt was simulated or copied.
- Scope of that evidence: it proves the project hook is loaded and can deny the probe and a premature `begin` in this runtime. It does not turn the other `.codex`/`.cursor` events (`SessionStart`, `UserPromptSubmit`, `Interrupt`, `Stop`) into OpenCode enforcement; those remain unmapped and are not claimed.
- `hookDigest` now also hashes `tools/harness/opencode-adapter.mjs` and every file under `.opencode/plugins`, plus optional `opencode.json(c)`. Editing or adding an adapter/plugin file invalidates older receipts. `tools/test/harness.test.mjs` covers forwarding, denial, fail-closed behaviour and this digest binding.
- Independent review of the adapter is still open; the reviewer subagent could not run because the free-usage limit was reached. No approval is claimed. `complete` for step 8 and `begin 9` remain blocked by the other open items below (independent correction review, migration approval, runner coverage for the heavy series checks, language review), not by the runtime probe.
