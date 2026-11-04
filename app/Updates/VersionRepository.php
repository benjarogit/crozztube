<?php

namespace App\Updates;

use App\Models\SystemUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class VersionRepository
{
    public function current(): string
    {
        $completed = '';
        try {
            if (Schema::hasTable('system_updates')) {
                $active = SystemUpdate::query()
                    ->whereIn('status', SystemUpdate::ACTIVE_STATUSES)
                    ->latest('id')
                    ->value('from_version');
                if (UpdateManifest::isValidVersion($active)) {
                    return $active;
                }

                $completed = (string) SystemUpdate::query()
                    ->where('status', 'completed')
                    ->latest('completed_at')
                    ->latest('id')
                    ->value('to_version');
            }
        } catch (Throwable) {
            // The VERSION file remains available while the database is unavailable.
        }

        $path = (string) config('playhub-updates.version_file', base_path('VERSION'));
        $version = is_readable($path) ? trim((string) file_get_contents($path)) : '';

        if (UpdateManifest::isValidVersion($completed)) {
            return ! UpdateManifest::isValidVersion($version) || version_compare($completed, $version, '>') ? $completed : $version;
        }

        return UpdateManifest::isValidVersion($version) ? $version : '0.0.0';
    }

    public function commit(SystemUpdate $update): void
    {
        $this->writeFile((string) config('playhub-updates.version_file', base_path('VERSION')), $update->to_version);
    }

    public function complete(SystemUpdate $update): void
    {
        DB::transaction(function () use ($update): void {
            $locked = SystemUpdate::query()->lockForUpdate()->findOrFail($update->id);
            $locked->forceFill([
                'status' => 'completed',
                'progress' => 100,
                'current_step' => 'Update completed',
                'completed_at' => now(),
                'heartbeat_at' => now(),
                'error_details' => null,
            ])->save();
        });
    }

    public function write(string $version): void
    {
        if (! UpdateManifest::isValidVersion($version)) {
            throw new RuntimeException('Cannot write an invalid PlayHub version.');
        }
        $this->writeFile((string) config('playhub-updates.version_file', base_path('VERSION')), $version);
    }

    private function writeFile(string $path, string $version): void
    {
        $directory = dirname($path);
        if (! is_dir($directory) || ! is_writable($directory) || (file_exists($path) && ! is_file($path))) {
            throw new RuntimeException('The PlayHub VERSION file is not writable.');
        }

        $temporary = $path.'.update-'.bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $version."\n", LOCK_EX) === false || ! @rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The PlayHub VERSION file could not be updated atomically.');
        }
        @chmod($path, 0644);
    }
}
