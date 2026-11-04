<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creator_tips', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->unsignedBigInteger('net_cents');
            $table->string('currency', 3);
            $table->string('idempotency_key', 100);
            $table->string('message', 500)->nullable();
            $table->string('status', 20)->default('completed');
            $table->timestamps();

            $table->unique(['sender_id', 'idempotency_key']);
            $table->index(['channel_id', 'status', 'created_at']);
            $table->index(['video_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creator_tips');
    }
};
