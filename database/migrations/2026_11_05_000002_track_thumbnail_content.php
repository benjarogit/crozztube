<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('thumbnail_mirrors', fn (Blueprint $table) => $table->string('content_hash', 64)->nullable()->index());
        DB::table('thumbnail_providers')->insertOrIgnore(['id' => 'original', 'priority' => 4, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('thumbnail_mirrors')->where('provider_id', 'original')->delete();
        DB::table('thumbnail_providers')->where('id', 'original')->delete();
        Schema::table('thumbnail_mirrors', fn (Blueprint $table) => $table->dropColumn('content_hash'));
    }
};
