<?php

namespace App\Support;

use App\Models\Video;
use App\Support\Thumbnails\ThumbnailService;

final class ImportMetadataBackfill
{
    public static function apply(Video $video, array $metadata): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () use ($video, $metadata): void {
            $current = Video::query()->lockForUpdate()->find($video->id);
            if (! $current) return;
            self::applyLocked($current, $metadata);
            $video->setRawAttributes($current->getAttributes(), true);
            $video->unsetRelation('thumbnailMirrors');
        });
    }

    private static function applyLocked(Video $video, array $metadata): void
    {
        $overrides = $video->metadata_overrides ?: [];
        $updates = [];
        foreach (['duration_seconds', 'video_width', 'video_height'] as $field) {
            $key = ['video_width' => 'width', 'video_height' => 'height'][$field] ?? $field;
            if ((int) ($metadata[$key] ?? 0) > 0 && (int) $video->$field <= 0) $updates[$field] = (int) $metadata[$key];
        }
        foreach (['title', 'description', 'tags'] as $field) {
            $placeholder = $field === 'title' && (in_array($video->title, ['Imported video', 'YouTube video', 'Video URL'], true) || preg_match('/^(YouTube|Vimeo|Dailymotion|Twitch) video [A-Za-z0-9_-]+$/', $video->title));
            if (! in_array($field, $overrides, true) && (blank($video->$field) || $placeholder) && filled($metadata[$field] ?? null)) $updates[$field] = $metadata[$field];
        }
        $short = ShortVideos::qualifies($updates['duration_seconds'] ?? $video->duration_seconds,
            $updates['video_width'] ?? $video->video_width, $updates['video_height'] ?? $video->video_height, $video->source_type);
        $updates['is_short'] = $short;
        if (! $short && $video->chapters_enabled && empty($video->chapters)
            && ! array_intersect(['chapters', 'chapters_enabled'], $overrides) && ! empty($metadata['chapters'])) {
            try {
                $updates += VideoChapters::attributes(['chapters_enabled' => true, 'chapters_source' => 'timestamps', 'chapters' => $metadata['chapters']],
                    $updates['duration_seconds'] ?? $video->duration_seconds);
            } catch (\Illuminate\Validation\ValidationException) {
                // Invalid upstream chapters must never break a working playback refresh.
            }
        }
        $video->forceFill($updates)->save();
        if (! $video->thumbnail_is_custom && filled($metadata['thumbnail_url'] ?? null)) {
            try { app(ThumbnailService::class)->ensureSource($video, $metadata['thumbnail_url']); }
            catch (\Throwable) { logger()->warning('Thumbnail backfill deferred', ['video_id' => $video->id]); }
        }
    }
}
