<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('native_device_sessions', function (Blueprint $table): void {
            $table->timestamp('refresh_expires_at')->nullable()->after('expires_at')->index();
        });

        Schema::create('native_session_refresh_tokens', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('native_device_session_id')
                ->constrained('native_device_sessions')
                ->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(
                ['native_device_session_id', 'revoked_at'],
                'native_refresh_session_revoked_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('native_session_refresh_tokens');

        Schema::table('native_device_sessions', function (Blueprint $table): void {
            $table->dropIndex(['refresh_expires_at']);
            $table->dropColumn('refresh_expires_at');
        });
    }
};
