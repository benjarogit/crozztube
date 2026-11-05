<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Api\V1\LiveStreamAccessService;
use App\Support\PlatformFeatureSettings;
use App\Support\VideoChapters;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VideoDetailResource extends VideoCardResource
{
    public function toArray(Request $request): array
    {
        $card = parent::toArray($request);
        $sale = VideoSales::payload($this->resource, $request);
        $chaptersEnabled = PlatformFeatureSettings::videoChaptersEnabled() && (bool) $this->chapters_enabled;

        return [
            ...$card,
            'description' => (string) ($this->description ?? ''),
            'tags' => array_values(array_map('strval', $this->tags ?: [])),
            'category' => $this->relationLoaded('category') && $this->category ? [
                'id' => (int) $this->category->id,
                'slug' => (string) $this->category->slug,
                'name' => (string) $this->category->name,
            ] : null,
            'language' => $this->relationLoaded('language') && $this->language ? [
                'id' => (int) $this->language->id,
                'code' => (string) $this->language->code,
                'name' => (string) $this->language->name,
            ] : null,
            'publication' => [
                'visibility' => (string) $this->visibility,
                'published_at' => optional($this->published_at)->toIso8601String(),
                'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
                'made_for_kids' => (bool) $this->made_for_kids,
            ],
            'commerce' => [
                'paid' => (bool) $sale['is_paid'],
                'has_access' => (bool) $sale['has_access'],
                'purchased' => (bool) $sale['is_purchased'],
                'purchase_available' => (bool) $sale['purchase_available'],
                'price_cents' => (int) $sale['price_cents'],
                'price_label' => $sale['price_label'],
                'total_cents' => (int) $sale['total_cents'],
                'total_label' => $sale['total_label'],
            ],
            'media' => [
                'poster_url' => $this->thumbnail_url,
                'thumbnail_candidates' => $this->thumbnail_candidates,
                'has_captions' => filled($this->captions_path),
                'has_chapters' => $chaptersEnabled && count($this->chapters ?: []) > 0,
                'chapters' => $chaptersEnabled
                    ? VideoChapters::forResource($this->chapters, $this->duration_seconds)
                    : [],
            ],
            'live_replay' => $this->liveReplayPayload($request),
            'viewer' => [
                ...$card['viewer'],
                'can_edit' => $request->user()?->can('update', $this->resource) ?? false,
                'reported' => (bool) ($this->viewer_reported ?? false),
                'can_report' => $request->user() !== null && (int) $request->user()->id !== (int) $this->user_id,
            ],
            'links' => [
                'playback' => route('api.v1.videos.playback', ['video' => $this->slug]),
                'comments' => route('api.v1.videos.comments.index', ['video' => $this->slug]),
                'related' => route('api.v1.videos.related', ['video' => $this->slug]),
            ],
        ];
    }

    private function liveReplayPayload(Request $request): ?array
    {
        if ($this->source_type !== 'live_replay') {
            return null;
        }
        $stream = $this->resource->liveStream;
        if (! $stream || (int) $stream->replay_video_id !== (int) $this->id) {
            return null;
        }
        try {
            app(LiveStreamAccessService::class)->assertViewer($stream, $request->user());
        } catch (HttpException) {
            return null;
        }

        return [
            'slug' => $stream->slug,
            'started_at' => $stream->actual_started_at?->toIso8601String(),
            'ended_at' => $stream->ended_at?->toIso8601String(),
            // The existing Live model does not persist a peak audience metric.
            'peak_viewers_count' => null,
        ];
    }
}
