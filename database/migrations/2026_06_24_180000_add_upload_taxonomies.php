<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('slug', 140)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('video_languages', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('code', 12)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('video_categories')->insert([
            ['name' => 'Documentary', 'slug' => 'documentary', 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Travel & Events', 'slug' => 'travel-events', 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Technology', 'slug' => 'technology', 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Education', 'slug' => 'education', 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Lifestyle', 'slug' => 'lifestyle', 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Music', 'slug' => 'music', 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Sports', 'slug' => 'sports', 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gaming', 'slug' => 'gaming', 'sort_order' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Film & Animation', 'slug' => 'film-animation', 'sort_order' => 210, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Science & Technology', 'slug' => 'science-technology', 'sort_order' => 250, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'News & Politics', 'slug' => 'news-politics', 'sort_order' => 280, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Entertainment', 'slug' => 'entertainment', 'sort_order' => 290, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Comedy', 'slug' => 'comedy', 'sort_order' => 300, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'People & Blogs', 'slug' => 'people-blogs', 'sort_order' => 310, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Howto & Style', 'slug' => 'howto-style', 'sort_order' => 320, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Pets & Animals', 'slug' => 'pets-animals', 'sort_order' => 330, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Autos & Vehicles', 'slug' => 'autos-vehicles', 'sort_order' => 340, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $videoLanguages = collect([
            ['name' => 'English', 'code' => 'en', 'sort_order' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Romanian', 'code' => 'ro', 'sort_order' => 20, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Russian', 'code' => 'ru', 'sort_order' => 30, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Spanish', 'code' => 'es', 'sort_order' => 40, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'French', 'code' => 'fr', 'sort_order' => 50, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'German', 'code' => 'de', 'sort_order' => 60, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Italian', 'code' => 'it', 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Portuguese', 'code' => 'pt', 'sort_order' => 80, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Arabic', 'code' => 'ar', 'sort_order' => 90, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Hindi', 'code' => 'hi', 'sort_order' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Chinese', 'code' => 'zh', 'sort_order' => 110, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Japanese', 'code' => 'ja', 'sort_order' => 120, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Korean', 'code' => 'ko', 'sort_order' => 130, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Turkish', 'code' => 'tr', 'sort_order' => 140, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Ukrainian', 'code' => 'uk', 'sort_order' => 150, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $isoLanguagesPath = '/usr/share/iso-codes/json/iso_639-3.json';
        if (is_file($isoLanguagesPath)) {
            $isoLanguages = collect(json_decode(file_get_contents($isoLanguagesPath), true)['639-3'] ?? [])
                ->map(fn (array $item, int $index): array => [
                    'name' => $item['name'] ?? null,
                    'code' => $item['alpha_3'] ?? null,
                    'sort_order' => 1000 + $index,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                ->filter(fn (array $item): bool => filled($item['name']) && filled($item['code']));

            $videoLanguages = $videoLanguages->concat($isoLanguages)->unique('name')->unique('code')->values();
        }
        $videoLanguages->chunk(100)->each(fn ($chunk) => DB::table('video_languages')->insertOrIgnore($chunk->all()));

        Schema::table('videos', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->after('description')->constrained('video_categories')->nullOnDelete();
            $table->foreignId('language_id')->nullable()->after('category_id')->constrained('video_languages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('language_id');
            $table->dropConstrainedForeignId('category_id');
        });

        Schema::dropIfExists('video_languages');
        Schema::dropIfExists('video_categories');
    }
};
