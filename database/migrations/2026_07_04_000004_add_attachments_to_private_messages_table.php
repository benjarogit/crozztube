<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('private_messages') && ! Schema::hasColumn('private_messages', 'attachments')) {
            Schema::table('private_messages', function (Blueprint $table): void {
                $table->json('attachments')->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('private_messages', 'attachments')) {
            Schema::table('private_messages', function (Blueprint $table): void {
                $table->dropColumn('attachments');
            });
        }
    }
};
