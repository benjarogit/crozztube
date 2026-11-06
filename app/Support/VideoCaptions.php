<?php

namespace App\Support;

use App\Models\Video;
use App\Support\Thumbnails\RemoteImages;

/** Import only timed text. Keep bounded, validated copies so signed URLs can expire. */
final class VideoCaptions
{
    public static function candidates(array $metadata): array
    {
        $tracks = [];
        foreach (['subtitles' => false, 'automatic_captions' => true] as $key => $automatic) {
            foreach (is_array($metadata[$key] ?? null) ? $metadata[$key] : [] as $language => $formats) {
                if (! is_string($language) || ! preg_match('/^[a-zA-Z0-9_-]{2,35}$/', $language) || ! is_array($formats)) continue;
                if (isset($tracks[$language])) continue;
                foreach (['vtt', 'srt'] as $extension) {
                    foreach ($formats as $format) {
                        if (! is_array($format) || ($format['ext'] ?? '') !== $extension || ! is_string($format['url'] ?? null)
                            || ! RemoteImages::urlAllowed($format['url'] ?? null)) continue;
                        $tracks[$language] = [
                            'language' => $language, 'label' => mb_substr((string) ($format['name'] ?? $language), 0, 80),
                            'automatic' => $automatic, 'format' => $extension, 'url' => $format['url'],
                        ];
                        break 2;
                    }
                }
            }
        }
        $preferred = [$metadata['language'] ?? '', config('app.locale', 'en'), 'en', 'de'];
        $tracks = array_values($tracks);
        usort($tracks, static function (array $a, array $b) use ($preferred): int {
            $rank = static fn (array $track): int => ($track['automatic'] ? 100 : 0)
                + (($index = array_search(preg_replace('/[-_].*/', '', $track['language']), $preferred, true)) !== false ? $index : 50);

            return $rank($a) <=> $rank($b);
        });

        return array_slice($tracks, 0, 16);
    }

    public static function importMetadata(array $candidates): ?array
    {
        $tracks = [];
        $seen = [];
        $bytes = 0;
        $deadline = microtime(true) + 15;
        $candidates = array_values(array_filter($candidates, 'is_array'));
        usort($candidates, static fn ($a, $b): int => (int) ($a['automatic'] ?? false) <=> (int) ($b['automatic'] ?? false));
        foreach (array_slice($candidates, 0, 32) as $candidate) {
            if (microtime(true) >= $deadline || $bytes >= 4 * 1024 * 1024) break;
            if (! is_array($candidate) || ! RemoteImages::urlAllowed($candidate['url'] ?? null)) continue;
            $language = (string) ($candidate['language'] ?? 'und');
            if (! preg_match('/^[a-zA-Z0-9_-]{2,35}$/', $language) || isset($seen[strtolower($language)])) continue;
            if (count($tracks) >= 16) break;
            try {
                $response = app(RemoteImages::class)->request($candidate['url'], 'GET', $deadline,
                    min(1024 * 1024, 4 * 1024 * 1024 - $bytes));
                if ($response['code'] !== 200 || $response['error'] !== '') continue;
                $text = self::webvtt($response['body'], $candidate['format'] ?? 'vtt');
                if ($text === null || $bytes + strlen($text) > 4 * 1024 * 1024) continue;
                $seen[strtolower($language)] = true;
                $bytes += strlen($text);
                $tracks[] = ['language' => $language, 'label' => mb_substr((string) ($candidate['label'] ?? $language), 0, 80),
                    'automatic' => (bool) ($candidate['automatic'] ?? false), 'content' => $text];
            } catch (\Throwable) {
                // An unavailable caption must not prevent the video import.
            }
        }

        return $tracks !== [] ? ['imported_tracks' => $tracks] : null;
    }

    public static function stored(Video $video): array
    {
        return $video->source_type === 'import' && ! $video->captions_path
            ? array_values(array_filter((array) ($video->caption_metadata['imported_tracks'] ?? []),
                static fn ($track): bool => is_array($track) && is_string($track['content'] ?? null))) : [];
    }

    public static function forResource(Video $video): array
    {
        if ($video->captions_path) {
            $format = strtolower(pathinfo($video->captions_path, PATHINFO_EXTENSION));

            return [['id' => 'default', 'url' => VideoSales::captionsUrl($video), 'format' => $format === 'vtt' ? 'webvtt' : $format,
                'language' => $video->caption_metadata['language'] ?? $video->language?->code ?? 'und',
                'label' => $video->caption_metadata['label'] ?? $video->language?->name ?? 'Captions',
                'default' => (bool) ($video->caption_metadata['default'] ?? true), 'automatic' => false]];
        }

        return array_map(static fn (array $track, int $index): array => [
            'id' => 'imported-'.$index, 'url' => VideoSales::captionsUrl($video, null, $index), 'format' => 'webvtt',
            'language' => $track['language'], 'label' => $track['label'], 'automatic' => (bool) ($track['automatic'] ?? false),
            'default' => $index === 0,
        ], self::stored($video), array_keys(self::stored($video)));
    }

    public static function webvtt(string $text, string $format = 'vtt'): ?string
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', str_replace(["\r\n", "\r"], "\n", $text));
        if (! mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0") || strlen($text) > 1024 * 1024) return null;
        if ($format === 'srt') {
            $text = "WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
        }
        if (strlen($text) > 1024 * 1024) return null;
        if (! preg_match('/^WEBVTT(?:\s|$)/', ltrim($text))
            || ! preg_match('/(?:\d{2}:)?\d{2}:\d{2}\.\d{3}\s+-->\s+(?:\d{2}:)?\d{2}:\d{2}\.\d{3}/', $text)) return null;

        return $text;
    }

    public static function transcript(Video $video, int $index = 0): ?string
    {
        $track = self::stored($video)[$index] ?? null;
        if (! $track) return null;
        $lines = [];
        foreach (preg_split('/\n\s*\n/', $track['content']) as $cue) {
            $parts = explode("\n", $cue);
            $timing = null;
            foreach ($parts as $index => $part) {
                if (str_contains($part, '-->')) { $timing = $index; break; }
            }
            if ($timing === null) continue;
            $line = trim(html_entity_decode(strip_tags(implode(' ', array_slice($parts, $timing + 1))), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($line !== '' && end($lines) !== $line) $lines[] = $line;
        }

        return mb_substr(implode("\n", $lines), 0, 100000) ?: null;
    }
}
