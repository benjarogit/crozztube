<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_uploads', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nullable only for an owner-bound cancellation arriving before the PUT.
            $table->string('purpose', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('status', 20)->default('uploading');
            $table->string('content_hash', 64)->nullable();
            $table->string('name')->nullable();
            $table->string('mime', 80)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('attachable_type', 100)->nullable();
            $table->string('attachable_id', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('attached_at')->nullable();
            $table->timestamp('detached_at')->nullable();
            $table->string('last_result', 40)->nullable();
            $table->timestamps();
            $table->index(['user_id', 'content_hash']);
            $table->index(['status', 'expires_at']);
            $table->index(['attachable_type', 'attachable_id']);
        });
        Schema::create('image_upload_mirrors', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('image_upload_id')->constrained('image_uploads')->cascadeOnDelete();
            $table->string('provider_id', 30);
            $table->foreign('provider_id')->references('id')->on('thumbnail_providers');
            $table->string('source_version', 64);
            $table->text('image_url')->nullable();
            $table->unsignedTinyInteger('priority');
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_result', 40)->nullable();
            $table->timestamps();
            $table->unique(['image_upload_id', 'provider_id'], 'image_upload_provider_unique');
            $table->index(['status', 'next_check_at']);
            $table->index(['status', 'next_attempt_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_upload_mirrors');
        Schema::dropIfExists('image_uploads');
    }
};
