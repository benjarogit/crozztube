<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->text('original_thumbnail_url')->nullable();
            $table->text('thumbnail_source_url')->nullable();
            $table->string('thumbnail_source_version', 64)->nullable()->index();
            $table->boolean('thumbnail_is_custom')->default(false);
            $table->unsignedInteger('video_width')->nullable();
            $table->unsignedInteger('video_height')->nullable();
            $table->json('metadata_overrides')->nullable();
        });
        Schema::create('thumbnail_providers', function (Blueprint $table): void {
            $table->string('id', 30)->primary();
            $table->unsignedTinyInteger('priority');
            $table->boolean('circuit_open')->default(false);
            $table->timestamp('paused_until')->nullable();
            $table->json('failed_video_ids')->nullable();
            $table->string('last_result', 40)->nullable();
            $table->timestamps();
        });
        Schema::create('thumbnail_mirrors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('video_id')->constrained()->cascadeOnDelete();
            $table->string('provider_id', 30);
            $table->foreign('provider_id')->references('id')->on('thumbnail_providers');
            $table->string('source_version', 64);
            $table->text('thumbnail_url')->nullable();
            $table->unsignedTinyInteger('priority');
            $table->string('status', 20)->default('pending');
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('next_check_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_result', 40)->nullable();
            $table->timestamps();
            $table->unique(['video_id', 'provider_id', 'source_version'], 'thumbnail_mirror_source_unique');
            $table->index(['status', 'next_check_at']);
            $table->index(['status', 'next_attempt_at']);
        });
        foreach (['directupload', 'fastpic', 'postimages'] as $i => $id) {
            DB::table('thumbnail_providers')->insert(['id' => $id, 'priority' => $i + 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('videos')->whereNotNull('thumbnail_path')->orderBy('id')->chunkById(100, function ($videos): void {
            foreach ($videos as $video) {
                if (preg_match('#^https?://#i', $video->thumbnail_path)) {
                    DB::table('videos')->where('id', $video->id)->update([
                        'original_thumbnail_url' => $video->thumbnail_path,
                        'thumbnail_source_url' => $video->thumbnail_path,
                        'thumbnail_source_version' => hash('sha256', $video->thumbnail_path),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thumbnail_mirrors');
        Schema::dropIfExists('thumbnail_providers');
        Schema::table('videos', fn (Blueprint $table) => $table->dropColumn([
            'original_thumbnail_url', 'thumbnail_source_url', 'thumbnail_source_version', 'thumbnail_is_custom',
            'video_width', 'video_height', 'metadata_overrides',
        ]));
    }
};
