<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('playlists') && ! Schema::hasTable('playlist_members')) {
            Schema::create('playlist_members', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('role', 20);
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
                $table->unique(['playlist_id', 'user_id']);
                $table->index(['user_id', 'role']);
            });
        }

        if (Schema::hasTable('playlist_video') && ! Schema::hasColumn('playlist_video', 'added_by_user_id')) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->foreignId('added_by_user_id')->nullable()->after('video_id')->constrained('users')->nullOnDelete();
            });
        }

        if (Schema::hasTable('playlists') && ! Schema::hasTable('playlist_video_votes')) {
            Schema::create('playlist_video_votes', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
                $table->foreignId('video_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['playlist_id', 'video_id', 'user_id']);
                $table->index(['playlist_id', 'video_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_video_votes');

        if (Schema::hasTable('playlist_video') && Schema::hasColumn('playlist_video', 'added_by_user_id')) {
            Schema::table('playlist_video', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('added_by_user_id');
            });
        }

        Schema::dropIfExists('playlist_members');
    }
};
