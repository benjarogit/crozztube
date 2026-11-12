<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clip_collections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->string('visibility', 16)->default('private');
            $table->timestamps();
            $table->index(['user_id', 'updated_at']);
        });
        Schema::create('clip_collection_clip', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('clip_collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('clip_transfer_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['clip_collection_id', 'clip_transfer_id'], 'clip_collection_clip_unique');
            $table->index(['clip_collection_id', 'sort_order'], 'clip_collection_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clip_collection_clip');
        Schema::dropIfExists('clip_collections');
    }
};
