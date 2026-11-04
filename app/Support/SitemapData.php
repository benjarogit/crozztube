<?php

namespace App\Support;

use App\Models\Channel;
use App\Models\LiveStream;
use App\Models\Playlist;
use App\Models\Video;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class SitemapData
{
    public function inventory(): array
    {
        $inventory = [];
        foreach (SeoSettings::SECTIONS as $section) {
            $count = $section === 'pages' ? count($this->staticPages()) : $this->queryCount($section);
            $lastModified = $section === 'pages' ? SeoSettings::updatedAt() : $this->queryLastModified($section);
            $inventory[$section] = [
                'section' => $section,
                'count' => $count,
                'last_modified' => $lastModified?->toAtomString(),
            ];
        }

        return $inventory;
    }

    public function summaries(array $settings): array
    {
        $summaries = [];
        $perFile = (int) data_get($settings, 'sitemap.urlsPerFile', 10000);

        foreach ($this->inventory() as $section => $inventory) {
            if (! $this->enabled($settings, $section)) continue;

            $count = $inventory['count'];
            if ($count === 0) continue;

            $summaries[$section] = [
                'section' => $section,
                'count' => $count,
                'files' => max(1, (int) ceil($count / $perFile)),
                'last_modified' => $inventory['last_modified'],
            ];
        }

        return $summaries;
    }

    public function entries(string $section, int $page, array $settings): array
    {
        if (! $this->enabled($settings, $section)) return [];
        if ($section === 'pages') return $page === 1 ? $this->staticPages() : [];

        $perFile = (int) data_get($settings, 'sitemap.urlsPerFile', 10000);
        $query = $this->query($section);
        if (! $query) return [];

        return $query->orderBy('id')->forPage($page, $perFile)->get()->map(function ($model) use ($section): array {
            return match ($section) {
                'videos' => $this->videoEntry($model, false),
                'shorts' => $this->videoEntry($model, true),
                'channels' => [
                    'path' => '/channel/'.rawurlencode($model->slug),
                    'last_modified' => $model->updated_at,
                ],
                'playlists' => [
                    'path' => '/playlists?id='.$model->id,
                    'last_modified' => $model->updated_at,
                ],
                'live' => [
                    'path' => '/live/'.rawurlencode($model->slug),
                    'last_modified' => $model->updated_at,
                ],
            };
        })->all();
    }

    public function enabled(array $settings, string $section): bool
    {
        return in_array($section, SeoSettings::SECTIONS, true)
            && (bool) data_get($settings, 'indexing.enabled', true)
            && (bool) data_get($settings, 'indexing.include.'.$section, true)
            && (bool) data_get($settings, 'sitemap.enabled', true)
            && (bool) data_get($settings, 'sitemap.include.'.$section, true);
    }

    private function staticPages(): array
    {
        $managedRoutes = ContentPages::enabledRoutes();

        return collect([
            ['path' => '/', 'priority' => 1.0],
            ['path' => '/explore'],
            ['path' => '/trending'],
            ['path' => '/shorts'],
            ['path' => '/communities'],
            ['path' => '/live'],
            ['path' => '/live/now'],
            ['path' => '/live/upcoming'],
            ['path' => '/live/replays'],
            ['path' => '/playlists'],
            ['path' => '/about'],
            ['path' => '/contact'],
            ['path' => '/developers'],
            ['path' => '/faq'],
            ['path' => '/support'],
            ['path' => '/refund'],
            ['path' => '/privacy'],
            ['path' => '/terms'],
            ['path' => '/cookies'],
        ])->filter(fn (array $entry): bool => ! ContentPages::pageForPath($entry['path'], null, true) || in_array($entry['path'], $managedRoutes, true))
            ->map(fn (array $entry): array => array_replace([
                'last_modified' => ContentPages::pageForPath($entry['path'], null, true)
                    ? (ContentPages::updatedAt() ?: SeoSettings::updatedAt())
                    : SeoSettings::updatedAt(),
            ], $entry))->all();
    }

    private function queryCount(string $section): int
    {
        return $this->query($section)?->count() ?? 0;
    }

    private function queryLastModified(string $section): ?Carbon
    {
        $value = $this->query($section)?->max('updated_at');

        return $value ? Carbon::parse($value) : null;
    }

    private function query(string $section): ?Builder
    {
        $table = match ($section) {
            'videos', 'shorts' => 'videos',
            'channels' => 'channels',
            'playlists' => 'playlists',
            'live' => 'live_streams',
            default => null,
        };
        if (! $table || ! Schema::hasTable($table)) return null;

        return match ($section) {
            'videos' => $this->publishedVideos()->standardVideos()->with('channel:id,name,slug'),
            'shorts' => $this->publishedVideos()->shorts()->with('channel:id,name,slug'),
            'channels' => Channel::query()->whereHas('videos', fn (Builder $videos) => $this->constrainPublishedVideos($videos)),
            'playlists' => Playlist::query()->where('privacy', 'public')
                ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
                ->whereHas('videos', fn (Builder $videos) => $this->constrainPublishedVideos($videos)),
            'live' => $this->publicLiveStreams(),
            default => null,
        };
    }

    private function publishedVideos(): Builder
    {
        return $this->constrainPublishedVideos(Video::query());
    }

    private function constrainPublishedVideos(Builder $query): Builder
    {
        return $query->public();
    }

    private function publicLiveStreams(): Builder
    {
        return LiveStream::query()
            ->where('visibility', 'public')
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
            ->where(function (Builder $audience): void {
                $audience->whereNull('stream_settings')
                    ->orWhereNull('stream_settings->audience_mode')
                    ->orWhere('stream_settings->audience_mode', 'everyone');
            })
            ->where(function (Builder $query): void {
                $query->where('status', 'live')
                    ->orWhere(fn (Builder $scheduled) => $scheduled->where('status', 'scheduled')->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now()))
                    ->orWhere(function (Builder $replay): void {
                        $replay->where('status', 'ended')
                            ->whereNotNull('replay_video_id')
                            ->where(fn (Builder $expiry) => $expiry->whereNull('replay_expires_at')->orWhere('replay_expires_at', '>', now()))
                            ->whereHas('replayVideo', fn (Builder $videos) => $this->constrainPublishedVideos($videos));
                    });
            });
    }

    private function videoEntry(Video $video, bool $short): array
    {
        $thumbnail = null;
        if ($video->thumbnail_path) {
            $thumbnail = preg_match('#^(https?:)?//#i', $video->thumbnail_path)
                ? $video->thumbnail_path
                : '/media/videos/'.$video->id.'/thumbnail?v='.substr(hash('sha256', $video->thumbnail_path), 0, 12);
        }

        $content = null;
        if (! $video->sale_enabled && $video->source_type === 'import' && $video->import_provider === 'direct' && filter_var($video->import_url, FILTER_VALIDATE_URL) && in_array(strtolower((string) parse_url($video->import_url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            $content = $video->import_url;
        } elseif (! $video->sale_enabled && $video->source_type !== 'import' && $video->video_path) {
            $content = '/media/videos/'.$video->id.'/stream/high?v='.$video->mediaVersion('high');
        }

        return [
            'path' => ($short ? '/shorts/' : '/watch/').rawurlencode($video->slug),
            'last_modified' => $video->updated_at ?: $video->published_at,
            'image' => $thumbnail,
            'video' => $thumbnail && $content ? [
                'title' => $video->title,
                'description' => $video->description,
                'thumbnail' => $thumbnail,
                'content' => $content,
                'duration' => $video->duration_seconds,
                'published_at' => $video->published_at,
                'views' => $video->views_count,
                'tags' => $video->tags,
                'uploader' => $video->channel?->name,
                'uploader_path' => $video->channel?->slug ? '/channel/'.rawurlencode($video->channel->slug) : null,
            ] : null,
        ];
    }
}
