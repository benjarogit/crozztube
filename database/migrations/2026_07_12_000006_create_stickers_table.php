<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stickers', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('image_path');
            $table->unsignedInteger('price_cents')->default(100);
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        if (Schema::hasTable('super_stickers')) {
            DB::table('stickers')->insertUsing(
                ['id', 'name', 'slug', 'image_path', 'price_cents', 'enabled', 'sort_order', 'created_at', 'updated_at'],
                DB::table('super_stickers')->select(['id', 'name', 'slug', 'image_path', 'price_cents', 'enabled', 'sort_order', 'created_at', 'updated_at'])
            );
        }

        Schema::table('comments', function (Blueprint $table): void {
            $table->foreignId('sticker_id')->nullable()->after('parent_id')->constrained('stickers')->nullOnDelete();
        });
        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->foreignId('sticker_id')->nullable()->after('super_sticker_id')->constrained('stickers')->nullOnDelete();
            $table->string('sticker_name', 80)->nullable()->after('super_sticker_image_path');
            $table->string('sticker_image_path')->nullable()->after('sticker_name');
        });

        if (Schema::hasColumn('live_stream_contributions', 'super_sticker_id')) {
            DB::table('live_stream_contributions')->whereNotNull('super_sticker_id')->update([
                'sticker_id' => DB::raw('super_sticker_id'),
                'sticker_name' => DB::raw('super_sticker_name'),
                'sticker_image_path' => DB::raw('super_sticker_image_path'),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('sticker_id');
            $table->dropColumn(['sticker_name', 'sticker_image_path']);
        });
        Schema::table('comments', fn (Blueprint $table) => $table->dropConstrainedForeignId('sticker_id'));
        Schema::dropIfExists('stickers');
    }
};
