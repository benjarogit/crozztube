<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'verified_at')) {
            return;
        }

        $now = now();
        DB::table('users')
            ->select(['id', 'settings', 'verified_at'])
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($now): void {
                foreach ($users as $user) {
                    if ($user->verified_at || ! $this->settingsGrantAdminAccess($user->settings)) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['verified_at' => $now, 'updated_at' => $now]);
                }
            });
    }

    public function down(): void
    {
        // Existing admin badges are intentionally preserved on rollback.
    }

    private function settingsGrantAdminAccess(mixed $settings): bool
    {
        $settings = is_array($settings) ? $settings : json_decode((string) $settings, true);
        if (! is_array($settings)) {
            return false;
        }

        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled
            || (bool) ($settings['is_admin'] ?? false)
            || in_array($role, ['admin', 'super admin', 'owner'], true);
    }
};
