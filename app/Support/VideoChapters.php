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
            'chapters_source' => ['nullable', 'string', Rule::in(['cue', 'timestamps', 'generated', 'manual'])],
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
        foreach ($chapters as $index => $chapter) {
            $startTime = (int) ($chapter['start_time'] ?? 0);
            $title = trim((string) ($chapter['title'] ?? ''));
            $previous = $normalized[$index - 1]['start_time'] ?? null;

            if ($index === 0 && $startTime !== 0) {
                throw ValidationException::withMessages([
                    'chapters.0.start_time' => 'The first chapter must start at 0:00.',
                ]);
            }
            if ($previous !== null && $startTime - $previous < self::MIN_CHAPTER_SECONDS) {
                throw ValidationException::withMessages([
                    "chapters.{$index}.start_time" => 'Chapter timestamps must be in order and at least 10 seconds apart.',
                ]);
            }
            if ($durationSeconds && $startTime >= $durationSeconds) {
                throw ValidationException::withMessages([
                    "chapters.{$index}.start_time" => 'Chapter timestamps must be inside the video duration.',
                ]);
            }

            $normalized[] = ['start_time' => $startTime, 'title' => $title];
        }

        $source = (string) ($data['chapters_source'] ?? 'manual');

        return [
            'chapters_enabled' => true,
            'chapters_source' => in_array($source, ['cue', 'timestamps', 'generated', 'manual'], true) ? $source : 'manual',
            'chapters' => $normalized,
        ];
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
