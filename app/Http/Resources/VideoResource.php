<?php

namespace App\Http\Resources;

use App\Support\MediaSourceMetadata;
use App\Support\PlatformFeatureSettings;
use App\Support\VideoChapters;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $sale = VideoSales::payload($this->resource, $request);
        $canPlay = (bool) $sale['has_access'];
        $videoUrl = $canPlay
            ? ($sale['is_paid'] && $this->source_type !== 'import'
                ? VideoSales::streamUrl($this->resource, $this->status === 'ready' ? 'high' : null)
                : $this->video_url)
            : null;
        $chaptersEnabled = PlatformFeatureSettings::videoChaptersEnabled() && (bool) $this->chapters_enabled;
        $videoSources = $this->videoSources($canPlay);
        $usesResolver = $this->usesSourceResolver();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'tags' => $this->tags ?: [],
            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'language_id' => $this->language_id,
            'language' => $this->whenLoaded('language', fn () => $this->language?->name),
            'language_code' => $this->whenLoaded('language', fn () => $this->language?->code),
            'playlist_title' => $this->playlist_title,
            'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
            'made_for_kids' => (bool) $this->made_for_kids,
            'monetization_enabled' => (bool) $this->monetization_enabled,
            'sale' => $sale,
            'has_captions' => (bool) $this->captions_path || \App\Support\VideoCaptions::stored($this->resource) !== [],
            'captions_url' => $canPlay ? VideoSales::captionsUrl($this->resource) : null,
            'caption_tracks' => $canPlay ? \App\Support\VideoCaptions::forResource($this->resource) : [],
            'transcript_url' => $canPlay && \App\Support\VideoCaptions::stored($this->resource) !== []
                ? VideoSales::captionsUrl($this->resource, null, 0, true) : null,
            'chapters_enabled' => $chaptersEnabled,
            'chapters_source' => $chaptersEnabled ? $this->chapters_source : null,
            'chapters' => $chaptersEnabled ? VideoChapters::forResource($this->chapters, $this->duration_seconds) : [],
            'visibility' => $this->visibility,
            'requested_visibility' => $this->requested_visibility,
            'status' => $this->status,
            'approval_status' => $this->approval_status ?: 'approved',
            'processing_progress' => $this->processing_progress,
            'processing_stage' => $this->processing_stage,
            'renditions' => collect($this->renditions ?: [])->map(fn (array $rendition): array => [
                'quality' => $rendition['quality'] ?? null,
                'width' => $rendition['width'] ?? null,
                'height' => $this->dimensionsFor($rendition['quality'] ?? null)['height'],
                'video_bitrate' => $rendition['video_bitrate'] ?? null,
                'url' => $canPlay && isset($rendition['quality']) ? VideoSales::streamUrl($this->resource, $rendition['quality']) : null,
                'type' => $this->mimeTypeForPath($rendition['path'] ?? null),
            ])->values(),
            'video_url' => $videoUrl,
            'video_mime_type' => $this->mimeTypeForPath($this->activeVideoPath()),
            'video_sources' => $videoSources,
            'thumbnail_candidates' => $this->thumbnail_candidates,
            'video_width' => $this->video_width, 'video_height' => $this->video_height,
            'thumbnail_url' => VideoSales::thumbnailUrl($this->resource),
            'source_type' => $this->source_type ?: 'upload',
            'live_stream_url' => $this->whenLoaded('liveStream', fn () => $this->liveStream ? '/live/'.$this->liveStream->slug : null),
            'import_provider' => $this->import_provider,
            'import_url' => $canPlay && $this->source_type === 'import' ? $this->import_url : null,
            'embed_url' => $canPlay && ! $usesResolver && $videoSources === [] ? $this->externalEmbedUrl($request) : null,
            'playback_pending' => $canPlay && $usesResolver && $videoSources === [],
            'duration_seconds' => $this->duration_seconds,
            'is_short' => $this->isShort(),
            'views_count' => $this->views_count,
            'likes_count' => $this->likes_count,
            'comments_count' => $this->comments_count,
            'monetization_earnings' => $this->when(isset($this->monetization_earnings), $this->monetization_earnings),
            'progress' => $this->when(isset($this->progress), (int) $this->progress),
            'current_time' => $this->when(isset($this->current_time), (int) $this->current_time),
            'progress_updated_at' => $this->when(isset($this->progress_updated_at), $this->progress_updated_at),
            'published_at' => optional($this->published_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'channel' => new ChannelResource($this->whenLoaded('channel')),
            'user' => new UserResource($this->whenLoaded('user'), false),
            'is_liked' => $this->when(isset($this->is_liked), (bool) $this->is_liked),
            'is_watch_later' => $this->when(isset($this->is_watch_later), (bool) $this->is_watch_later),
            'reported' => $this->when(isset($this->reported), (bool) $this->reported),
            'can_report' => $this->when(isset($this->can_report), (bool) $this->can_report),
            'boost' => $this->when(isset($this->boost_campaign), $this->boost_campaign),
        ];
    }

    private function videoSources(bool $canPlay): array
    {
        if (! $canPlay) {
            return [];
        }

        // A provider embed is an explicit Mirror choice. While the resolver is
        // preparing/renewing native playback, never silently switch adapters.
        if ($this->usesSourceResolver()) {
            return \CrzSourceResolver\Support\VideoSources::forVideo($this->resource);
        }

        if ($this->source_type === 'import' && class_exists(\CrzSourceResolver\Support\VideoSources::class)) {
            $resolverSources = \CrzSourceResolver\Support\VideoSources::forVideo($this->resource);
            if (! empty($resolverSources)) {
                return $resolverSources;
            }
        }

        if ($externalSource = $this->externalPlayerSource()) {
            return [$externalSource];
        }

        if ($this->externalEmbedUrl(request())) {
            return [];
        }

        // A direct URL is already the media source, not a locally uploaded file.
        // In particular, HLS must load its playlist at the canonical origin so
        // relative segments resolve there without a cross-origin redirect.
        if ($this->source_type === 'import' && $this->import_provider === 'direct') {
            return $this->video_url ? [[
                'src' => $this->video_url,
                'type' => $this->mimeTypeForPath($this->activeVideoPath()),
                'quality' => 'original',
            ]] : [];
        }

        $sources = collect($this->renditions ?: [])
            ->filter(fn (array $rendition): bool => isset($rendition['quality'], $rendition['path']))
            ->sortBy(fn (array $rendition): int => match ($rendition['quality']) {
                'low' => 0,
                'medium' => 1,
                'high' => 2,
                'original' => 3,
                default => 4,
            })
            ->map(fn (array $rendition): array => [
                'src' => VideoSales::streamUrl($this->resource, $rendition['quality']),
                'type' => $this->mimeTypeForPath($rendition['path']),
                'quality' => $rendition['quality'],
                'width' => $rendition['width'] ?? $this->dimensionsFor($rendition['quality'])['width'],
                'height' => $this->dimensionsFor($rendition['quality'])['height'],
            ])
            ->values()
            ->all();

        if (! $sources && $this->video_url) {
            $sources[] = [
                'src' => VideoSales::streamUrl($this->resource, $this->status === 'ready' ? 'high' : null),
                'type' => $this->mimeTypeForPath($this->activeVideoPath()),
                'quality' => 'original',
                'width' => $this->dimensionsFor('original')['width'],
                'height' => $this->dimensionsFor('original')['height'],
            ];
        }

        return $sources;
    }

    private function usesSourceResolver(): bool
    {
        return $this->source_type === 'import'
            && $this->import_provider !== 'direct'
            && class_exists(\CrzSourceResolver\Support\VideoSources::class)
            && \CrzSourceResolver\Resolver::available();
    }

    private function dimensionsFor(?string $quality): array
    {
        return match ($quality) {
            'low' => ['width' => 426, 'height' => 240],
            'medium' => ['width' => 854, 'height' => 480],
            'high' => ['width' => 1280, 'height' => 720],
            'original' => ['width' => 1920, 'height' => 1080],
            default => ['width' => null, 'height' => null],
        };
    }

    private function activeVideoPath(): ?string
    {
        if ($this->source_type === 'import' && $this->import_provider === 'direct') {
            return $this->import_url;
        }

        if ($this->status === 'ready') {
            $high = collect($this->renditions ?: [])->firstWhere('quality', 'high');

            return $high['path'] ?? $this->video_path;
        }

        return $this->video_path;
    }

    private function mimeTypeForPath(?string $path): string
    {
        return match (strtolower(pathinfo((string) parse_url((string) $path, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'mkv' => 'video/x-matroska',
            'ogv', 'ogg' => 'video/ogg',
            'avi' => 'video/x-msvideo',
            'mpeg', 'mpg' => 'video/mpeg',
            '3gp', '3gpp' => 'video/3gpp',
            'm3u8' => 'application/vnd.apple.mpegurl',
            'mpd' => 'application/dash+xml',
            default => 'video/mp4',
        };
    }

    private function externalEmbedUrl(Request $request): ?string
    {
        if ($this->source_type !== 'import') {
            return null;
        }

        $provider = MediaSourceMetadata::originProvider($this->resource);
        $id = MediaSourceMetadata::providerIdForVideo($this->resource, $provider);
        $parent = $request->getHost() ?: parse_url((string) config('app.url'), PHP_URL_HOST);

        return ! in_array($provider, ['youtube', 'vimeo'], true)
            ? MediaSourceMetadata::embedUrl($provider, $id, (string) $parent, (string) $this->import_url)
            : null;
    }

    private function externalPlayerSource(): ?array
    {
        if ($this->source_type !== 'import') {
            return null;
        }

        $provider = MediaSourceMetadata::originProvider($this->resource);
        $id = MediaSourceMetadata::providerIdForVideo($this->resource, $provider);

        return match ($provider) {
            'youtube' => $id ? ['src' => 'youtube/'.$id, 'type' => 'video/youtube', 'quality' => 'provider'] : null,
            'vimeo' => $id ? ['src' => 'vimeo/'.$id, 'type' => 'video/vimeo', 'quality' => 'provider'] : null,
            default => null,
        };
    }
}
