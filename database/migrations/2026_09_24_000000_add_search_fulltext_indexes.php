<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->fullText(['title', 'description'], 'videos_title_description_fulltext');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->fullText(['name', 'bio'], 'channels_name_bio_fulltext');
        });

        Schema::table('live_streams', function (Blueprint $table) {
            $table->fullText(['title', 'description'], 'live_streams_title_description_fulltext');
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('videos', function (Blueprint $table) {
            $table->dropFullText('videos_title_description_fulltext');
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->dropFullText('channels_name_bio_fulltext');
        });

        Schema::table('live_streams', function (Blueprint $table) {
            $table->dropFullText('live_streams_title_description_fulltext');
        });
    }
};
