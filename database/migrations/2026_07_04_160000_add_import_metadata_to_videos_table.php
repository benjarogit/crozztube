<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('source_type', 20)->default('upload')->after('captions_path');
            $table->string('import_provider', 40)->nullable()->after('source_type');
            $table->text('import_url')->nullable()->after('import_provider');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['source_type', 'import_provider', 'import_url']);
        });
    }
};
