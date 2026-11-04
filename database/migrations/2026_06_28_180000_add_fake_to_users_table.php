<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->enum('fake', ['false', 'true'])->default('false')->after('settings');
        });

        DB::table('users')
            ->where('email', 'like', '%@fake.playhub.local')
            ->orWhere('settings->generated_fake', true)
            ->update(['fake' => 'true']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('fake');
        });
    }
};
