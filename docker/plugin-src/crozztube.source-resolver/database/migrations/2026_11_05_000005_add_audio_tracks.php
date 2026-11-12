<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crozztube_import_sources') && ! Schema::hasColumn('crozztube_import_sources', 'audio_tracks')) {
            Schema::table('crozztube_import_sources', static fn (Blueprint $table) => $table->longText('audio_tracks')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('crozztube_import_sources') && Schema::hasColumn('crozztube_import_sources', 'audio_tracks')) {
            Schema::table('crozztube_import_sources', static fn (Blueprint $table) => $table->dropColumn('audio_tracks'));
        }
    }
};
