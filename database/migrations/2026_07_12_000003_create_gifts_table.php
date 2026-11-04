<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->string('image_path');
            $table->unsignedInteger('price_cents');
            $table->boolean('enabled')->default(true)->index();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();
        $files = ['awesome.webp', 'butterfly.webp', 'heart.webp', 'ice_cream.webp', 'rose.webp', 'star.webp'];
        $gifts = [];
        foreach ($files as $index => $file) {
            if (! is_file(base_path('gifts/'.$file))) continue;
            $slug = pathinfo($file, PATHINFO_FILENAME);
            $gifts[] = [
                'name' => ucwords(str_replace('_', ' ', $slug)),
                'slug' => str_replace('_', '-', $slug),
                'image_path' => 'gifts/'.$file,
                'price_cents' => 100,
                'enabled' => true,
                'sort_order' => ($index + 1) * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($gifts) DB::table('gifts')->insert($gifts);

        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->foreignId('gift_id')->nullable()->after('type')->constrained('gifts')->nullOnDelete();
            $table->string('gift_name', 80)->nullable()->after('message');
            $table->string('gift_image_path')->nullable()->after('gift_name');
        });
    }

    public function down(): void
    {
        Schema::table('live_stream_contributions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('gift_id');
            $table->dropColumn(['gift_name', 'gift_image_path']);
        });
        Schema::dropIfExists('gifts');
    }
};
