<?php

namespace App\Jobs;

use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\VideoImportSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;
use Throwable;

class ImportVideoFromUrl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 21600;

    public function __construct(public int $videoId) {}

    public function handle(): void
    {
        $video = Video::query()->findOrFail($this->videoId);
        $provider = (string) $video->import_provider;
        $url = (string) $video->import_url;
        $settings = VideoImportSettings::settings();
        $providers = VideoImportSettings::providers();

        if (! VideoImportSettings::providerEnabled($provider)) {
            throw new RuntimeException('Video import provider is disabled.');
        }

        $this->validateImportUrl($url, $providers[$provider]['domains'] ?? []);

        $tempRelativeDir = 'video-imports/'.$video->id.'-'.Str::uuid();
        $tempDir = Storage::disk('local')->path($tempRelativeDir);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0775, true);
        }

        try {
            $video->update([
                'status' => 'processing',
                'visibility' => 'private',
                'processing_progress' => 2,
                'processing_stage' => 'Importing from '.$providers[$provider]['label'],
            ]);

            $localPath = $provider === 'direct'
                ? $this->downloadDirectFile($url, $tempDir)
                : $this->downloadWithCommand($url, $tempDir, (string) ($settings['downloaderCommand'] ?? 'yt-dlp'));

            $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION) ?: 'mp4');
            $safeTitle = Str::slug($video->title) ?: 'imported-video';
            $targetPath = 'videos/originals/'.now()->format('Y/m').'/'.$video->id.'-'.$safeTitle.'.'.$extension;

            $this->putLocalFile($targetPath, $localPath);

            $video->update([
                'video_path' => $targetPath,
                'processing_progress' => 5,
                'processing_stage' => 'Queued for processing',
            ]);

            ProcessVideoUpload::dispatch($video->id)->onQueue('videos');
        } finally {
            Storage::disk('local')->deleteDirectory($tempRelativeDir);
        }
    }

    public function failed(Throwable $exception): void
    {
        Video::query()->whereKey($this->videoId)->update([
            'status' => 'failed',
            'visibility' => 'private',
            'processing_stage' => 'Video import failed',
            'processing_progress' => 100,
        ]);

        Log::error('Video import failed', [
            'video_id' => $this->videoId,
            'exception_type' => $exception::class,
        ]);
    }

    private function downloadDirectFile(string $url, string $tempDir): string
    {
        $extension = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        $allowedExtensions = ['mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v'];
        if ($extension !== '' && ! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('Direct imports must use a supported video file URL.');
        }

        $target = $tempDir.'/source.'.($extension ?: 'mp4');
        $maxBytes = (int) config('playhub.max_upload_mb', 2048) * 1024 * 1024;
        $context = stream_context_create([
            'http' => [
                'follow_location' => 1,
                'max_redirects' => 5,
                'timeout' => 120,
                'user_agent' => 'PlayHub Video Importer',
            ],
        ]);
        $read = @fopen($url, 'rb', false, $context);
        if ($read === false) {
            throw new RuntimeException('Cannot open remote video URL.');
        }
        $write = fopen($target, 'wb');
        if ($write === false) {
            fclose($read);
            throw new RuntimeException('Cannot prepare imported video file.');
        }

        $bytes = 0;
        try {
            while (! feof($read)) {
                $chunk = fread($read, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Cannot read remote video file.');
                }
                if ($chunk === '') {
                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > $maxBytes) {
                    throw new RuntimeException('Imported video exceeds the maximum upload size.');
                }
                fwrite($write, $chunk);
            }
        } finally {
            fclose($read);
            fclose($write);
        }

        if ($bytes < 1) {
            throw new RuntimeException('Remote video file is empty.');
        }

        return $target;
    }

    private function downloadWithCommand(string $url, string $tempDir, string $command): string
    {
        $command = trim($command);
        if ($command === '') {
            throw new RuntimeException('Video import downloader command is not configured.');
        }

        $output = $tempDir.'/source.%(ext)s';
        $process = Process::fromShellCommandline(sprintf(
            '%s --no-playlist --restrict-filenames --merge-output-format mp4 -f %s -o %s %s',
            $command,
            escapeshellarg('bv*+ba/best[ext=mp4]/best'),
            escapeshellarg($output),
            escapeshellarg($url)
        ));
        $process->setTimeout($this->timeout);
        try {
            $process->mustRun();
        } catch (ProcessException) {
            // Process exceptions include the full command line, including URL query secrets.
            throw new RuntimeException('Video import downloader failed.');
        }

        $files = collect(glob($tempDir.'/source.*') ?: [])
            ->filter(fn (string $path): bool => is_file($path) && filesize($path) > 0)
            ->sortByDesc(fn (string $path): int => filesize($path))
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException('Downloader did not create an importable video file.');
        }

        return $files->first();
    }

    private function validateImportUrl(string $url, array $allowedDomains): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('Import URL is invalid.');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Import URL must use HTTP or HTTPS.');
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('Import URL host is invalid.');
        }

        if ($allowedDomains && ! collect($allowedDomains)->contains(fn (string $domain): bool => $host === $domain || str_ends_with($host, '.'.$domain))) {
            throw new RuntimeException('Import URL does not match the selected provider.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (@gethostbynamel($host) ?: []);
        if (! $ips) {
            throw new RuntimeException('Import URL host cannot be resolved.');
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Import URL must resolve to a public host.');
            }
        }
    }

    private function putLocalFile(string $path, string $localPath): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read imported video file.');
        }

        try {
            if (! MediaStorage::put($path, $stream)) {
                throw new RuntimeException('Cannot store imported video file.');
            }
        } finally {
            fclose($stream);
        }
    }
}
