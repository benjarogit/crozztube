<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_app_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('platform', 24)->unique();
            $table->string('latest_version', 40)->default('1.0.0');
            $table->string('minimum_version', 40)->default('1.0.0');
            $table->boolean('supported')->default(true);
            $table->string('update_url', 2048)->nullable();
            $table->string('release_notes_url', 2048)->nullable();
            $table->string('update_message', 500)->nullable();
            $table->timestamps();
        });

        $now = now();
        DB::table('platform_app_versions')->insert(array_map(
            static fn (string $platform): array => [
                'platform' => $platform,
                'latest_version' => '1.0.0',
                'minimum_version' => '1.0.0',
                'supported' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['android', 'ios', 'android-tv', 'tvos', 'tizen', 'webos'],
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_app_versions');
    }
};
