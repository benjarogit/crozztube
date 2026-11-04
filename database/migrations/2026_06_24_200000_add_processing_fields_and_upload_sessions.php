<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->string('requested_visibility', 20)->default('public')->after('visibility');
            $table->unsignedTinyInteger('processing_progress')->default(0)->after('status');
            $table->string('processing_stage')->nullable()->after('processing_progress');
            $table->json('renditions')->nullable()->after('video_path');
        });

        Schema::create('upload_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('original_filename');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->unsignedInteger('received_chunks')->default(0);
            $table->json('received_chunk_indexes')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');

        Schema::table('videos', function (Blueprint $table): void {
            $table->dropColumn([
                'requested_visibility',
                'processing_progress',
                'processing_stage',
                'renditions',
            ]);
        });
    }
};
