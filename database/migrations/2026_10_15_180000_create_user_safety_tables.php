<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'blocked_user_id']);
            $table->index(['blocked_user_id', 'user_id']);
        });
        Schema::create('user_ugc_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version', 64);
            $table->timestamp('accepted_at');
            $table->unique(['user_id', 'policy_version']);
        });
        Schema::create('content_safety_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reported_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->timestamps();
            $table->unique(['user_id', 'target_type', 'target_id'], 'safety_report_user_target_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_safety_reports');
        Schema::dropIfExists('user_ugc_consents');
        Schema::dropIfExists('user_blocks');
    }
};
