<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('community_posts', function (Blueprint $table): void {
            $table->boolean('sale_enabled')->default(false)->after('metadata');
            $table->unsignedInteger('price_cents')->nullable()->after('sale_enabled');
            $table->index(['sale_enabled', 'price_cents']);
        });

        Schema::create('community_photo_purchases', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('community_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('tax_cents')->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_type', 20)->nullable();
            $table->unsignedBigInteger('fee_cents')->default(0);
            $table->unsignedBigInteger('net_cents');
            $table->string('currency', 3)->default('usd');
            $table->string('idempotency_key', 100);
            $table->string('status', 40)->default('completed');
            $table->timestamps();

            $table->unique(['buyer_id', 'community_post_id']);
            $table->unique(['buyer_id', 'idempotency_key']);
            $table->index(['seller_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('community_photo_purchases');

        Schema::table('community_posts', function (Blueprint $table): void {
            $table->dropIndex(['sale_enabled', 'price_cents']);
            $table->dropColumn(['sale_enabled', 'price_cents']);
        });
    }
};
