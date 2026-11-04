<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('crozztube_import_sources')) {
            return;
        }

        Schema::create('crozztube_import_sources', static function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('video_id')->unique();
            $table->longText('media_url')->nullable();
            $table->longText('download_url')->nullable();
            $table->string('kind', 24)->default('pending'); // pending|hls|mp4|embed_only
            $table->string('state', 24)->default('pending'); // pending|refreshing|fresh|stale|error
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('refresh_started_at')->nullable();
            $table->unsignedInteger('refresh_attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->string('title')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->longText('chapters')->nullable();
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crozztube_import_sources');
    }
};
