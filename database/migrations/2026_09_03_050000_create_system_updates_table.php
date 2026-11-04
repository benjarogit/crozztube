<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('from_version', 40);
            $table->string('to_version', 40);
            $table->string('status', 40)->index();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('current_step', 120)->nullable();
            $table->string('package_checksum', 64)->nullable();
            $table->string('backup_path', 500)->nullable();
            $table->json('release_metadata')->nullable();
            $table->text('error_details')->nullable();
            $table->text('rollback_error')->nullable();
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('heartbeat_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
