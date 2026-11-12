<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class VideoChapters
{
    public const MAX_CHAPTERS = 100;

    public const MIN_CHAPTER_SECONDS = 10;

    public static function rules(): array
    {
        return [
            'chapters_enabled' => ['nullable', 'boolean'],
            'chapters_source' => ['nullable', 'string', Rule::in(['cue', 'timestamps', 'generated', 'manual', 'provider'])],
            'chapters' => ['nullable', 'array', 'max:'.self::MAX_CHAPTERS],
            'chapters.*.start_time' => ['required_with:chapters', 'integer', 'min:0'],
            'chapters.*.title' => ['required_with:chapters', 'string', 'max:160'],
        ];
    }

    public static function attributes(array $data, ?int $durationSeconds = null): array
    {
        if (! PlatformFeatureSettings::videoChaptersEnabled()) {
            return [
                'chapters_enabled' => false,
                'chapters_source' => null,
                'chapters' => null,
            ];
        }

        $enabled = array_key_exists('chapters_enabled', $data) ? (bool) $data['chapters_enabled'] : true;
        $chapters = array_values($data['chapters'] ?? []);

        if (! $enabled) {
            return [
                'chapters_enabled' => false,
                'chapters_source' => null,
                'chapters' => null,
            ];
        }

        if ($chapters === []) {
            return [
                'chapters_enabled' => true,
                'chapters_source' => null,
                'chapters' => null,
            ];
        }

        if (count($chapters) < 2) {
            throw ValidationException::withMessages([
                'chapters' => 'Add at least two chapters or turn off Upload with chapters.',
            ]);
        }

        $normalized = [];
        $source = (string) ($data['chapters_source'] ?? 'manual');
        $minimum = $source === 'provider' ? 1 : self::MIN_CHAPTER_SECONDS;
        foreach ($chapters as $index => $chapter) {
            $startTime = (int) ($chapter['start_time'] ?? 0);
            $title = trim((string) ($chapter['title'] ?? ''));
            $previous = $normalized[$index - 1]['start_time'] ?? null;

            if ($index === 0 && $startTime !== 0 && $source !== 'provider') {
                throw ValidationException::withMessages([
                    'chapters.0.start_time' => 'The first chapter must start at 0:00.',
                ]);
            }
            if ($previous !== null && $startTime - $previous < $minimum) {
                throw ValidationException::withMessages([
                    "chapters.{$index}.start_time" => "Chapter timestamps must be in order and at least {$minimum} seconds apart.",
                ]);
            }
            if ($durationSeconds && $startTime >= $durationSeconds) {
                throw ValidationException::withMessages([
                    "chapters.{$index}.start_time" => 'Chapter timestamps must be inside the video duration.',
                ]);
            }

            $normalized[] = ['start_time' => $startTime, 'title' => $title];
        }

        return [
            'chapters_enabled' => true,
            'chapters_source' => in_array($source, ['cue', 'timestamps', 'generated', 'manual', 'provider'], true) ? $source : 'manual',
            'chapters' => $normalized,
        ];
    }

    /** Native chapters take priority over conservative description timestamp lists. */
    public static function discover(mixed $native, ?string $description, ?int $duration = null, ?string $source = null): array
    {
        $chapters = [];
        foreach (is_array($native) ? $native : [] as $chapter) {
            if (! is_array($chapter)) continue;
            $start = $chapter['start_time'] ?? $chapter['timestamp'] ?? $chapter['time'] ?? null;
            $title = is_string($chapter['title'] ?? null) ? trim(strip_tags($chapter['title'])) : '';
            if (! is_numeric($start) || ! is_finite((float) $start) || (float) $start < 0 || $title === '') continue;
            $start = (int) floor((float) $start);
            if ($duration && $start >= $duration) continue;
            $chapters[] = ['start_time' => $start, 'title' => mb_substr($title, 0, 160)];
        }
        $chapters = collect($chapters)->sortBy('start_time')->unique('start_time')->take(self::MAX_CHAPTERS)->values()->all();
        if (count($chapters) >= 2) return ['chapters' => $chapters, 'chapters_source' => $source === 'timestamps' ? 'timestamps' : 'provider'];

        // Only standalone leading/trailing timestamps form a chapter list.
        // URLs, durations in prose and out-of-order lists must not become chapters.
        $candidates = [];
        foreach (preg_split('/\R/u', $description ?? '') ?: [] as $line) {
            $line = trim($line);
            $line = preg_replace('~\[(\d{1,4}:\d{2}(?::\d{2})?)\]\(https?://[^\s)]+\)~u', '$1', $line);
            $timestamp = '[\[(]?(\d{1,4}:\d{2}(?::\d{2})?)[)\]]?';
            $title = $stamp = null;
            if (preg_match('/^(?:[-*•]\s*)?'.$timestamp.'\s*(?:[-–—|:]\s*)?(.+)$/u', $line, $match)) {
                [$stamp, $title] = [$match[1], $match[2]];
            } elseif (preg_match('/^(.+?)\s+(?:[-–—|]\s*)?'.$timestamp.'$/u', $line, $match)) {
                [$title, $stamp] = [$match[1], $match[2]];
            }
            if ($stamp === null || str_contains((string) $title, '://')) continue;
            $parts = array_map('intval', explode(':', $stamp));
            if (end($parts) > 59 || (count($parts) === 3 && $parts[1] > 59)) continue;
            $start = array_reduce($parts, static fn (int $total, int $part): int => $total * 60 + $part, 0);
            $title = preg_replace('/^[\s\-–—|:]+|[\s\-–—|:]+$/u', '', (string) $title);
            if ($title !== '') $candidates[] = ['start_time' => $start, 'title' => mb_substr($title, 0, 160)];
        }
        $chapters = [];
        foreach ($candidates as $chapter) {
            if ($chapters === [] && $chapter['start_time'] !== 0) continue;
            if ($chapters !== [] && $chapter['start_time'] === end($chapters)['start_time']) continue;
            if (($chapters !== [] && $chapter['start_time'] - end($chapters)['start_time'] < self::MIN_CHAPTER_SECONDS)
                || ($duration && $chapter['start_time'] >= $duration)) break;
            $chapters[] = $chapter;
            if (count($chapters) >= self::MAX_CHAPTERS) break;
        }

        return count($chapters) >= 2 ? ['chapters' => $chapters, 'chapters_source' => 'timestamps'] : ['chapters' => [], 'chapters_source' => null];
    }

    public static function forResource(?array $chapters, ?int $durationSeconds = null): array
    {
        $duration = (int) $durationSeconds;

        return collect($chapters ?? [])
            ->map(fn ($chapter): array => [
                'start_time' => max(0, (int) ($chapter['start_time'] ?? 0)),
                'title' => trim((string) ($chapter['title'] ?? '')),
            ])
            ->filter(fn (array $chapter): bool => $chapter['title'] !== '' && ($duration <= 0 || $chapter['start_time'] < $duration))
            ->sortBy('start_time')
            ->unique('start_time')
            ->take(self::MAX_CHAPTERS)
            ->values()
            ->all();
    }
}
