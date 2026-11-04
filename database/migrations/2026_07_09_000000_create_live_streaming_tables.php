<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_stream_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('enabled')->default(true)->index();
            $table->timestamps();
        });

        $now = now();
        DB::table('live_stream_categories')->insert([
            ['name' => 'Gaming', 'slug' => 'gaming', 'sort_order' => 20, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Travel', 'slug' => 'travel', 'sort_order' => 30, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Film & Video', 'slug' => 'film-video', 'sort_order' => 40, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Music', 'slug' => 'music', 'sort_order' => 50, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Tech', 'slug' => 'tech', 'sort_order' => 60, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Education', 'slug' => 'education', 'sort_order' => 70, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Podcasts', 'slug' => 'podcasts', 'sort_order' => 80, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Q&A', 'slug' => 'q-and-a', 'sort_order' => 90, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);

        Schema::create('live_streams', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('live_stream_categories')->nullOnDelete();
            $table->foreignId('replay_video_id')->nullable()->constrained('videos')->nullOnDelete();
            $table->string('slug', 190)->unique();
            $table->string('title', 160)->nullable();
            $table->text('description')->nullable();
            $table->string('visibility', 30)->default('public');
            $table->string('status', 30)->default('draft')->index();
            $table->string('source_type', 30)->default('webcam');
            $table->string('thumbnail_path')->nullable();
            $table->json('appearance')->nullable();
            $table->json('stream_settings')->nullable();
            $table->json('monetization_settings')->nullable();
            $table->json('engagement_settings')->nullable();
            $table->json('preflight')->nullable();
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('actual_started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('replay_expires_at')->nullable()->index();
            $table->timestamp('preflight_tested_at')->nullable();
            $table->timestamps();
            $table->index(['channel_id', 'status', 'scheduled_at']);
        });

        Schema::create('live_stream_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->string('question', 240);
            $table->string('status', 20)->default('draft')->index();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('live_stream_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('live_stream_polls')->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('votes_count')->default(0);
            $table->timestamps();
        });

        Schema::create('live_stream_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('poll_id')->constrained('live_stream_polls')->cascadeOnDelete();
            $table->foreignId('option_id')->constrained('live_stream_poll_options')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['poll_id', 'user_id']);
        });

        Schema::create('live_stream_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('normalized_hash', 64)->nullable()->index();
            $table->string('moderation_status', 20)->default('visible')->index();
            $table->string('moderation_reason', 120)->nullable();
            $table->timestamp('pinned_at')->nullable();
            $table->timestamps();
            $table->index(['live_stream_id', 'created_at']);
        });

        Schema::create('live_stream_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['live_stream_id', 'user_id']);
        });

        Schema::create('live_stream_contributions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 30);
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->unsignedBigInteger('net_cents');
            $table->string('currency', 3);
            $table->string('idempotency_key', 100);
            $table->string('message', 500)->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestamps();
            $table->unique(['sender_id', 'idempotency_key']);
            $table->index(['live_stream_id', 'type', 'status']);
        });

        Schema::create('live_stream_ad_impressions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('viewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ad_id', 80);
            $table->string('status', 20)->default('served')->index();
            $table->unsignedBigInteger('gross_cents')->default(0);
            $table->decimal('creator_share_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('creator_amount_cents')->default(0);
            $table->timestamp('served_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_ad_impressions');
        Schema::dropIfExists('live_stream_contributions');
        Schema::dropIfExists('live_stream_invites');
        Schema::dropIfExists('live_stream_chat_messages');
        Schema::dropIfExists('live_stream_poll_votes');
        Schema::dropIfExists('live_stream_poll_options');
        Schema::dropIfExists('live_stream_polls');
        Schema::dropIfExists('live_streams');
        Schema::dropIfExists('live_stream_categories');
    }
};
