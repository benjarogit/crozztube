<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('community_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('video_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 24)->default('text');
            $table->longText('body')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('url_title')->nullable();
            $table->text('url_description')->nullable();
            $table->string('url_image', 2048)->nullable();
            $table->json('attachments')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->unsignedInteger('votes_count')->default(0);
            $table->timestamps();
            $table->index(['channel_id', 'type', 'created_at']);
        });

        Schema::create('community_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('option_index');
            $table->timestamps();
            $table->unique(['community_post_id', 'user_id']);
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->nullableMorphs('commentable');
        });

        if (DB::getDriverName() === 'sqlite') {
            Schema::table('comments', function (Blueprint $table): void {
                $table->unsignedBigInteger('video_id')->nullable()->change();
            });
        } else {
            DB::statement('ALTER TABLE comments MODIFY video_id BIGINT UNSIGNED NULL');
        }

        Schema::table('comments', function (Blueprint $table): void {
            $table->index(['commentable_type', 'commentable_id', 'parent_id', 'created_at'], 'comments_commentable_parent_created_index');
        });

        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->foreignId('community_post_id')->nullable()->after('channel_id')->constrained('community_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('user_notifications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('community_post_id');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropIndex('comments_commentable_parent_created_index');
            $table->dropMorphs('commentable');
        });

        Schema::dropIfExists('community_poll_votes');
        Schema::dropIfExists('community_posts');
    }
};
