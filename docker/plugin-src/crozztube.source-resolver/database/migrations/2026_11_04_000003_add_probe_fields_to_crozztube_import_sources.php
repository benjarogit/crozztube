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
            if (! Schema::hasColumn('crozztube_import_sources', 'last_checked_at')) {
                $table->timestamp('last_checked_at')->nullable()->after('resolved_at');
            }

            if (! Schema::hasColumn('crozztube_import_sources', 'last_check_status')) {
                $table->string('last_check_status', 32)->nullable()->after('last_checked_at');
            }
        });

        // Maintenance jobs scan by expiry, so keep that lookup cheap.
        try {
            Schema::table('crozztube_import_sources', static function (Blueprint $table): void {
                $table->index('expires_at', 'crozztube_import_sources_expires_at_index');
            });
        } catch (\Throwable) {
            // Index already exists (idempotent re-install).
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('crozztube_import_sources')) {
            return;
        }

        try {
            Schema::table('crozztube_import_sources', static function (Blueprint $table): void {
                $table->dropIndex('crozztube_import_sources_expires_at_index');
            });
        } catch (\Throwable) {
            // Nothing to drop.
        }

        Schema::table('crozztube_import_sources', static function (Blueprint $table): void {
            if (Schema::hasColumn('crozztube_import_sources', 'last_check_status')) {
                $table->dropColumn('last_check_status');
            }

            if (Schema::hasColumn('crozztube_import_sources', 'last_checked_at')) {
                $table->dropColumn('last_checked_at');
            }
        });
    }
};
