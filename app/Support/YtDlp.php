<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * yt-dlp als zentrale Metadaten-Engine.
 *
 * Wird nur fuer Metadaten benutzt (-J, kein Download). Das Loesen der
 * Direkt-URLs fuer die Wiedergabe macht das Source-Resolver-Plugin; beide
 * teilen sich denselben Binärpfad aus den Video-Import-Einstellungen.
 */
class YtDlp
{
    /** yt-dlp -J kann bei grossen Seiten einige Sekunden brauchen. */
    private const METADATA_TIMEOUT = 120.0;

    private const METADATA_CACHE_MINUTES = 10;

    private const VERSION_CACHE_MINUTES = 60;

    /**
     * Normalisierte Metadaten einer URL. Wirft, wenn yt-dlp die URL nicht
     * versteht. Das ist bewusst so: Aufrufer entscheiden, ob das ein Fehler ist.
     *
     * @return array{title:?string, description:?string, tags:array<int,string>,
     *               thumbnail_url:?string, duration_seconds:?int,
     *               chapters:array<int,array{start_time:int,title:string}>,
     *               author_name:?string, width:?int, height:?int,
     *               extractor:?string, webpage_url:?string}
     */
    public static function metadata(string $url): array
    {
        $url = trim($url);
        if ($url === '') {
            throw new RuntimeException('No URL supplied for yt-dlp metadata.');
        }

        $cacheKey = 'ytdlp:meta:'.sha1($url);

        return Cache::remember($cacheKey, now()->addMinutes(self::METADATA_CACHE_MINUTES), function () use ($url): array {
            return self::extract($url);
        });
    }

    /**
     * Wie metadata(), aber liefert bei jedem Fehler ein leeres Array. Fuer
     * Imports gedacht: yt-dlp ist eine Bereicherung, kein Pflicht-Gate.
     *
     * @return array<string, mixed>
     */
    public static function metadataQuietly(string $url): array
    {
        try {
            return self::metadata($url);
        } catch (Throwable $exception) {
            // Imports must not fail because yt-dlp is unavailable or does not
            // understand a URL; provider metadata and the mirror still work.
            logger()->debug('yt-dlp metadata unavailable', [
                'url' => $url,
                'error' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    public static function version(): ?string
    {
        return Cache::remember('ytdlp:version', now()->addMinutes(self::VERSION_CACHE_MINUTES), function (): ?string {
            try {
                $process = new Process([self::binary(), '--version'], null, null, null, 15.0);
                $process->run();

                return $process->isSuccessful() ? trim($process->getOutput()) : null;
            } catch (Throwable) {
                return null;
            }
        });
    }

    public static function available(): bool
    {
        return self::version() !== null;
    }

    public static function binary(): string
    {
        return VideoImportSettings::downloaderCommand();
    }

    /**
     * @return array{title:?string, description:?string, tags:array<int,string>,
     *               thumbnail_url:?string, duration_seconds:?int,
     *               chapters:array<int,array{start_time:int,title:string}>,
     *               author_name:?string, width:?int, height:?int,
     *               extractor:?string, webpage_url:?string}
     */
    private static function extract(string $url): array
    {
        $process = new Process(
            [
                self::binary(),
                '-J',
                '--no-playlist',
                '--no-warnings',
                '--socket-timeout', '30',
                $url,
            ],
            null,
            null,
            null,
            self::METADATA_TIMEOUT
        );
        $process->run();

        if (! $process->isSuccessful()) {
            $tail = trim(implode("\n", array_slice(explode("\n", $process->getErrorOutput() ?: $process->getOutput()), -3)));

            throw new RuntimeException('yt-dlp metadata failed: '.mb_substr($tail, 0, 500));
        }

        $data = json_decode($process->getOutput(), true);
        if (! is_array($data)) {
            throw new RuntimeException('yt-dlp returned invalid metadata JSON.');
        }

        return self::normalize($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{title:?string, description:?string, tags:array<int,string>,
     *               thumbnail_url:?string, duration_seconds:?int,
     *               chapters:array<int,array{start_time:int,title:string}>,
     *               author_name:?string, width:?int, height:?int,
     *               extractor:?string, webpage_url:?string}
     */
    public static function normalize(array $data): array
    {
        $title = self::text($data['title'] ?? null, 160);
        $description = self::text($data['description'] ?? null, 10000);

        $tags = collect(is_array($data['tags'] ?? null) ? $data['tags'] : [])
            ->map(fn ($tag): string => trim((string) $tag))
            ->filter()
            ->unique(fn (string $tag): bool => mb_strtolower($tag))
            ->take(10)
            ->values()
            ->all();

        $thumbnail = self::text($data['thumbnail'] ?? null, 1000);
        if ($thumbnail === null && is_array($data['thumbnails'] ?? null)) {
            $last = collect($data['thumbnails'])->last();
            $thumbnail = is_array($last) ? self::text($last['url'] ?? null, 1000) : null;
        }

        $width = self::positiveInt($data['width'] ?? null);
        $height = self::positiveInt($data['height'] ?? null);
        if (($width === null || $height === null) && is_array($data['formats'] ?? null)) {
            foreach ($data['formats'] as $format) {
                if (! is_array($format)) {
                    continue;
                }
                $width ??= self::positiveInt($format['width'] ?? null);
                $height ??= self::positiveInt($format['height'] ?? null);
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'tags' => $tags,
            'thumbnail_url' => $thumbnail,
            'duration_seconds' => self::positiveInt($data['duration'] ?? null),
            'chapters' => self::chapters($data['chapters'] ?? null),
            'author_name' => self::text(
                $data['uploader'] ?? $data['channel'] ?? $data['creator'] ?? $data['artist'] ?? $data['uploader_id'] ?? null,
                255
            ),
            'width' => $width,
            'height' => $height,
            'extractor' => self::text($data['extractor_key'] ?? $data['extractor'] ?? null, 120),
            'webpage_url' => self::text($data['webpage_url'] ?? null, 2000),
        ];
    }

    /**
     * @return array<int, array{start_time:int, title:string}>
     */
    private static function chapters(mixed $chapters): array
    {
        if (! is_array($chapters)) {
            return [];
        }

        return collect($chapters)
            ->filter(fn ($chapter): bool => is_array($chapter))
            ->map(function (array $chapter): ?array {
                $start = $chapter['start_time'] ?? $chapter['timestamp'] ?? null;
                $title = trim((string) ($chapter['title'] ?? ''));

                if ($start === null || ! is_numeric($start) || $title === '') {
                    return null;
                }

                return [
                    'start_time' => max(0, (int) round((float) $start)),
                    'title' => mb_substr($title, 0, 160),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (float) $value > 0 ? (int) round((float) $value) : null;
    }
}
