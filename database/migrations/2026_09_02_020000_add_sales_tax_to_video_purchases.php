<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('video_purchases', function (Blueprint $table): void {
            $table->unsignedBigInteger('tax_cents')->default(0)->after('gross_cents');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('tax_cents');
            $table->string('tax_type', 20)->nullable()->after('tax_rate');
        });
    }

    public function down(): void
    {
        Schema::table('video_purchases', function (Blueprint $table): void {
            $table->dropColumn(['tax_cents', 'tax_rate', 'tax_type']);
        });
    }
};
