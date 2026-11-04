<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOOKUP_INDEX = 'live_ads_stream_viewer_served_idx';

    public function up(): void
    {
        if (! Schema::hasTable('live_stream_ad_impressions')) {
            return;
        }

        if (! Schema::hasColumn('live_stream_ad_impressions', 'duration_seconds')) {
            Schema::table('live_stream_ad_impressions', function (Blueprint $table): void {
                $table->unsignedInteger('duration_seconds')->default(0)->after('creator_amount_cents');
            });
        }

        if (! Schema::hasIndex('live_stream_ad_impressions', self::LOOKUP_INDEX)) {
            Schema::table('live_stream_ad_impressions', function (Blueprint $table): void {
                $table->index(['live_stream_id', 'viewer_id', 'served_at'], self::LOOKUP_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('live_stream_ad_impressions')) {
            return;
        }

        if (Schema::hasIndex('live_stream_ad_impressions', self::LOOKUP_INDEX)) {
            Schema::table('live_stream_ad_impressions', function (Blueprint $table): void {
                $table->dropIndex(self::LOOKUP_INDEX);
            });
        }

        if (Schema::hasColumn('live_stream_ad_impressions', 'duration_seconds')) {
            Schema::table('live_stream_ad_impressions', function (Blueprint $table): void {
                $table->dropColumn('duration_seconds');
            });
        }
    }
};
