<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_time_mark_votes', function (Blueprint $table): void {
            // Existing votes remain likes.
            $table->smallInteger('value')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('video_time_mark_votes', fn (Blueprint $table) => $table->dropColumn('value'));
    }
};
