<?php

namespace App\Jobs;

use App\Models\LiveStream;
use App\Support\LiveKitSettings;
use App\Support\MediaStorage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class GenerateLiveStreamThumbnail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public int $liveStreamId) {}

    public function backoff(): array
    {
        return [5, 15];
    }

    public function handle(): void
    {
        $liveStream = LiveStream::query()->find($this->liveStreamId);
        if (! $liveStream || $liveStream->thumbnail_path || $liveStream->status !== 'live') return;

        $room = 'playhub-live-'.$liveStream->uuid;
        $relativeDirectory = 'thumbnails/'.$liveStream->uuid;
        $outputDirectory = MediaStorage::ensureLocalDirectory('live-streams/'.$relativeDirectory, 0777);
        foreach (['jpg', 'jpeg'] as $extension) {
            File::delete(File::glob($outputDirectory.'/thumbnail*.'.$extension));
        }

        $egressId = '';
        try {
            $egress = LiveKitSettings::startRoomThumbnailCapture(
                $room,
                '/out/'.$relativeDirectory.'/thumbnail',
                (array) $liveStream->stream_settings,
            );
            $egressId = (string) ($egress['egress_id'] ?? $egress['egressId'] ?? '');
            if ($egressId === '') throw new RuntimeException('LiveKit did not return a thumbnail Egress ID.');

            $thumbnail = $this->waitForThumbnail($outputDirectory);
            if (! $thumbnail) throw new RuntimeException('LiveKit did not generate a thumbnail image.');

            $path = 'live-streams/thumbnails/'.now()->format('Y/m').'/'.$liveStream->uuid.'.jpg';
            $stream = fopen($thumbnail, 'rb');
            if ($stream === false) throw new RuntimeException('Unable to read the generated live stream thumbnail.');
            try {
                if (! MediaStorage::put($path, $stream)) {
                    throw new RuntimeException('Unable to store the generated live stream thumbnail.');
                }
            } finally {
                fclose($stream);
            }
            if (! MediaStorage::disk()->exists($path)) throw new RuntimeException('The generated live stream thumbnail is missing from storage.');

            $updated = LiveStream::query()->whereKey($liveStream->id)->whereNull('thumbnail_path')->update(['thumbnail_path' => $path]);
            if (! $updated) MediaStorage::delete($path);
        } finally {
            if ($egressId !== '') {
                try {
                    LiveKitSettings::stopEgress($egressId, $room);
                } catch (Throwable) {
                    // The short-lived Egress also exits when its room closes.
                }
            }
            File::deleteDirectory($outputDirectory);
        }
    }

    private function waitForThumbnail(string $directory): ?string
    {
        $deadline = microtime(true) + 20;
        do {
            foreach (['jpg', 'jpeg'] as $extension) {
                foreach (File::glob($directory.'/thumbnail*.'.$extension) as $path) {
                    if (is_file($path) && filesize($path) > 1024) return $path;
                }
            }
            usleep(250000);
        } while (microtime(true) < $deadline);

        return null;
    }
}
