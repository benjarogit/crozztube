<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('private_conversations')) {
            Schema::create('private_conversations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('last_message_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['user_one_id', 'user_two_id']);
            });
        }

        if (! Schema::hasTable('private_messages')) {
            Schema::create('private_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('conversation_id')->constrained('private_conversations')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamp('read_at')->nullable()->index();
                $table->timestamps();
                $table->index(['conversation_id', 'created_at']);
                $table->index(['conversation_id', 'sender_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('private_messages');
        Schema::dropIfExists('private_conversations');
    }
};
