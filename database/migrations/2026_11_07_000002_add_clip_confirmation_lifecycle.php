<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clip_transfers', function (Blueprint $table): void {
            $table->string('lease_token', 64)->nullable();
            $table->timestamp('lease_until')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('notification_handled_at')->nullable();
            $table->timestamp('cleanup_checked_at')->nullable();
            $table->string('last_error', 48)->nullable();
            $table->string('publisher_channel_id', 40)->nullable();
            $table->index(['phase', 'next_check_at'], 'clip_transfer_confirmation_due_idx');
        });
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->foreignId('clip_transfer_id')->nullable()->unique('notification_clip_transfer_unique')
                ->constrained('clip_transfers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('clip_transfers')->exists()) {
            throw new RuntimeException('Clip transfers must be reconciled before removing confirmation state.');
        }
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->dropForeign(['clip_transfer_id']);
            $table->dropUnique('notification_clip_transfer_unique');
            $table->dropColumn('clip_transfer_id');
        });
        Schema::table('clip_transfers', function (Blueprint $table): void {
            $table->dropIndex('clip_transfer_confirmation_due_idx');
            $table->dropColumn(['lease_token', 'lease_until', 'next_check_at', 'available_at',
                'notification_handled_at', 'cleanup_checked_at', 'last_error', 'publisher_channel_id']);
        });
    }
};
