<?php

namespace App\Support;

use App\Models\Video;
use Illuminate\Support\Facades\DB;

class CompleteVideoProcessing
{
    /** Use current publication intent, even when metadata changed during FFmpeg. */
    public static function save(int $videoId, array $media): void
    {
        DB::transaction(function () use ($videoId, $media): void {
            $video = Video::query()->lockForUpdate()->findOrFail($videoId);
            $private = $video->publish_as_draft || $video->scheduled_at?->isFuture()
                || ($video->approval_status ?: 'approved') !== 'approved';
            $isShort = Video::isShortContent($video->source_type, $media['duration_seconds'] ?? $video->duration_seconds, $video->is_short, (bool) $video->sale_enabled);
            $video->update([
                ...$media,
                'is_short' => $isShort,
                ...($isShort ? ['monetization_enabled' => false, 'chapters_enabled' => false, 'chapters_source' => null, 'chapters' => null] : []),
                'status' => $video->publish_as_draft ? 'draft' : 'ready',
                'visibility' => $private ? 'private' : ($video->requested_visibility ?: 'public'),
                'published_at' => $private ? null : ($video->published_at ?? now()),
                'processing_progress' => 100,
                'processing_stage' => 'Processing complete',
            ]);
        });
    }
}
