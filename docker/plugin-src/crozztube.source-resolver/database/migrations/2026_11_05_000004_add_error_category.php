<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crozztube_import_sources') && ! Schema::hasColumn('crozztube_import_sources', 'error_category')) {
            Schema::table('crozztube_import_sources', fn (Blueprint $table) => $table->string('error_category', 30)->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('crozztube_import_sources', 'error_category')) {
            Schema::table('crozztube_import_sources', fn (Blueprint $table) => $table->dropColumn('error_category'));
        }
    }
};
