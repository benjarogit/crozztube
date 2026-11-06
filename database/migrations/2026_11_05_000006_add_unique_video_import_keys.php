<?php

use App\Models\Video;
use App\Support\VideoImportIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('videos', static function (Blueprint $table): void {
            $table->char('import_source_key', 64)->nullable()->unique();
            $table->char('import_url_key', 64)->nullable()->unique();
        });
        // Keep all existing records. The oldest record claims each identity;
        // historical duplicates stay intact but cannot be imported again.
        Video::query()->withoutEagerLoads()->where('source_type', 'import')->orderBy('id')->chunkById(200, static function ($videos): void {
            foreach ($videos as $video) {
                $keys = VideoImportIdentity::forVideo($video);
                foreach ($keys as $column => $key) {
                    if ($key && DB::table('videos')->where($column, $key)->exists()) $keys[$column] = null;
                }
                DB::table('videos')->where('id', $video->id)->update($keys);
            }
        });
    }

    public function down(): void
    {
        Schema::table('videos', static function (Blueprint $table): void {
            $table->dropUnique(['import_source_key']);
            $table->dropUnique(['import_url_key']);
            $table->dropColumn(['import_source_key', 'import_url_key']);
        });
    }
};
