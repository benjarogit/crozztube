<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('live_stream_invites', function (Blueprint $table): void {
            $table->string('role', 20)->default('viewer')->after('user_id')->index();
            $table->foreignId('invited_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable()->after('invited_by');
        });
    }

    public function down(): void
    {
        Schema::table('live_stream_invites', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('invited_by');
            $table->dropColumn(['role', 'accepted_at']);
        });
    }
};
