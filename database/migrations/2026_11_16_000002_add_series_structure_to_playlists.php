<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A series is the same playlist with a season/episode structure on top.
 * Existing playlists stay valid: kind defaults to "playlist" and every
 * attached video keeps an implicit season 1 until an editor numbers it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('playlists') && ! Schema::hasColumn('playlists', 'kind')) {
            Schema::table('playlists', function (Blueprint $table): void {
                $table->string('kind', 20)->default('playlist')->after('privacy');
                $table->string('series_status', 20)->nullable()->after('kind');
                $table->index(['kind', 'privacy']);
            });
        }

        if (Schema::hasTable('playlist_video') && ! Schema::hasColumn('playlist_video', 'season_number')) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->unsignedSmallInteger('season_number')->default(1)->after('sort_order');
                $table->unsignedSmallInteger('episode_number')->nullable()->after('season_number');
                $table->string('episode_title', 160)->nullable()->after('episode_number');
                $table->text('episode_description')->nullable()->after('episode_title');
                $table->timestamp('aired_at')->nullable()->after('episode_description');
                $table->index(['playlist_id', 'season_number', 'episode_number'], 'playlist_video_episode_index');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('playlist_video') && Schema::hasColumn('playlist_video', 'season_number')) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->dropIndex('playlist_video_episode_index');
                $table->dropColumn(['season_number', 'episode_number', 'episode_title', 'episode_description', 'aired_at']);
            });
        }

        if (Schema::hasTable('playlists') && Schema::hasColumn('playlists', 'kind')) {
            Schema::table('playlists', function (Blueprint $table): void {
                $table->dropIndex(['kind', 'privacy']);
                $table->dropColumn(['kind', 'series_status']);
            });
        }
    }
};
