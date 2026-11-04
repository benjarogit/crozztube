<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('playhub_plugins', function (Blueprint $table): void {
            $table->string('id', 100)->primary();
            $table->string('name', 120);
            $table->string('version', 40);
            $table->text('description')->nullable();
            $table->string('author', 160)->nullable();
            $table->json('manifest');
            $table->longText('settings')->nullable();
            $table->string('checksum', 64);
            $table->boolean('enabled')->default(false)->index();
            $table->text('last_error')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playhub_plugins');
    }
};
