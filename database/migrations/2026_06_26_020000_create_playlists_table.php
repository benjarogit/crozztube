<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playlists', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('privacy', 20)->default('public');
            $table->string('color', 20)->default('#6c4cff');
            $table->timestamps();
            $table->index(['channel_id', 'privacy', 'created_at']);
        });

        Schema::create('playlist_video', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['playlist_id', 'video_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_video');
        Schema::dropIfExists('playlists');
    }
};
