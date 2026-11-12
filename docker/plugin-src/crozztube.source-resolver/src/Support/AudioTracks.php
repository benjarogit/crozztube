<?php

namespace CrzSourceResolver\Support;

final class AudioTracks
{
    /** Explicit extractor evidence only: a regional default can be a dub. */
    public static function originalLanguage(array $formats): ?string
    {
        foreach ($formats as $format) {
            if (self::isOriginal($format) && is_string($format['language'] ?? null) && $format['language'] !== '') return $format['language'];
        }

        return null;
    }

    public static function isOriginal(array $format): bool
    {
        return ($format['language_preference'] ?? 0) >= 10
            || ($format['audio_is_original'] ?? false) === true
            || preg_match('/\boriginal\b/i', (string) ($format['format_note'] ?? '')) === 1;
    }

    public static function preference(array $format, ?string $originalLanguage = null): int
    {
        if (self::descriptive($format)) return -100;
        if (self::isOriginal($format) || ($originalLanguage && ($format['language'] ?? '') === $originalLanguage)) return 100;

        return min(9, (int) ($format['language_preference'] ?? 0))
            + (preg_match('/\bdefault\b/i', (string) ($format['format_note'] ?? '')) ? 5 : 0);
    }

    /** One playable HLS rendition per language/role, retaining every offered language. */
    public static function hls(array $formats): array
    {
        $originalLanguage = self::originalLanguage($formats);
        $candidates = array_values(array_filter($formats, static fn (array $format): bool =>
            str_starts_with((string) ($format['protocol'] ?? ''), 'm3u8')
            && ($format['vcodec'] ?? '') === 'none' && ! ($format['has_drm'] ?? false)
            && ! empty($format['url']) && ($format['acodec'] ?? '') !== 'none'));
        usort($candidates, static fn (array $a, array $b): int =>
            [self::preference($b, $originalLanguage), (float) ($b['abr'] ?? $b['tbr'] ?? 0), (int) preg_match('/high/i', $b['format_note'] ?? '')]
            <=> [self::preference($a, $originalLanguage), (float) ($a['abr'] ?? $a['tbr'] ?? 0), (int) preg_match('/high/i', $a['format_note'] ?? '')]);
        $tracks = [];
        $seen = [];
        foreach ($candidates as $format) {
            $language = (string) ($format['language'] ?? 'und');
            if (! preg_match('/^[a-zA-Z0-9_-]{2,35}$/D', $language)) $language = 'und';
            $original = self::isOriginal($format) || ($originalLanguage && $language === $originalLanguage && ! self::descriptive($format));
            $description = self::descriptive($format);
            $key = strtolower($language).':'.(int) $description;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $label = class_exists(\Locale::class) && $language !== 'und' ? \Locale::getDisplayName($language, 'en') : $language;
            if ($language === 'und') $label = 'Audio';
            $label .= $original ? ' (Original)' : ($description ? ' (Audio description)' : '');
            $tracks[] = [
                'language' => $language, 'label' => $label, 'original' => (bool) $original,
                'default' => $tracks === [], 'descriptive' => $description,
                'codecs' => $format['acodec'] ?? 'mp4a.40.2', 'url' => $format['url'],
            ];
        }

        return $tracks;
    }

    public static function master(array $variants, array $tracks, string $playlistBase, string $suffix = ''): string
    {
        $quote = static fn (string $text): string => str_replace(['"', "\r", "\n"], ['', '', ''], $text);
        $lines = ['#EXTM3U', '#EXT-X-VERSION:6'];
        foreach ($tracks as $index => $track) {
            $lines[] = sprintf('#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="audio",NAME="%s",LANGUAGE="%s",DEFAULT=%s,AUTOSELECT=%s%s,URI="%s"',
                $quote($track['label']), $quote($track['language']), $index === 0 ? 'YES' : 'NO',
                $index === 0 ? 'YES' : 'NO',
                ! empty($track['descriptive']) ? ',CHARACTERISTICS="public.accessibility.describes-video"' : '',
                $playlistBase.urlencode($track['url']).$suffix);
        }
        $audioCodecs = array_unique(array_map(static fn ($track): string => $quote($track['codecs'] ?: 'mp4a.40.2'), $tracks));
        foreach ($variants as $variant) {
            if (! is_array($variant) || empty($variant['url'])) continue;
            $resolution = ($variant['width'] ?? 0) > 0 && ($variant['height'] ?? 0) > 0
                ? sprintf(',RESOLUTION=%dx%d', $variant['width'], $variant['height']) : '';
            $codecs = $quote($variant['codecs'] ?: 'avc1.640029');
            $lines[] = sprintf('#EXT-X-STREAM-INF:BANDWIDTH=%d%s,CODECS="%s",AUDIO="audio"',
                (int) ($variant['bandwidth'] ?? 0) ?: 2000000, $resolution, implode(',', array_unique([$codecs, ...$audioCodecs])));
            $lines[] = $playlistBase.urlencode($variant['url']).$suffix;
        }

        return implode("\n", $lines)."\n";
    }

    private static function descriptive(array $format): bool
    {
        return ($format['language_preference'] ?? 0) <= -10
            || preg_match('/descriptive|audio description/i', (string) ($format['format_note'] ?? '')) === 1;
    }
}
