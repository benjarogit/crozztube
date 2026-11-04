<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stickers', function (Blueprint $table): void {
            $table->string('provider', 20)->default('local')->index();
            $table->string('provider_id', 240)->nullable()->index();
            $table->char('reference_hash', 64)->nullable();
            $table->unique(['provider', 'reference_hash']);
            $table->text('image_path')->change();
        });
        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->text('sticker_image_path')->nullable()->change();
            $table->text('super_sticker_image_path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stickers', function (Blueprint $table): void {
            $table->dropUnique(['provider', 'reference_hash']);
            $table->dropColumn(['provider', 'provider_id', 'reference_hash']);
        });
        // Keep widened URL fields: narrowing could destroy historical references.
    }
};
