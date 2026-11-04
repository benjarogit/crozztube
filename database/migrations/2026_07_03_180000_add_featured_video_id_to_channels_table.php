<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('channels', 'featured_video_id')) {
            return;
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->foreignId('featured_video_id')->nullable()->after('cover_position_y')->constrained('videos')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('channels', 'featured_video_id')) {
            return;
        }

        Schema::table('channels', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('featured_video_id');
        });
    }
};
