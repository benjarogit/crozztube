<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->boolean('is_series')->default(false);
        });
        Schema::table('playlist_video', function (Blueprint $table): void {
            $table->unsignedInteger('season_number')->nullable();
            $table->unsignedInteger('episode_number')->nullable();
            $table->string('episode_title', 160)->nullable();
            $table->unique(['playlist_id', 'season_number', 'episode_number'], 'playlist_episode_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_video', function (Blueprint $table): void {
            $table->dropUnique('playlist_episode_number_unique');
            $table->dropColumn(['season_number', 'episode_number', 'episode_title']);
        });
        Schema::table('playlists', fn (Blueprint $table) => $table->dropColumn('is_series'));
    }
};
