<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_ad_impressions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->foreignId('creator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ad_id', 80);
            $table->string('placement', 20);
            $table->char('visitor_hash', 64);
            $table->char('dedupe_key', 64)->unique();
            $table->string('status', 20)->default('served');
            $table->string('currency', 3);
            $table->unsignedBigInteger('gross_micros');
            $table->decimal('creator_share_percent', 5, 2);
            $table->unsignedBigInteger('creator_amount_micros');
            $table->unsignedBigInteger('credited_cents')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->timestamp('served_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['creator_id', 'status', 'completed_at']);
            $table->index(['video_id', 'status']);
        });

        Schema::create('creator_ad_accruals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('currency', 3);
            $table->unsignedBigInteger('pending_micros')->default(0);
            $table->unsignedBigInteger('lifetime_micros')->default(0);
            $table->unsignedBigInteger('credited_cents')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_ad_accruals');
        Schema::dropIfExists('video_ad_impressions');
    }
};
