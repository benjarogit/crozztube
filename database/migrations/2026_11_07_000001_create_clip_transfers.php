<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_transfer_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('clip_transfer_locks')->insert(['id' => 1]);
        Schema::create('clip_staging_accounts', function (Blueprint $table): void {
            $table->string('id', 48)->primary();
            $table->string('provider', 16);
            $table->text('connection'); // Encrypted, never exposed in API/model JSON.
            $table->boolean('enabled')->default(true);
            $table->timestamp('suspended_until')->nullable();
            $table->unsignedBigInteger('capacity_bytes')->default(1000000000);
            $table->timestamps();
        });
        Schema::create('clip_transfers', function (Blueprint $table): void {
            $table->id(); // FIFO sequence independent of UUIDs and clock ties.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('phase', 24)->default('queued');
            $table->string('account_id', 48)->nullable();
            $table->foreign('account_id')->references('id')->on('clip_staging_accounts')->restrictOnDelete();
            $table->unsignedInteger('bytes');
            $table->string('sha256', 64);
            $table->json('metadata');
            $table->unsignedBigInteger('reserved_bytes')->default(0);
            $table->timestamp('grant_until')->nullable();
            $table->timestamp('cleanup_not_before')->nullable();
            $table->timestamp('deadline_at');
            $table->timestamp('reservation_released_at')->nullable();
            $table->boolean('cancel_requested')->default(false);
            $table->text('provider_state')->nullable(); // Encrypted upload/query references.
            $table->timestamps();
            $table->unique(['user_id', 'request_key']);
            $table->index(['phase', 'id']);
            $table->index(['account_id', 'reservation_released_at'], 'clip_transfer_storage_cleanup_idx');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (DB::table('clip_transfers')->where('reserved_bytes', '>', 0)->whereNull('reservation_released_at')->exists()) {
            throw new RuntimeException('Clip transfer reservations must be cleaned before rollback.');
        }
        Schema::dropIfExists('clip_transfers');
        Schema::dropIfExists('clip_staging_accounts');
        Schema::dropIfExists('clip_transfer_locks');
    }
};
