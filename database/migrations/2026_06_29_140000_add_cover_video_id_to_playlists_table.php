<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('playlists', 'cover_video_id')) {
            return;
        }

        Schema::table('playlists', function (Blueprint $table): void {
            $table->foreignId('cover_video_id')->nullable()->after('color')->constrained('videos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('playlists', 'cover_video_id')) {
            return;
        }

        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cover_video_id');
        });
    }
};
