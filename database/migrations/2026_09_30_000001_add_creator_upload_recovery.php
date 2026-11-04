<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upload_sessions', function (Blueprint $table): void {
            $table->uuid('client_upload_id')->nullable();
            $table->unique(['user_id', 'client_upload_id']);
        });
        Schema::table('videos', function (Blueprint $table): void {
            $table->boolean('publish_as_draft')->default(false);
            $table->json('caption_metadata')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn(['publish_as_draft', 'caption_metadata']));
        Schema::table('upload_sessions', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'client_upload_id']);
            $table->dropColumn('client_upload_id');
        });
    }
};
