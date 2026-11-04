<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->json('tags')->nullable()->after('description');
            $table->string('playlist_title')->nullable()->after('language_id');
            $table->timestamp('scheduled_at')->nullable()->after('playlist_title');
            $table->boolean('made_for_kids')->default(false)->after('scheduled_at');
            $table->boolean('monetization_enabled')->default(false)->after('made_for_kids');
            $table->string('captions_path')->nullable()->after('thumbnail_path');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'tags',
                'playlist_title',
                'scheduled_at',
                'made_for_kids',
                'monetization_enabled',
                'captions_path',
            ]);
        });
    }
};
