<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('platform', 32);
            $table->string('environment', 16)->default('production');
            $table->char('token_hash', 64);
            $table->text('token');
            $table->char('device_identifier_hash', 64);
            $table->string('device_name', 120)->nullable();
            $table->string('app_version', 40)->nullable();
            $table->string('locale', 35)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'token_hash']);
            $table->index(['user_id', 'revoked_at']);
            $table->index(['user_id', 'provider', 'device_identifier_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
