<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\LiveStreamController;
use App\Jobs\FinalizeLiveStreamReplay;
use App\Models\Channel;
use App\Models\LiveStream;
use App\Models\LiveStreamPoll;
use App\Models\User;
use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\MediaImageCache;
use App\Support\Slugs;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

Schedule::command('uploads:prune')->daily()->withoutOverlapping();
Schedule::call(fn () => MediaImageCache::prune())
    ->daily()->name('prune-media-image-cache')->withoutOverlapping();
Schedule::command('push:recover')->everyMinute()->withoutOverlapping();

Artisan::command('playhub:install-admin {credentials}', function (string $credentials): int {
    $path = realpath($credentials);
    $storageRoot = realpath(storage_path('app'));
    if (! $path || ! $storageRoot || ! str_starts_with($path, $storageRoot.DIRECTORY_SEPARATOR)) {
        $this->error('The installer credentials file is invalid.');

        return 1;
    }

    $data = json_decode((string) file_get_contents($path), true);
    $validator = Validator::make(is_array($data) ? $data : [], [
        'name' => ['required', 'string', 'min:2', 'max:80'],
        'username' => ['required', 'alpha_dash:ascii', 'min:4', 'max:30', 'unique:users,username'],
        'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
        'password' => ['required', 'string', 'min:8'],
    ]);
    if ($validator->fails()) {
        $this->error($validator->errors()->first());

        return 1;
    }

    $data = $validator->validated();
    DB::transaction(function () use ($data): void {
        $user = new User([
            'name' => trim($data['name']),
            'username' => strtolower(trim($data['username'])),
            'email' => strtolower(trim($data['email'])),
            'password' => $data['password'],
            'registration_provider' => 'installer',
            'settings' => [
                'role' => 'owner',
                'status' => 'active',
                'is_admin' => true,
            ],
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'verified_at' => now(),
        ])->save();

        Channel::query()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'slug' => Slugs::unique($user->username, Channel::class),
            'bio' => null,
        ]);
    });

    $this->info('Administrator account created successfully.');

    return 0;
})->purpose('Create the initial PlayHub owner account during installation');

$backupSettings = [];
$backupSettingsPath = storage_path('app/backups/.settings.json');
if (is_file($backupSettingsPath)) {
    $backupSettings = json_decode((string) file_get_contents($backupSettingsPath), true) ?: [];
}
if ($backupSettings['enabled'] ?? false) {
    [$backupHour, $backupMinute] = array_map('intval', explode(':', (string) ($backupSettings['time'] ?? '02:00')));
    $frequency = (string) ($backupSettings['frequency'] ?? 'daily');
    $cron = match ($frequency) {
        'weekly' => sprintf('%d %d * * %d', $backupMinute, $backupHour, (int) ($backupSettings['day_of_week'] ?? 0)),
        'monthly' => sprintf('%d %d %d * *', $backupMinute, $backupHour, (int) ($backupSettings['day_of_month'] ?? 1)),
        default => sprintf('%d %d * * *', $backupMinute, $backupHour),
    };

    Schedule::call(fn () => app(AdminController::class)->runScheduledBackup())
        ->cron($cron)
        ->name('automatic-system-backup')
        ->withoutOverlapping();
}

Schedule::command('model:prune')->daily();

Schedule::command('playhub:updates:recover')
    ->everyFiveMinutes()
    ->name('recover-interrupted-playhub-updates')
    ->evenInMaintenanceMode()
    ->withoutOverlapping();

Schedule::call(function (): void {
    Video::query()
        ->where('status', 'ready')
        ->where('publish_as_draft', false)
        ->where(fn ($query) => $query->where('approval_status', 'approved')->orWhereNull('approval_status'))
        ->whereNotNull('scheduled_at')
        ->whereNull('published_at')
        ->where('scheduled_at', '<=', now())
        ->chunkById(100, function ($videos): void {
            foreach ($videos as $video) {
                $video->forceFill([
                    'visibility' => $video->requested_visibility ?: 'public',
                    'published_at' => now(),
                ])->save();
            }
        });
})->everyMinute()->name('publish-expired-scheduled-videos')->withoutOverlapping();

