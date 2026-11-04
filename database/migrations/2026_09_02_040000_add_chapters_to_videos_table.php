<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->json('chapters')->nullable()->after('captions_path');
            $table->boolean('chapters_enabled')->default(true)->after('chapters');
            $table->string('chapters_source', 24)->nullable()->after('chapters_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn(['chapters', 'chapters_enabled', 'chapters_source']);
        });
    }
};
