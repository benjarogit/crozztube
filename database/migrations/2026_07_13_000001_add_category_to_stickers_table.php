<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stickers', function (Blueprint $table): void {
            $table->string('category', 80)->default('General')->after('image_path')->index();
        });

        DB::table('stickers')->select(['id', 'image_path'])->orderBy('id')->chunkById(200, function ($stickers): void {
            foreach ($stickers as $sticker) {
                $relative = Str::after(str_replace('\\', '/', (string) $sticker->image_path), 'stickers/');
                $folder = explode('/', $relative)[0] ?? '';
                $category = str_contains($relative, '/') && $folder !== ''
                    ? (ctype_digit($folder) ? 'Pack '.$folder : Str::headline($folder))
                    : 'General';
                DB::table('stickers')->where('id', $sticker->id)->update(['category' => $category]);
            }
        });

    }

    public function down(): void
    {
        Schema::table('stickers', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
