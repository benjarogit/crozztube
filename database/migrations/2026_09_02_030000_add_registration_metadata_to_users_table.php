<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('registration_ip', 45)->nullable()->after('gender');
            $table->string('registration_provider', 32)->nullable()->index()->after('registration_ip');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['registration_provider']);
            $table->dropColumn(['registration_ip', 'registration_provider']);
        });
    }
};