Schedule::call(function (): void {
    LiveStream::query()
        ->where('status', 'ended')
        ->whereNull('replay_video_id')
        ->whereNotNull('stream_settings->_recording')
        ->chunkById(50, function ($streams): void {
            foreach ($streams as $stream) {
                $recording = (array) data_get($stream->stream_settings, '_recording', []);
                $path = trim((string) ($recording['local_path'] ?? ''));
                if ($path === '' || ! in_array($recording['status'] ?? null, ['finalizing', 'failed'], true)) {
                    continue;
                }
                $disk = Storage::disk(str_starts_with($path, 'live-stream-recordings/') ? 'local' : 'public');
                if ($disk->exists($path) && (int) $disk->size($path) > 1024) {
                    FinalizeLiveStreamReplay::dispatch($stream->id)->onQueue('videos');
                }
            }
        });
})->everyMinute()->name('finalize-live-stream-replays')->withoutOverlapping();

Schedule::call(function (): void {
    LiveStream::query()
        ->with('replayVideo')
        ->whereNotNull('replay_expires_at')
        ->where('replay_expires_at', '<=', now())
        ->where(fn ($query) => $query->whereNotNull('replay_video_id')->orWhereNotNull('thumbnail_path')->orWhereNotNull('stream_settings->_recording'))
        ->chunkById(50, function ($streams): void {
            foreach ($streams as $stream) {
                $video = $stream->replayVideo;
                $settings = (array) $stream->stream_settings;
                $recording = (array) ($settings['_recording'] ?? []);
                $paths = array_filter([
                    $stream->thumbnail_path,
                    $video?->video_path,
                    $video?->thumbnail_path,
                    $video?->captions_path,
                    ...collect($video?->renditions ?: [])->pluck('path')->all(),
                    $recording['local_path'] ?? null,
                    $recording['media_path'] ?? null,
                ]);
                unset($settings['_recording']);
                DB::transaction(function () use ($stream, $video, $settings): void {
                    $stream->forceFill(['replay_video_id' => null, 'thumbnail_path' => null, 'stream_settings' => $settings])->save();
                    if (! $video) {
                        return;
                    }
                    $video->channel()->decrement('videos_count');
                    $video->delete();
                });
                MediaStorage::delete($paths);
            }
        });
})->hourly()->name('prune-expired-live-replays')->withoutOverlapping();

Schedule::call(function (): void {
    LiveStreamPoll::query()
        ->whereIn('status', ['active', 'scheduled'])
        ->whereNotNull('ends_at')
        ->where('ends_at', '<=', now())
        ->update(['status' => 'closed']);

    LiveStreamPoll::query()
        ->where('status', 'scheduled')
        ->where('starts_at', '<=', now())
        ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()))
        ->whereHas('liveStream', fn ($query) => $query->where('status', 'live'))
        ->orderBy('starts_at')
        ->chunkById(50, function ($polls): void {
            foreach ($polls as $poll) {
                DB::transaction(function () use ($poll): void {
                    LiveStreamPoll::query()
                        ->where('live_stream_id', $poll->live_stream_id)
                        ->where('id', '!=', $poll->id)
                        ->where('status', 'active')
                        ->update(['status' => 'closed', 'ends_at' => now()]);
                    $poll->update(['status' => 'active']);
                });
            }
        });
})->everyMinute()->name('activate-scheduled-live-polls')->withoutOverlapping();

Schedule::call(fn () => app(LiveStreamController::class)->reconcileInactiveHosts())
    ->everyMinute()->name('reconcile-live-stream-publishers')->withoutOverlapping();

// Thumbnail replication and health checks never execute outside this window.
Schedule::call(function (): void {
    if (\App\Support\Thumbnails\NightWindow::contains() && ! \App\Support\Thumbnails\NightWindow::paused()) {
        \App\Jobs\MaintainThumbnails::dispatch();
    }
})->everyFiveMinutes()->name('thumbnail-night-maintenance')->withoutOverlapping()->onOneServer();
