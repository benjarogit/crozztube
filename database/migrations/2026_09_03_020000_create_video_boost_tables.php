<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_boost_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('billing_model', 20);
            $table->unsignedBigInteger('bid_cents');
            $table->unsignedBigInteger('daily_budget_cents');
            $table->unsignedBigInteger('budget_cents');
            $table->unsignedBigInteger('spent_cents')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('status', 20)->default('active');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->date('last_daily_charge_on')->nullable();
            $table->unsignedBigInteger('impressions_count')->default(0);
            $table->unsignedBigInteger('views_count')->default(0);
            $table->unsignedBigInteger('clicks_count')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['video_id', 'status']);
            $table->index(['status', 'starts_at', 'ends_at']);
        });

        Schema::create('video_boost_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->constrained('video_boost_campaigns')->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 20);
            $table->string('surface', 20);
            $table->char('visitor_hash', 64);
            $table->char('dedupe_key', 64)->unique();
            $table->unsignedBigInteger('cost_cents')->default(0);
            $table->date('occurred_on');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'occurred_on', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('video_boost_events');
        Schema::dropIfExists('video_boost_campaigns');
    }
};
