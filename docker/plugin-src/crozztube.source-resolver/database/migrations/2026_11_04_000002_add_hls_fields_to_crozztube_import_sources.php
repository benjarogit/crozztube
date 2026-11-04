<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('crozztube_import_sources')) {
            return;
        }

        Schema::table('crozztube_import_sources', static function (Blueprint $table): void {
            if (! Schema::hasColumn('crozztube_import_sources', 'audio_url')) {
                $table->longText('audio_url')->nullable();
            }

            if (! Schema::hasColumn('crozztube_import_sources', 'variants')) {
                $table->longText('variants')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('crozztube_import_sources')) {
            return;
        }

        Schema::table('crozztube_import_sources', static function (Blueprint $table): void {
            if (Schema::hasColumn('crozztube_import_sources', 'variants')) {
                $table->dropColumn('variants');
            }

            if (Schema::hasColumn('crozztube_import_sources', 'audio_url')) {
                $table->dropColumn('audio_url');
            }
        });
    }
};
