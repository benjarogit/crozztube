<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('native_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('personal_access_token_id')
                ->nullable()
                ->unique()
                ->constrained('personal_access_tokens')
                ->nullOnDelete();
            $table->string('client_type', 20);
            $table->string('platform', 30);
            $table->string('device_name', 120);
            $table->char('device_identifier_hash', 64)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('os_version', 80)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['user_id', 'device_identifier_hash']);
            $table->index('expires_at');
        });

        Schema::create('tv_device_authorizations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->char('device_code_lookup', 64)->unique();
            $table->string('device_code_hash');
            $table->char('user_code_lookup', 64)->unique();
            $table->string('user_code_hash');
            $table->string('status', 20)->default('pending');
            $table->foreignId('approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('platform', 30);
            $table->string('device_name', 120);
            $table->char('device_identifier_hash', 64);
            $table->string('app_version', 40)->nullable();
            $table->string('os_version', 80)->nullable();
            $table->string('request_ip_address', 45)->nullable();
            $table->string('approval_ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedSmallInteger('poll_interval_seconds')->default(5);
            $table->unsignedInteger('poll_attempts')->default(0);
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('denied_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['approved_by_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_device_authorizations');
        Schema::dropIfExists('native_device_sessions');
    }
};
