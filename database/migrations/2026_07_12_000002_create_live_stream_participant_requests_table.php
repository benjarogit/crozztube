<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('live_stream_participant_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('live_stream_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('identity', 160);
            $table->string('media_mode', 20);
            $table->string('status', 20)->default('pending')->index();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['live_stream_id', 'user_id']);
            $table->index(['live_stream_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('live_stream_participant_requests');
    }
};
