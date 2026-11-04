<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Models\UploadSession;
use App\Models\UserNotification;
use App\Models\Video;
use App\Support\CompleteVideoProcessing;
use App\Support\MediaBinaries;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessVideoUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 21600;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = Video::query()->findOrFail($this->videoId);
        $disk = MediaStorage::disk();
        $diskName = MediaStorage::diskName();
        $localDisk = Storage::disk('local');
        $workDir = 'video-processing/'.$video->id.'-'.Str::uuid();
        $sourceDisk = $disk;
        $sourceDiskName = $diskName;

        try {
            if (! $disk->fileExists($video->video_path)) {
                if (! $localDisk->fileExists($video->video_path)) {
                    $video->update([
                        'status' => 'failed',
                        'visibility' => 'private',
                        'processing_stage' => 'Source video file is missing',
                    ]);
                    UploadSession::query()->where('video_id', $video->id)->update([
                        'status' => 'failed',
                        'error_message' => 'Source video file is missing.',
                    ]);

                    return;
                }
                $sourceDisk = $localDisk;
                $sourceDiskName = 'local';
            }

            $sourcePath = $this->localMediaPath($sourceDisk, $sourceDiskName, $video->video_path, $workDir.'/source.'.pathinfo($video->video_path, PATHINFO_EXTENSION));
            $outputDir = 'videos/processed/'.now()->format('Y/m').'/'.$video->id;
            $outputPath = $localDisk->path($workDir.'/processed');

            if (! is_dir($outputPath) && ! $localDisk->makeDirectory($workDir.'/processed')) {
                throw new \RuntimeException('Unable to create video processing directory.');
            }

            $video->update([
                'status' => 'processing',
                'visibility' => 'private',
                'processing_progress' => 3,
                'processing_stage' => 'Preparing source',
            ]);

            $duration = $this->probeDuration($sourcePath);
            $videoStreamIndex = $this->videoStreamIndex($sourcePath);
            $hasAudio = $this->hasAudio($sourcePath);
            $captionsDisk = $video->captions_path && $disk->fileExists($video->captions_path) ? $disk : ($video->captions_path && $localDisk->fileExists($video->captions_path) ? $localDisk : null);
            $captionsDiskName = $captionsDisk === $localDisk ? 'local' : $diskName;
            $captionsPath = $video->captions_path && $captionsDisk ? $this->localMediaPath($captionsDisk, $captionsDiskName, $video->captions_path, $workDir.'/captions.'.pathinfo($video->captions_path, PATHINFO_EXTENSION)) : null;
            $sourceVideoPath = $video->video_path;
            $enabledRenditions = array_values(array_intersect(
                ['low', 'medium', 'high', 'original'],
                (array) data_get(PlatformFeatureSettings::settings(), 'uploads.renditions', [])
            ));
            if (! $enabledRenditions) {
                $enabledRenditions = ['low', 'medium', 'high', 'original'];
            }
            $renditions = [];
            $profiles = [
                ['quality' => 'low', 'width' => 426, 'video_bitrate' => '500k', 'audio_bitrate' => '96k', 'progress' => 25],
                ['quality' => 'medium', 'width' => 854, 'video_bitrate' => '1200k', 'audio_bitrate' => '128k', 'progress' => 48],
                ['quality' => 'high', 'width' => 1280, 'video_bitrate' => '2800k', 'audio_bitrate' => '160k', 'progress' => 72],
            ];

            foreach ($profiles as $profile) {
                if (! in_array($profile['quality'], $enabledRenditions, true)) {
                    continue;
                }
                $video->update([
                    'processing_progress' => max(5, $profile['progress'] - 18),
                    'processing_stage' => 'Encoding '.$profile['quality'].' quality',
                ]);

                $relativePath = $outputDir.'/'.$profile['quality'].'.mp4';
                $localTarget = $outputPath.'/'.$profile['quality'].'.mp4';
                $this->encodeRendition($sourcePath, $localTarget, $profile, $videoStreamIndex, $hasAudio, $captionsPath);
                $this->putLocalFile($relativePath, $localTarget);

                $renditions[] = [
                    'quality' => $profile['quality'],
                    'path' => $relativePath,
                    'width' => $profile['width'],
                    'video_bitrate' => $profile['video_bitrate'],
                ];

                $video->update([
                    'processing_progress' => $profile['progress'],
                    'renditions' => $renditions,
                ]);
            }

            if (! $video->thumbnail_path) {
                $thumbnailPath = 'videos/thumbnails/'.now()->format('Y/m').'/'.$video->id.'.jpg';
                $localThumbnail = $localDisk->path($workDir.'/thumbnail.jpg');
                $this->generateThumbnail($sourcePath, $localThumbnail, $videoStreamIndex);
                $this->putLocalFile($thumbnailPath, $localThumbnail);
                $video->thumbnail_path = $thumbnailPath;
            }

            if (in_array('original', $enabledRenditions, true)) {
                $video->update([
                    'processing_progress' => 92,
                    'processing_stage' => 'Moving original quality',
                ]);
                $extension = strtolower(pathinfo($sourceVideoPath, PATHINFO_EXTENSION)) ?: 'mp4';
                $originalPath = $outputDir.'/original.'.$extension;
                $this->putLocalFile($originalPath, $sourcePath);
                $renditions[] = [
                    'quality' => 'original',
                    'path' => $originalPath,
                    'width' => null,
                    'video_bitrate' => null,
                ];
            }

            $processedVideoPath = collect($renditions)->firstWhere('quality', 'original')['path']
                ?? collect($renditions)->last()['path'];

            CompleteVideoProcessing::save($video->id, [
                'renditions' => $renditions,
                'video_path' => $processedVideoPath,
                'thumbnail_path' => $video->thumbnail_path,
                'duration_seconds' => $duration,
            ]);
            if ($sourceVideoPath !== $processedVideoPath) {
                try {
                    MediaStorage::delete($sourceVideoPath);
                } catch (Throwable $cleanupException) {
                    // The processed objects are already committed. A provider
                    // cleanup failure must not mark a playable video as failed.
                    report($cleanupException);
                }
            }
            UploadSession::query()->where('video_id', $video->id)->update(['status' => 'completed', 'error_message' => null]);

            $video->refresh();
            $this->notifyVideoReady($video);
        } finally {
            $localDisk->deleteDirectory($workDir);
        }
    }

    public function failed(Throwable $exception): void
    {
        Video::query()->whereKey($this->videoId)->update([
            'status' => 'failed',
            'visibility' => 'private',
            'processing_stage' => 'Processing failed',
        ]);
        UploadSession::query()->where('video_id', $this->videoId)->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
        ]);
        $this->updateLiveReplayStatus('failed', $exception->getMessage());

        Log::error('Video processing failed', [
            'video_id' => $this->videoId,
            'error' => $exception->getMessage(),
        ]);
    }

    private function encodeRendition(string $sourcePath, string $targetPath, array $profile, int $videoStreamIndex, bool $hasAudio, ?string $captionsPath): void
    {
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        $command = [MediaBinaries::ffmpeg(), '-nostdin', '-y', '-hide_banner', '-i', $sourcePath];
        if ($captionsPath) {
            $command = [...$command, '-i', $captionsPath];
        }

        $command = [...$command, '-map', '0:'.$videoStreamIndex];
        if ($hasAudio) {
            $command = [...$command, '-map', '0:a:0?'];
        }
        if ($captionsPath) {
            $command = [...$command, '-map', '1:0?'];
        }

        if ($profile['width']) {
            $command = [...$command, '-vf', "scale='min({$profile['width']},iw)':-2"];
        }

        $command = [
            ...$command,
            '-c:v', 'libx264',
            '-preset', 'veryfast',
            '-pix_fmt', 'yuv420p',
            '-movflags', '+faststart',
        ];

        if ($profile['video_bitrate']) {
            $command = [...$command, '-b:v', $profile['video_bitrate'], '-maxrate', $profile['video_bitrate'], '-bufsize', ((int) $profile['video_bitrate'] * 2).'k'];
        } else {
            $command = [...$command, '-crf', '20'];
        }

        if ($hasAudio) {
            $command = [...$command, '-c:a', 'aac', '-b:a', $profile['audio_bitrate'], '-af', 'loudnorm=I=-16:TP=-1.5:LRA=11'];
        }

        if ($captionsPath) {
            $command = [...$command, '-c:s', 'mov_text', '-metadata:s:s:0', 'language=und'];
        }

        $this->runProcess([...$command, $targetPath], 21600);
    }

    private function generateThumbnail(string $sourcePath, string $targetPath, int $videoStreamIndex): void
    {
        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        $this->runProcess([
            MediaBinaries::ffmpeg(),
            '-nostdin',
            '-y',
            '-hide_banner',
            '-ss',
            '00:00:01',
            '-i',
            $sourcePath,
            '-map',
            '0:'.$videoStreamIndex,
            '-frames:v',
            '1',
            '-q:v',
            '3',
            $targetPath,
        ], 300);
    }

    private function probeDuration(string $sourcePath): ?int
    {
        $process = new Process([
            MediaBinaries::ffprobe(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $sourcePath,
        ]);
        $process->setTimeout(120);
        $process->run();

        $duration = (float) trim($process->getOutput());

        return $process->isSuccessful() && $duration > 0 ? (int) ceil($duration) : null;
    }

    private function videoStreamIndex(string $sourcePath): int
    {
        $process = new Process([
            MediaBinaries::ffprobe(),
            '-v',
            'error',
            '-select_streams',
            'v',
            '-show_entries',
            'stream=index,duration,nb_frames,width,height:stream_disposition=attached_pic',
            '-of',
            'json',
            $sourcePath,
        ]);
        $process->setTimeout(120);
        $process->mustRun();

        $streams = (array) (json_decode($process->getOutput(), true)['streams'] ?? []);
        $playable = array_values(array_filter($streams, fn (array $stream): bool => ! (bool) data_get($stream, 'disposition.attached_pic', false)));
        if (! $playable) {
            $playable = $streams;
        }
        if (! $playable) {
            throw new \RuntimeException('The uploaded file does not contain a video stream.');
        }

        usort($playable, function (array $left, array $right): int {
            $leftScore = [(float) ($left['duration'] ?? 0), (int) ($left['nb_frames'] ?? 0), (int) ($left['width'] ?? 0) * (int) ($left['height'] ?? 0)];
            $rightScore = [(float) ($right['duration'] ?? 0), (int) ($right['nb_frames'] ?? 0), (int) ($right['width'] ?? 0) * (int) ($right['height'] ?? 0)];

            return $rightScore <=> $leftScore;
        });

        return (int) $playable[0]['index'];
    }

    private function hasAudio(string $sourcePath): bool
    {
        $process = new Process([
            MediaBinaries::ffprobe(),
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=index',
            '-of',
            'csv=p=0',
            $sourcePath,
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }

    private function runProcess(array $command, int $timeout): void
    {
        $process = new Process($command);
        $process->setTimeout($timeout);
        $process->mustRun();
    }

    private function localMediaPath($disk, string $diskName, string $path, string $localPath): string
    {
        if (in_array($diskName, ['local', 'public'], true)) {
            return Storage::disk($diskName)->path($path);
        }

        $target = Storage::disk('local')->path($localPath);
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }

        $read = null;
        $write = null;
        $copied = false;
        try {
            $expectedSize = $disk->size($path);
            $read = $disk->readStream($path);
            abort_unless(is_resource($read), 500, 'Cannot read media file.');
            $write = fopen($target, 'wb');
            abort_unless(is_resource($write), 500, 'Cannot prepare media file.');
            $copied = stream_copy_to_stream($read, $write);
            abort_unless($copied !== false && $copied === $expectedSize, 500, 'Cannot download media file completely.');
        } finally {
            if (is_resource($read)) {
                fclose($read);
            }
            if (is_resource($write)) {
                fclose($write);
            }
            if ($copied === false || $copied !== ($expectedSize ?? null)) {
                @unlink($target);
            }
        }

        return $target;
    }

    private function putLocalFile(string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        abort_if($stream === false, 500, 'Cannot upload processed media.');

        try {
            abort_unless(MediaStorage::put($path, $stream), 500, 'Cannot upload processed media.');
        } finally {
            fclose($stream);
        }
    }

    private function notifyVideoReady(Video $video): void
    {
        $liveStream = LiveStream::query()->where('replay_video_id', $video->id)->first();
        if (! $liveStream) {
            UserNotification::query()->firstOrCreate([
                'user_id' => $video->user_id,
                'video_id' => $video->id,
                'type' => 'video_ready',
            ]);

            return;
        }

        $this->updateLiveReplayStatus('complete');
        $participantUserIds = DB::table('live_stream_invites')
            ->where('live_stream_id', $liveStream->id)
            ->whereNotNull('accepted_at')
            ->whereIn('role', ['guest', 'cohost'])
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        foreach ($this->liveReplayAudienceUserIds($liveStream) as $userId) {
            $audienceRole = $userId === (int) $liveStream->user_id
                ? 'streamer'
                : (in_array($userId, $participantUserIds, true) ? 'participant' : 'viewer');
            UserNotification::query()->firstOrCreate([
                'user_id' => $userId,
                'video_id' => $video->id,
                'type' => 'live_replay_ready',
            ], [
                'actor_id' => $liveStream->user_id,
                'channel_id' => $liveStream->channel_id,
                'data' => [
                    'live_stream_id' => $liveStream->id,
                    'live_stream_title' => $liveStream->title,
                    'audience_role' => $audienceRole,
                ],
            ]);
        }
    }

    private function updateLiveReplayStatus(string $status, ?string $error = null): void
    {
        $liveStream = LiveStream::query()->where('replay_video_id', $this->videoId)->first();
        if (! $liveStream) {
            return;
        }

        $settings = (array) $liveStream->stream_settings;
        $recording = (array) ($settings['_recording'] ?? []);
        $recording['status'] = $status;
        $recording['completed_at'] = now()->toIso8601String();
        if ($error !== null) {
            $recording['error'] = mb_substr($error, 0, 500);
        } else {
            unset($recording['error']);
        }
        $settings['_recording'] = $recording;
        $liveStream->forceFill(['stream_settings' => $settings])->save();
    }

    private function liveReplayAudienceUserIds(LiveStream $liveStream): array
    {
        $ids = collect([(int) $liveStream->user_id]);
        if (Schema::hasTable('live_stream_viewers')) {
            $ids = $ids->merge(DB::table('live_stream_viewers')->where('live_stream_id', $liveStream->id)->pluck('user_id'));
        }

        $ids = $ids
            ->merge(DB::table('live_stream_chat_messages')->where('live_stream_id', $liveStream->id)->pluck('user_id'))
            ->merge(DB::table('live_stream_invites')->where('live_stream_id', $liveStream->id)->whereNotNull('accepted_at')->pluck('user_id'))
            ->merge(DB::table('live_stream_poll_votes')
                ->join('live_stream_polls', 'live_stream_polls.id', '=', 'live_stream_poll_votes.poll_id')
                ->where('live_stream_polls.live_stream_id', $liveStream->id)
                ->pluck('live_stream_poll_votes.user_id'))
            ->merge(DB::table('live_stream_contributions')->where('live_stream_id', $liveStream->id)->where('status', 'completed')->pluck('sender_id'))
            ->merge(DB::table('live_stream_ad_impressions')->where('live_stream_id', $liveStream->id)->whereNotNull('viewer_id')->pluck('viewer_id'));

        return $ids->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
    }
}
