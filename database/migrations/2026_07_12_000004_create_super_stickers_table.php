<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('super_stickers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('image_path');
            $table->unsignedInteger('price_cents');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->foreignId('super_sticker_id')->nullable()->after('gift_id')->constrained('super_stickers')->nullOnDelete();
            $table->string('super_sticker_name', 80)->nullable()->after('gift_image_path');
            $table->string('super_sticker_image_path')->nullable()->after('super_sticker_name');
        });
    }

    public function down(): void
    {
        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('super_sticker_id');
            $table->dropColumn(['super_sticker_name', 'super_sticker_image_path']);
        });
        Schema::dropIfExists('super_stickers');
    }
};
