<?php

/**
 * PENDING DRAFT - NOT APPLIED AUTOMATICALLY.
 *
 * This file lives outside database/migrations/ and is not loaded by
 * `php artisan migrate`. It may only be moved into database/migrations/ after
 * explicit maintainer approval and a clean preflight on the target database.
 *
 * Why: episode numbers are unique per season in the application
 * (SeriesStructure::applyEpisodes() rejects explicit duplicates and renumbers
 * transactionally under a playlist row lock). Callers of applyEpisodes() are
 * already serialized by that lock. The index is the storage guarantee for any
 * writer that bypasses applyEpisodes(), for example a direct query or a future
 * code path. applyEpisodes() clears the affected numbers before writing the
 * finals, so a swap such as 1 <-> 2 cannot collide with the index mid-save.
 *
 * The draft replaces the older non-unique index `playlist_video_episode_index`
 * on the same columns. Both indexes can coexist during the migration; the
 * unique one also serves the old lookups.
 *
 * Preflight (all statements must return no rows):
 *
 *   SELECT playlist_id, season_number, episode_number, COUNT(*) AS rows
 *   FROM playlist_video
 *   WHERE episode_number IS NOT NULL
 *   GROUP BY playlist_id, season_number, episode_number
 *   HAVING COUNT(*) > 1;
 *
 * Unnumbered rows keep episode_number = NULL. MySQL and MariaDB allow several
 * NULL values in a unique index, so plain playlists and auto-numbered drafts
 * stay valid.
 *
 * Rollback: drop the unique index and restore the previous non-unique index on
 * the same columns. No data or column is changed, so the previous behaviour
 * (application-level checks only) returns immediately.
 *
 * Verification after applying:
 *
 *   SHOW INDEX FROM playlist_video WHERE Key_name = 'playlist_video_episode_unique';
 *   -- then re-run tools/test/php/series-checks.php
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const INDEX = 'playlist_video_episode_unique';

    private const PREVIOUS_INDEX = 'playlist_video_episode_index';

    public function up(): void
    {
        if (! Schema::hasTable('playlist_video')) {
            return;
        }

        if (! Schema::hasIndex('playlist_video', self::INDEX)) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->unique(
                    ['playlist_id', 'season_number', 'episode_number'],
                    self::INDEX,
                );
            });
        }

        // Keep this separate and last: a partial run that already added the
        // unique index still removes the redundant non-unique one on retry.
        if (Schema::hasIndex('playlist_video', self::PREVIOUS_INDEX)) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->dropIndex(self::PREVIOUS_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('playlist_video')) {
            return;
        }

        if (Schema::hasIndex('playlist_video', self::INDEX)) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }

        if (! Schema::hasIndex('playlist_video', self::PREVIOUS_INDEX)) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->index(
                    ['playlist_id', 'season_number', 'episode_number'],
                    self::PREVIOUS_INDEX,
                );
            });
        }
    }
};
