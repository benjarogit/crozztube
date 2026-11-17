# Pending database migrations (not auto-applied)

Files in this directory are review drafts. They are deliberately **outside**
`database/migrations/`, so `php artisan migrate` and
`php artisan migrate --force` never pick them up.

To apply one, all of the following must happen first:

1. The maintainer approves the migration in writing (issue, PR comment or task note).
2. The preflight queries in the migration's docblock return no blocking rows
   on the target database.
3. A database backup exists and the change window is agreed.
4. The file is moved to `database/migrations/` in a reviewed commit, a
   successful `migrate --pretend` was observed and `down()` was described.

`tools/test/step-08-series.sh` runs `migrate --force`, so anything placed in
`database/migrations/` runs automatically in local checks. Do not copy drafts
there to "test" them.

## Drafts

| Draft | Purpose | State |
| --- | --- | --- |
| `2026_11_17_000001_add_unique_episode_number_to_playlist_video.php` | Unique index on `(playlist_id, season_number, episode_number)` for non-null episode numbers; drops the redundant non-unique `playlist_video_episode_index` and restores it on rollback | Awaiting approval; not applied anywhere. `applyEpisodes()` already clears affected numbers before writing finals, so renumbering is safe once approved. |
