<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_time_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('start_seconds');
            $table->string('body', 280)->nullable();
            $table->string('visibility', 16)->default('private');
            $table->timestamps();
            $table->index(['video_id', 'visibility', 'start_seconds']);
        });

        Schema::create('video_time_mark_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_time_mark_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['video_time_mark_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_time_mark_votes');
        Schema::dropIfExists('video_time_marks');
    }
};
