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
            if (! Schema::hasColumn('video_watch_progress', 'last_event_id')) {
                $table->uuid('last_event_id')->nullable();
            }
            if (! Schema::hasColumn('video_watch_progress', 'last_reported_at')) {
                $table->timestamp('last_reported_at')->nullable();
            }
            if (! Schema::hasColumn('video_watch_progress', 'completed_at')) {
                $table->timestamp('completed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('video_watch_progress')) {
            return;
        }

        $columns = collect(['last_event_id', 'last_reported_at', 'completed_at'])
            ->filter(fn (string $column): bool => Schema::hasColumn('video_watch_progress', $column))
            ->all();

        if ($columns !== []) {
            Schema::table('video_watch_progress', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }
};
