<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedBigInteger('amount_cents')->default(0);
            $table->string('currency', 3)->default('usd');
            $table->string('status', 40)->default('pending');
            $table->string('provider', 40)->nullable();
            $table->string('provider_reference', 255)->nullable();
            $table->string('method', 80)->nullable();
            $table->string('account', 255)->nullable();
            $table->text('note')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_reference']);
            $table->index(['user_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
