<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('comments', function (Blueprint $table): void {
            $table->boolean('is_pinned')->default(false)->after('likes_count');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->foreignId('pinned_by')->nullable()->after('pinned_at')->constrained('users')->nullOnDelete();
            $table->index(['is_pinned', 'pinned_at']);
        });

        Schema::create('comment_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'comment_id']);
        });

        Schema::create('user_mutes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('muted_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'muted_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_mutes');
        Schema::dropIfExists('comment_reports');

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('pinned_by');
            $table->dropIndex(['is_pinned', 'pinned_at']);
            $table->dropColumn(['is_pinned', 'pinned_at']);
        });
    }
};
