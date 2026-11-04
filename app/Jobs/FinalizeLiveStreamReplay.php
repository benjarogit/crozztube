<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\Video;
use App\Support\LiveKitSettings;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use App\Support\Slugs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FinalizeLiveStreamReplay implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 20;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(public int $liveStreamId) {}

    public function uniqueId(): string
    {
        return (string) $this->liveStreamId;
    }

    public function backoff(): array
    {
        return [2, 5, 10, 15, 30, 60];
    }

    public function handle(): void
    {
        $liveStream = LiveStream::query()->with(['category', 'user', 'channel'])->find($this->liveStreamId);
        if (! $liveStream || $liveStream->replay_video_id) return;

        $storedSettings = (array) $liveStream->stream_settings;
        $recording = (array) ($storedSettings['_recording'] ?? []);
        $egressId = trim((string) ($recording['egress_id'] ?? ''));
        $room = trim((string) ($recording['room'] ?? ''));
        $localPath = trim((string) ($recording['local_path'] ?? ''));
        if ($egressId === '' || $room === '' || $localPath === '') return;

        $localDisk = Storage::disk(str_starts_with($localPath, 'live-stream-recordings/') ? 'local' : 'public');
        $fileReady = $this->localFileIsStable($localDisk, $localPath);
        try {
            $egress = LiveKitSettings::findEgress($egressId, $room);
        } catch (RuntimeException $exception) {
            if (! $fileReady) throw $exception;
            report($exception);
            $egress = null;
        }
        if ($egress && LiveKitSettings::egressHasFailed($egress)) throw new RuntimeException('LiveKit replay recording failed.');
        if ($egress && ! LiveKitSettings::egressIsComplete($egress)) throw new RuntimeException('LiveKit is still finalizing the replay recording.');
        if (! $fileReady) throw new RuntimeException('The finalized live replay file is not available yet.');

        $size = (int) $localDisk->size($localPath);
        $quotaBytes = MediaStorage::userQuotaBytes($liveStream->user);
        if ($quotaBytes !== null && MediaStorage::userUsageBytes($liveStream->user) + $size > $quotaBytes) {
            throw new RuntimeException('The live replay exceeds the creator storage quota.');
        }

        $mediaPath = 'live-streams/source/'.now()->format('Y/m').'/'.$liveStream->uuid.'.mp4';
        if (! MediaStorage::exists($mediaPath)) {
            $source = $localDisk->readStream($localPath);
            if ($source === false) throw new RuntimeException('Unable to read the finalized live replay.');
            try {
                if (! MediaStorage::put($mediaPath, $source)) {
                    throw new RuntimeException('Unable to store the finalized live replay.');
                }
            } finally {
                fclose($source);
            }
            if (! MediaStorage::exists($mediaPath)) throw new RuntimeException('The stored live replay could not be verified.');
        }

        $videoId = DB::transaction(function () use ($liveStream, $mediaPath): int {
            $lockedStream = LiveStream::query()->lockForUpdate()->findOrFail($liveStream->id);
            if ($lockedStream->replay_video_id) return (int) $lockedStream->replay_video_id;

            $liveCategorySlug = $lockedStream->category_id
                ? DB::table('live_stream_categories')->where('id', $lockedStream->category_id)->value('slug')
                : null;
            $videoCategoryId = $liveCategorySlug
                ? DB::table('video_categories')->where('slug', $liveCategorySlug)->value('id')
                : null;
            $approvalStatus = PlatformFeatureSettings::requiresUploadApproval() ? 'pending' : 'approved';
            $audienceMode = (string) data_get($lockedStream->stream_settings, 'audience_mode', 'everyone');
            $requestedVisibility = $audienceMode === 'everyone'
                ? (in_array($lockedStream->visibility, ['public', 'unlisted'], true) ? $lockedStream->visibility : 'private')
                : 'private';
            $video = Video::query()->create([
                'user_id' => $lockedStream->user_id,
                'channel_id' => $lockedStream->channel_id,
                'title' => trim((string) $lockedStream->title) ?: 'Live stream replay',
                'slug' => Slugs::unique((string) ($lockedStream->title ?: 'Live stream replay'), Video::class),
                'description' => $lockedStream->description,
                'tags' => $lockedStream->tags,
                'category_id' => $videoCategoryId,
                'monetization_enabled' => (bool) data_get($lockedStream->monetization_settings, 'ads', false),
                'visibility' => 'private',
                'requested_visibility' => $requestedVisibility,
                'status' => 'processing',
                'approval_status' => $approvalStatus,
                'processing_progress' => 0,
                'processing_stage' => 'Queued for processing',
                'video_path' => $mediaPath,
                'thumbnail_path' => null,
                'source_type' => 'live_replay',
                'published_at' => null,
            ]);

            $settings = (array) $lockedStream->stream_settings;
            $recording = (array) ($settings['_recording'] ?? []);
            $recording['status'] = 'processing';
            $recording['media_path'] = $mediaPath;
            $recording['video_id'] = $video->id;
            $settings['_recording'] = $recording;
            $lockedStream->forceFill(['replay_video_id' => $video->id, 'stream_settings' => $settings])->save();
            DB::table('channels')->where('id', $lockedStream->channel_id)->increment('videos_count');
            ProcessVideoUpload::dispatch($video->id)->onQueue('videos')->afterCommit();

            return (int) $video->id;
        });

        if ($videoId > 0) $localDisk->delete($localPath);
    }

    public function failed(Throwable $exception): void
    {
        $liveStream = LiveStream::query()->find($this->liveStreamId);
        if (! $liveStream || $liveStream->replay_video_id) return;

        $settings = (array) $liveStream->stream_settings;
        $recording = (array) ($settings['_recording'] ?? []);
        $recording['status'] = 'failed';
        $recording['error'] = mb_substr($exception->getMessage(), 0, 500);
        $settings['_recording'] = $recording;
        $liveStream->forceFill(['stream_settings' => $settings])->save();
        report($exception);
    }

    private function localFileIsStable($disk, string $path): bool
    {
        if (! $disk->exists($path) || (int) $disk->size($path) <= 1024) return false;
        $size = (int) $disk->size($path);
        usleep(250000);

        return $disk->exists($path) && (int) $disk->size($path) === $size;
    }
}
