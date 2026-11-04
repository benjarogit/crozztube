<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $categoryId = DB::table('live_stream_categories')->where('slug', 'all')->value('id');
        if (! $categoryId) return;

        DB::table('live_streams')->where('category_id', $categoryId)->update(['category_id' => null]);
        DB::table('live_stream_categories')->where('id', $categoryId)->delete();
    }

    public function down(): void
    {
        DB::table('live_stream_categories')->insertOrIgnore([
            'name' => 'Any (All)',
            'slug' => 'all',
            'sort_order' => 10,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
