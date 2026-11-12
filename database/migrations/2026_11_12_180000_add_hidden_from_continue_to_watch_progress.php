<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('video_watch_progress')) {
            return;
        }

        Schema::table('video_watch_progress', function (Blueprint $table): void {
            if (! Schema::hasColumn('video_watch_progress', 'hidden_from_continue_at')) {
                $table->timestamp('hidden_from_continue_at')->nullable()->after('completed_at');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('video_watch_progress') || ! Schema::hasColumn('video_watch_progress', 'hidden_from_continue_at')) {
            return;
        }

        Schema::table('video_watch_progress', fn (Blueprint $table) => $table->dropColumn('hidden_from_continue_at'));
    }
};
