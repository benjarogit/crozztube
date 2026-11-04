<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('push_devices', fn (Blueprint $table) => $table->string('app_identifier', 200)->nullable());
        Schema::create('push_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('notification_id')->constrained('user_notifications')->cascadeOnDelete();
            $table->foreignId('push_device_id')->constrained('push_devices')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('error_code', 80)->nullable();
            $table->timestamp('available_at');
            $table->timestamp('expires_at');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['notification_id', 'push_device_id']);
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_deliveries');
        Schema::table('push_devices', fn (Blueprint $table) => $table->dropColumn('app_identifier'));
    }
};
