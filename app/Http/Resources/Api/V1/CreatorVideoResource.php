<?php

namespace App\Http\Resources\Api\V1;

use App\Support\PlatformFeatureSettings;
use App\Support\VideoChapters;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CreatorVideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $native = $this->source_type !== 'import';
        $caption = $this->caption_metadata ?? [];

        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'title' => $this->title,
            'description' => $this->description ?? '',
            'playlist_title' => $this->playlist_title,
            'tags' => $this->tags ?? [],
            'category_id' => $this->category_id,
            'language_id' => $this->language_id,
            'content_type' => $this->source_type === 'live_replay' ? 'replay' : ($this->isShort() ? 'short' : 'video'),
            'source_type' => $this->source_type ?: 'upload',
            'import_provider' => $this->import_provider,
            'thumbnail_candidates' => $this->thumbnail_candidates,
            'video_width' => $this->video_width, 'video_height' => $this->video_height,
            'thumbnail_url' => preg_match('#^(https?:)?//#', $this->thumbnail_path ?? '')
                ? $this->thumbnail_url
                : VideoSales::thumbnailUrl($this->resource),
            'status' => $this->status,
            'approval_status' => $this->approval_status,
            'visibility' => $this->visibility,
            'requested_visibility' => $this->requested_visibility ?: $this->visibility,
            'publish_as_draft' => (bool) $this->publish_as_draft || $this->status === 'draft',
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'views_count' => (int) $this->views_count,
            'likes_count' => (int) $this->likes_count,
            'comments_count' => (int) $this->comments_count,
            'made_for_kids' => (bool) $this->made_for_kids,
            'monetization_enabled' => (bool) $this->monetization_enabled,
            'processing' => [
                'progress_percent' => (int) $this->processing_progress,
                'stage' => $this->status === 'failed' ? 'Processing failed. Retry or delete this upload.' : ($this->processing_stage ?: $this->status),
                'can_retry' => $native && $this->status === 'failed',
            ],
            'chapters_enabled' => (bool) $this->chapters_enabled,
            'chapters' => VideoChapters::forResource($this->chapters, $this->duration_seconds),
            'captions' => $this->captions_path ? [[
                'id' => 'default', 'label' => $caption['label'] ?? 'Captions',
                'language' => $caption['language'] ?? $this->language?->code ?? 'und',
                'default' => (bool) ($caption['default'] ?? false),
                'url' => VideoSales::captionsUrl($this->resource),
            ]] : [],
            'capabilities' => [
                'captions' => $native, 'chapters' => $native && ! $this->isShort() && PlatformFeatureSettings::videoChaptersEnabled(),
                'monetization' => $native && ! $this->isShort() && ! VideoSales::isPaid($this->resource),
                'comments_settings' => false, 'midroll_cues' => false,
            ],
            'share_url' => url('/'.($this->isShort() ? 'shorts/' : 'watch/').$this->slug),
        ];
    }
}
