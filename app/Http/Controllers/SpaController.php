<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\CommunityPost;
use App\Models\LiveStream;
use App\Models\Playlist;
use App\Models\Video;
use App\Support\ContentPages;
use App\Support\GeneralSettings;
use App\Support\LiveStreamMedia;
use App\Support\MediaStorage;
use App\Support\SeoSettings;
use App\Support\VideoImportSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SpaController extends Controller
{
    private const SOCIAL_IMAGE_VERSION = 8;
    private const SOCIAL_IMAGE_MAX_SOURCE_BYTES = 10 * 1024 * 1024;
    private const SOCIAL_IMAGE_MAX_SOURCE_PIXELS = 40_000_000;

    public function __invoke(Request $request): View
    {
        return view('app', ['socialPreview' => $this->socialPreview($request)]);
    }

    public function embed(Video $video): Response
    {
        abort_unless(
            $video->status === 'ready'
                && in_array($video->visibility, ['public', 'unlisted'], true)
                && ($video->approval_status ?: 'approved') === 'approved'
                && $video->user?->hasPublicProfileEnabled(),
            404,
        );

        $settings = GeneralSettings::publicSettings();
        $siteName = trim((string) ($settings['platformName'] ?? '')) ?: config('app.name', 'PlayHub');

        return response()->view('embed-player', [
            'embedConfig' => [
                'slug' => $video->slug,
                'title' => $video->title,
                'thumbnailUrl' => $video->thumbnail_url,
                'watchUrl' => url('/watch/'.$video->slug),
                'siteName' => $siteName,
                'primaryColor' => data_get($settings, 'design.colors.primary') ?: ($settings['defaultPrimaryColor'] ?? '#d4b477'),
                'fontFamily' => $settings['fontFamily'] ?? null,
            ],
            'faviconUrl' => $settings['faviconUrl'] ?? null,
        ])->withHeaders([
            'Cache-Control' => 'public, max-age=300',
            'Content-Security-Policy' => "frame-ancestors *",
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function metadata(Request $request): JsonResponse
    {
        $target = trim((string) $request->query('path', '/'));
        abort_if($target === '' || strlen($target) > 2048 || str_contains($target, "\n") || str_contains($target, "\r"), 422, 'Invalid website path.');
        $parts = parse_url($target);
        abort_if($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']), 422, 'Invalid website path.');
        $path = (string) ($parts['path'] ?? '/');
        abort_unless(str_starts_with($path, '/'), 422, 'Invalid website path.');
        $url = $request->getSchemeAndHttpHost().$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return response()->json(['metadata' => $this->socialPreview(Request::create($url, 'GET'))])
            ->header('Cache-Control', 'no-store');
    }

    public function socialImage(Request $request): Response
    {
        $settings = GeneralSettings::settings();
        $seo = SeoSettings::settings();
        $siteName = $this->siteName($settings);
        $description = $this->cleanText(
            $seo['siteDescription'] ?: data_get($settings, 'authPreview.description'),
            'Watch videos, Shorts, live streams, and creators on '.$siteName.'.',
            150,
        );
        $image = imagecreatetruecolor(1200, 630);
        abort_unless($image, 500);

        $background = $this->imageColor($image, data_get($settings, 'design.colors.bg'), '#edf0f4');
        $panel = $this->imageColor($image, data_get($settings, 'design.colors.panel'), '#fcfdff');
        $text = $this->imageColor($image, data_get($settings, 'design.colors.text'), '#28384b');
        $muted = $this->imageColor($image, data_get($settings, 'design.colors.muted'), '#586478');
        $line = $this->imageColor($image, data_get($settings, 'design.colors.line'), '#d1dae5');
        $accent = $this->imageColor($image, data_get($settings, 'design.colors.primary'), '#d4b477');
        $white = imagecolorallocate($image, 255, 255, 255);

        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 42, 42, 1157, 587, $panel);
        imagefilledrectangle($image, 42, 42, 1157, 53, $accent);
        imagerectangle($image, 42, 42, 1157, 587, $line);
        imagefilledellipse($image, 119, 135, 92, 92, $accent);
        imagefilledpolygon($image, [107, 106, 107, 164, 145, 135], $white);

        $boldFont = base_path('vendor/mews/captcha/assets/fonts/Asap_700.ttf');
        $regularFont = base_path('vendor/mews/captcha/assets/fonts/Open_Sans_regular.ttf');
        $displayName = $this->cleanText($siteName, 'PlayHub', 70);
        $titleSize = 72;
        while ($titleSize > 38 && $this->imageTextWidth($displayName, $boldFont, $titleSize) > 1010) {
            $titleSize -= 2;
        }

        imagettftext($image, 34, 0, 185, 147, $text, $boldFont, $displayName);
        imagettftext($image, $titleSize, 0, 88, 330, $text, $boldFont, $displayName);
        $descriptionLines = $this->wrapImageText($description, $regularFont, 28, 990, 2);
        foreach ($descriptionLines as $index => $lineText) {
            imagettftext($image, 28, 0, 91, 405 + ($index * 43), $muted, $regularFont, $lineText);
        }
        imagettftext($image, 22, 0, 91, 542, $accent, $boldFont, $request->getHost());

        ob_start();
        imagepng($image, null, 6);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return response($contents, 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => 'image/png',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function videoSocialImage(Video $video): Response
    {
        $video = $this->publishedVideos(Video::query(), true)->find($video->id);
        abort_unless($video?->thumbnail_path, 404);

        $image = imagecreatetruecolor(1200, 630);
        abort_unless($image, 500);
        imagefill($image, 0, 0, imagecolorallocate($image, 8, 12, 20));

        $thumbnailContents = $this->videoThumbnailContents($video);
        $sourceSize = $thumbnailContents ? @getimagesizefromstring($thumbnailContents) : false;
        $source = $sourceSize
            && $sourceSize[0] > 0
            && $sourceSize[1] > 0
            && $sourceSize[0] * $sourceSize[1] <= self::SOCIAL_IMAGE_MAX_SOURCE_PIXELS
                ? @imagecreatefromstring($thumbnailContents)
                : false;
        if ($source) {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $sourceRatio = $sourceWidth / max(1, $sourceHeight);
            $targetRatio = 1200 / 630;
            if ($sourceRatio > $targetRatio) {
                $cropHeight = $sourceHeight;
                $cropWidth = (int) round($sourceHeight * $targetRatio);
                $sourceX = (int) round(($sourceWidth - $cropWidth) / 2);
                $sourceY = 0;
            } else {
                $cropWidth = $sourceWidth;
                $cropHeight = (int) round($sourceWidth / $targetRatio);
                $sourceX = 0;
                $sourceY = (int) round(($sourceHeight - $cropHeight) / 2);
            }
            imagecopyresampled($image, $source, 0, 0, $sourceX, $sourceY, 1200, 630, $cropWidth, $cropHeight);
            imagedestroy($source);
        }

        imagealphablending($image, true);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledellipse($image, 600, 315, 160, 160, imagecolorallocatealpha($image, 0, 0, 0, 32));
        imagefilledpolygon($image, [580, 277, 580, 353, 640, 315], $white);

        ob_start();
        imagejpeg($image, null, 88);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return response($contents, 200, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'Content-Type' => 'image/jpeg',
            'Content-Length' => (string) strlen($contents),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function socialPreview(Request $request): array
    {
        $settings = GeneralSettings::settings();
        $seo = SeoSettings::settings();
        $siteName = $this->siteName($settings);
        $websiteTitle = trim((string) ($seo['siteTitle'] ?? '')) ?: trim((string) ($settings['websiteTitle'] ?? '')) ?: $siteName;
        $description = $this->cleanText(
            $seo['siteDescription'] ?: data_get($settings, 'authPreview.description'),
            'Watch videos, Shorts, live streams, and creators on '.$siteName.'.',
        );
        $fallbackVersion = substr(hash('sha256', json_encode([
            self::SOCIAL_IMAGE_VERSION,
            $siteName,
            $description,
            data_get($settings, 'design.colors.bg'),
            data_get($settings, 'design.colors.primary'),
            $seo['defaultImageUrl'],
        ])), 0, 12);
        $defaultImage = $this->absoluteUrl($seo['defaultImageUrl'], $request, $seo['canonicalBaseUrl'])
            ?: $this->absoluteUrl('/social-preview?v='.$fallbackVersion, $request, $seo['canonicalBaseUrl']);
        $preview = [
            'site_name' => $siteName,
            'title' => $websiteTitle,
            'browser_title' => $websiteTitle,
            'description' => $description,
            'url' => $this->canonicalUrl($request->getPathInfo(), $request, $seo),
            'type' => 'website',
            'image' => $defaultImage,
            'image_type' => $seo['defaultImageUrl'] ? $this->imageMimeType($seo['defaultImageUrl']) : 'image/png',
            'image_width' => $seo['defaultImageUrl'] ? null : 1200,
            'image_height' => $seo['defaultImageUrl'] ? null : 630,
            'image_alt' => $websiteTitle,
            'section' => null,
            'indexable' => false,
        ];

        try {
            $path = trim($request->path(), '/');
            if (preg_match('#^(watch|shorts)/([^/]+)$#', $path, $matches)) {
                $preview = $this->videoPreview($preview, rawurldecode($matches[2]), $matches[1] === 'shorts', $request);
            } elseif (preg_match('#^live/([^/]+)$#', $path, $matches) && ! in_array($matches[1], ['now', 'upcoming', 'replays'], true)) {
                $preview = $this->livePreview($preview, rawurldecode($matches[1]), $request);
            } elseif ($path === 'playlists' && $request->integer('id') > 0) {
                $preview = $this->playlistPreview($preview, $request->integer('id'), $request);
            } elseif (preg_match('#^channel/([^/]+)(?:/.*)?$#', $path, $matches)) {
                $preview = $this->channelPreview($preview, rawurldecode($matches[1]), $request);
            } else {
                $preview = $this->staticPagePreview($preview, $path);
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        $preview['title'] = $this->cleanText($preview['title'] ?? null, $websiteTitle, 120);
        $preview['browser_title'] = $this->browserTitle($preview['title'], $websiteTitle, $siteName, $seo);
        $preview['description'] = $this->cleanText($preview['description'] ?? null, $description);
        $preview['image'] = $this->absoluteUrl($preview['image'] ?? null, $request, $seo['canonicalBaseUrl']) ?: $defaultImage;
        $preview['image_alt'] = $this->cleanText($preview['image_alt'] ?? null, $preview['title'], 180);
        $section = $preview['section'] ?? null;
        $preview['indexable'] = (bool) ($preview['indexable'] ?? false)
            && (bool) data_get($seo, 'indexing.enabled', true)
            && $section
            && (bool) data_get($seo, 'indexing.include.'.$section, true);
        $preview['robots'] = $preview['indexable']
            ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
            : 'noindex, nofollow';
        $preview['keywords'] = $seo['keywords'];
        $preview['locale'] = str_replace('-', '_', (string) ($settings['defaultLanguage'] ?? config('app.locale', 'en')));
        $preview['twitter_site'] = $seo['twitterSite'];
        $preview['google_site_verification'] = $seo['googleSiteVerification'];
        $preview['bing_site_verification'] = $seo['bingSiteVerification'];
        $preview['custom_meta_tags'] = $seo['customMetaTags'];
        $preview['structured_data'] = $this->structuredData($preview, $request, $seo);

        return $preview;
    }

    private function videoPreview(array $preview, string $slug, bool $short, Request $request): array
    {
        $video = $this->publishedVideos(Video::query(), true)
            ->with('channel:id,name,slug')
            ->where('slug', $slug)
            ->first();
        if (! $video || ($short && ! $video->isShort())) {
            return $preview;
        }

        $creator = $video->channel?->name ?: 'a creator';
        $kind = $short ? 'Short' : 'video';
        $image = $video->thumbnail_path
            ? $this->absoluteUrl('/social-preview/videos/'.$video->id.'?v='.$this->videoSocialImageVersion($video), $request)
            : null;
        $guestAccessible = $video->visibility === 'public'
            && $video->status === 'ready'
            && in_array($video->approval_status, [null, 'approved'], true)
            && (! $video->scheduled_at || $video->scheduled_at->isPast());

        return array_replace($preview, [
            'title' => $video->title,
            'description' => $this->cleanText($video->description, 'Watch this '.$kind.' by '.$creator.' on '.$preview['site_name'].'.'),
            'type' => 'video.other',
            'image' => $image ?: $preview['image'],
            'image_type' => $image ? 'image/jpeg' : $preview['image_type'],
            'image_width' => $image ? 1200 : $preview['image_width'],
            'image_height' => $image ? 630 : $preview['image_height'],
            'image_alt' => $video->title,
            'video_duration' => max(0, (int) $video->duration_seconds) ?: null,
            'published_time' => ($video->published_at ?: $video->created_at)?->toIso8601String(),
            'section' => $short ? 'shorts' : 'videos',
            'indexable' => $guestAccessible && ($short ? $video->isShort() : ! $video->isShort()),
            'schema_type' => 'VideoObject',
            'schema_author' => $video->channel?->name,
            'schema_author_url' => $video->channel?->slug ? '/channel/'.rawurlencode($video->channel->slug) : null,
            'schema_content_url' => $this->videoContentUrl($video, $request),
        ]);
    }

    private function livePreview(array $preview, string $slug, Request $request): array
    {
        $stream = LiveStream::query()
            ->with(['channel:id,name,slug,avatar_path,cover_path', 'replayVideo'])
            ->where('slug', $slug)
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
            ->whereIn('visibility', ['public', 'unlisted'])
            ->where(function (Builder $query): void {
                $query->whereNull('stream_settings')
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
                            ->whereHas('replayVideo', fn (Builder $video) => $this->publishedVideos($video, true));
                    });
            })
            ->first();
        if (! $stream) {
            return $preview;
        }

        $creator = $stream->channel?->name ?: 'A creator';
        $fallback = match ($stream->status) {
            'live' => $creator.' is live now on '.$preview['site_name'].'.',
            'scheduled' => $creator.' has scheduled a live stream on '.$preview['site_name'].'.',
            default => 'Watch the live stream replay from '.$creator.' on '.$preview['site_name'].'.',
        };
        $imageSource = $stream->thumbnail_path ?: $stream->replayVideo?->thumbnail_path ?: $stream->channel?->cover_path ?: $stream->channel?->avatar_path;
        $image = $stream->thumbnail_path
            ? $this->absoluteUrl(LiveStreamMedia::thumbnailUrl($stream), $request)
            : ($stream->replayVideo?->thumbnail_url
                ? $this->absoluteUrl($stream->replayVideo->thumbnail_url, $request)
                : $this->mediaUrl($stream->channel?->cover_path ?: $stream->channel?->avatar_path, $request));
        $title = trim((string) $stream->title) ?: $creator.' live stream';
        $audienceMode = data_get($stream->stream_settings, 'audience_mode');
        $guestAudience = ! $audienceMode || $audienceMode === 'everyone';
        $guestStatus = $stream->status === 'live'
            || ($stream->status === 'scheduled' && $stream->scheduled_at?->isFuture())
            || ($stream->status === 'ended' && $stream->replay_video_id && (! $stream->replay_expires_at || $stream->replay_expires_at->isFuture()));

        return array_replace($preview, [
            'title' => $title,
            'description' => $this->cleanText($stream->description, $fallback),
            'type' => 'video.other',
            'image' => $image ?: $preview['image'],
            'image_type' => $image ? $this->imageMimeType($imageSource) : $preview['image_type'],
            'image_width' => $image ? null : $preview['image_width'],
            'image_height' => $image ? null : $preview['image_height'],
            'image_alt' => $title,
            'video_duration' => max(0, (int) $stream->replayVideo?->duration_seconds) ?: null,
            'published_time' => ($stream->actual_started_at ?: $stream->scheduled_at ?: $stream->ended_at ?: $stream->created_at)?->toIso8601String(),
            'section' => 'live',
            'indexable' => $stream->visibility === 'public' && $guestAudience && $guestStatus,
            'schema_type' => 'VideoObject',
            'schema_author' => $stream->channel?->name,
            'schema_author_url' => $stream->channel?->slug ? '/channel/'.rawurlencode($stream->channel->slug) : null,
            'schema_content_url' => $stream->replayVideo ? $this->videoContentUrl($stream->replayVideo, $request) : null,
            'schema_live' => $stream->status === 'live',
        ]);
    }

    private function playlistPreview(array $preview, int $playlistId, Request $request): array
    {
        $playlist = Playlist::query()->with('channel:id,name,slug')
            ->where('privacy', 'public')
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
            ->find($playlistId);
        if (! $playlist) {
            return $preview;
        }

        $videos = $this->publishedVideos($playlist->videos());
        $cover = $playlist->cover_video_id
            ? $this->publishedVideos(Video::query())->find($playlist->cover_video_id)
            : null;
        $cover ??= (clone $videos)->whereNotNull('videos.thumbnail_path')->first();
        $videoCount = (clone $videos)->count();
        $creator = $playlist->channel?->name ?: 'a creator';
        $image = $this->absoluteUrl($cover?->thumbnail_url, $request);
        $preview['url'] .= '?id='.$playlist->id;

        return array_replace($preview, [
            'title' => $playlist->title,
            'description' => $this->cleanText($playlist->description, 'Watch '.$videoCount.' videos from '.$creator.' on '.$preview['site_name'].'.'),
            'image' => $image ?: $preview['image'],
            'image_type' => $image ? $this->imageMimeType($cover?->thumbnail_path) : $preview['image_type'],
            'image_width' => $image ? null : $preview['image_width'],
            'image_height' => $image ? null : $preview['image_height'],
            'image_alt' => $playlist->title,
            'section' => 'playlists',
            'indexable' => $playlist->privacy === 'public' && $videoCount > 0,
            'schema_type' => 'CollectionPage',
            'schema_author' => $playlist->channel?->name,
            'schema_author_url' => $playlist->channel?->slug ? '/channel/'.rawurlencode($playlist->channel->slug) : null,
        ]);
    }

    private function channelPreview(array $preview, string $slug, Request $request): array
    {
        $channel = Channel::query()->where('slug', $slug)
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
            ->first();
        if (! $channel) {
            return $preview;
        }

        $postId = str_ends_with($request->path(), '/community') ? $request->integer('post') : 0;
        if ($postId > 0) {
            $post = CommunityPost::query()->where('channel_id', $channel->id)->find($postId);
            if ($post) {
                return $this->communityPostPreview($preview, $channel, $post, $request);
            }
        }

        $featured = $channel->featured_video_id
            ? $this->publishedVideos(Video::query())->find($channel->featured_video_id)
            : null;
        $latest = ! $channel->cover_path && ! $channel->avatar_path && ! $featured?->thumbnail_path
            ? $this->publishedVideos(Video::query())->where('channel_id', $channel->id)->whereNotNull('thumbnail_path')->latest('published_at')->first()
            : null;
        $imageSource = $channel->cover_path ?: $featured?->thumbnail_path ?: $channel->avatar_path ?: $latest?->thumbnail_path;
        $image = $channel->cover_path
            ? $this->mediaUrl($channel->cover_path, $request)
            : ($featured?->thumbnail_url
                ? $this->absoluteUrl($featured->thumbnail_url, $request)
                : ($channel->avatar_path ? $this->mediaUrl($channel->avatar_path, $request) : $this->absoluteUrl($latest?->thumbnail_url, $request)));

        return array_replace($preview, [
            'title' => $channel->name,
            'description' => $this->cleanText($channel->bio ?: $channel->about, 'Watch videos and updates from '.$channel->name.' on '.$preview['site_name'].'.'),
            'type' => 'profile',
            'image' => $image ?: $preview['image'],
            'image_type' => $image ? $this->imageMimeType($imageSource) : $preview['image_type'],
            'image_width' => $image ? null : $preview['image_width'],
            'image_height' => $image ? null : $preview['image_height'],
            'image_alt' => $channel->name,
            'profile_username' => $channel->slug,
            'section' => 'channels',
            'indexable' => trim($request->path(), '/') === 'channel/'.$channel->slug,
            'schema_type' => 'ProfilePage',
        ]);
    }

    private function communityPostPreview(array $preview, Channel $channel, CommunityPost $post, Request $request): array
    {
        $attachment = $post->sale_enabled ? null : collect($post->attachments ?: [])->first(fn (mixed $item): bool => is_array($item) && ($item['type'] ?? 'image') === 'image' && ! empty($item['url'] ?? $item['path'] ?? null));
        $postVideo = $post->video_id ? $this->publishedVideos(Video::query())->find($post->video_id) : null;
        $imageSource = $attachment['url'] ?? $attachment['path'] ?? $postVideo?->thumbnail_path ?? $post->url_image ?? $channel->cover_path ?? $channel->avatar_path;
        $image = $attachment
            ? $this->mediaUrl($attachment['url'] ?? $attachment['path'], $request)
            : ($postVideo?->thumbnail_url
                ? $this->absoluteUrl($postVideo->thumbnail_url, $request)
                : $this->mediaUrl($post->url_image ?: $channel->cover_path ?: $channel->avatar_path, $request));
        $eventTitle = $post->type === 'event' ? trim((string) data_get($post->metadata, 'title')) : '';
        $title = $eventTitle ?: 'Post by '.$channel->name;
        $description = $post->body ?: $post->url_description ?: data_get($post->metadata, 'description');
        $preview['url'] .= '?post='.$post->id;

        return array_replace($preview, [
            'title' => $title,
            'description' => $this->cleanText($description, 'View this community post from '.$channel->name.' on '.$preview['site_name'].'.'),
            'type' => 'article',
            'image' => $image ?: $preview['image'],
            'image_type' => $image ? $this->imageMimeType($imageSource) : $preview['image_type'],
            'image_width' => $image ? null : $preview['image_width'],
            'image_height' => $image ? null : $preview['image_height'],
            'image_alt' => $title,
            'published_time' => $post->created_at?->toIso8601String(),
            'schema_type' => 'SocialMediaPosting',
            'schema_author' => $channel->name,
            'schema_author_url' => '/channel/'.rawurlencode($channel->slug),
        ]);
    }

    private function staticPagePreview(array $preview, string $path): array
    {
        $pages = [
            '' => [$preview['title'], $preview['description']],
            'explore' => ['Explore videos', 'Discover videos and creators across '.$preview['site_name'].'.'],
            'trending' => ['Trending videos', 'Watch videos gaining attention now on '.$preview['site_name'].'.'],
            'shorts' => ['Shorts', 'Watch short-form videos from creators on '.$preview['site_name'].'.'],
            'communities' => ['Creator communities', 'Join creator conversations and discover community updates on '.$preview['site_name'].'.'],
            'live' => ['Live streams', 'Watch live and upcoming creator streams on '.$preview['site_name'].'.'],
            'live/now' => ['Live now', 'See creators streaming live now on '.$preview['site_name'].'.'],
            'live/upcoming' => ['Upcoming live streams', 'Find scheduled creator streams on '.$preview['site_name'].'.'],
            'live/replays' => ['Live stream replays', 'Watch available live stream replays on '.$preview['site_name'].'.'],
            'playlists' => ['Playlists', 'Discover public video collections on '.$preview['site_name'].'.'],
            'about' => ['About', 'Learn about '.$preview['site_name'].' and its creator community.'],
            'contact' => ['Contact', 'Contact the '.$preview['site_name'].' support team.'],
            'developers' => ['Developers', 'Developer information and platform capabilities for '.$preview['site_name'].'.'],
            'faq' => ['Frequently asked questions', 'Find answers to common questions about '.$preview['site_name'].'.'],
            'support' => ['Support', 'Get help with your '.$preview['site_name'].' account and content.'],
            'refund' => ['Refund policy', 'Read the '.$preview['site_name'].' refund policy.'],
            'privacy' => ['Privacy policy', 'Read how '.$preview['site_name'].' handles personal information.'],
            'terms' => ['Terms of service', 'Read the terms that apply when using '.$preview['site_name'].'.'],
            'cookies' => ['Cookie policy', 'Read how '.$preview['site_name'].' uses cookies.'],
        ];
        if (! array_key_exists($path, $pages)) return $preview;

        $managedPage = ContentPages::pageForPath($path, null, true);
        if ($managedPage) {
            return array_replace($preview, [
                'title' => $managedPage['title'],
                'description' => $managedPage['subtitle'],
                'section' => 'pages',
                'indexable' => $managedPage['enabled'],
                'schema_type' => 'WebPage',
            ]);
        }

        return array_replace($preview, [
            'title' => $pages[$path][0],
            'description' => $pages[$path][1],
            'section' => 'pages',
            'indexable' => true,
            'schema_type' => 'WebPage',
        ]);
    }

    private function browserTitle(string $title, string $websiteTitle, string $siteName, array $seo): string
    {
        if ($title === $websiteTitle || ! ($seo['appendSiteName'] ?? true) || $title === $siteName) return $title;

        return $this->cleanText($title.' '.($seo['titleSeparator'] ?: '|').' '.$siteName, $title, 150);
    }

    private function structuredData(array $preview, Request $request, array $seo): array
    {
        $base = rtrim($seo['canonicalBaseUrl'] ?: $request->getSchemeAndHttpHost(), '/');
        $general = GeneralSettings::settings();
        $logo = $this->absoluteUrl($general['logoUrl'] ?? null, $request, $seo['canonicalBaseUrl']);
        $siteDescription = $this->cleanText(
            $seo['siteDescription'] ?: data_get($general, 'authPreview.description'),
            'Watch videos, Shorts, live streams, and creators on '.$preview['site_name'].'.',
        );
        $organizationId = $base.'/#organization';
        $websiteId = $base.'/#website';
        $graph = [[
            '@type' => 'Organization',
            '@id' => $organizationId,
            'name' => $preview['site_name'],
            'url' => $base.'/',
            ...($logo ? ['logo' => ['@type' => 'ImageObject', 'url' => $logo]] : []),
        ], [
            '@type' => 'WebSite',
            '@id' => $websiteId,
            'url' => $base.'/',
            'name' => $preview['site_name'],
            'description' => $siteDescription,
            'publisher' => ['@id' => $organizationId],
            'inLanguage' => str_replace('_', '-', $preview['locale']),
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $base.'/search?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ]];

        $type = $preview['schema_type'] ?? 'WebPage';
        $entity = [
            '@type' => $type,
            '@id' => $preview['url'].'#'.match ($type) {
                'VideoObject' => 'video',
                'ProfilePage' => 'profile',
                'SocialMediaPosting' => 'post',
                default => 'webpage',
            },
            'url' => $preview['url'],
            'name' => $preview['title'],
            'description' => $preview['description'],
            'isPartOf' => ['@id' => $websiteId],
            'inLanguage' => str_replace('_', '-', $preview['locale']),
        ];
        if (! empty($preview['image'])) $entity['image'] = $preview['image'];

        if ($type === 'VideoObject') {
            $entity['thumbnailUrl'] = [$preview['image']];
            $entity['mainEntityOfPage'] = $preview['url'];
            if (! empty($preview['published_time'])) $entity['uploadDate'] = $preview['published_time'];
            if (! empty($preview['video_duration'])) $entity['duration'] = $this->isoDuration((int) $preview['video_duration']);
            if (! empty($preview['schema_content_url'])) $entity['contentUrl'] = $preview['schema_content_url'];
            if (! empty($preview['schema_author'])) {
                $entity['author'] = [
                    '@type' => 'Organization',
                    'name' => $preview['schema_author'],
                    ...(! empty($preview['schema_author_url']) ? ['url' => $this->absoluteUrl($preview['schema_author_url'], $request, $seo['canonicalBaseUrl'])] : []),
                ];
            }
            if (! empty($preview['schema_live'])) $entity['publication'] = ['@type' => 'BroadcastEvent', 'isLiveBroadcast' => true, 'startDate' => $preview['published_time'] ?? now()->toIso8601String()];
        } elseif ($type === 'ProfilePage') {
            $entity['mainEntity'] = ['@type' => 'Organization', 'name' => $preview['title'], 'url' => $preview['url'], ...(! empty($preview['image']) ? ['image' => $preview['image']] : [])];
        } elseif ($type === 'SocialMediaPosting') {
            $entity['headline'] = $preview['title'];
            if (! empty($preview['published_time'])) $entity['datePublished'] = $preview['published_time'];
            if (! empty($preview['schema_author'])) $entity['author'] = ['@type' => 'Organization', 'name' => $preview['schema_author'], 'url' => $this->absoluteUrl($preview['schema_author_url'], $request, $seo['canonicalBaseUrl'])];
        } else {
            $entity['publisher'] = ['@id' => $organizationId];
        }
        $graph[] = $entity;

        return ['@context' => 'https://schema.org', '@graph' => $graph];
    }

    private function canonicalUrl(string $path, Request $request, array $seo): string
    {
        $base = rtrim($seo['canonicalBaseUrl'] ?: $request->getSchemeAndHttpHost(), '/');

        return $base.'/'.ltrim($path, '/');
    }

    private function isoDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $seconds %= 60;

        return 'PT'.($hours ? $hours.'H' : '').($minutes ? $minutes.'M' : '').($seconds || (! $hours && ! $minutes) ? $seconds.'S' : '');
    }

    private function videoContentUrl(Video $video, Request $request): ?string
    {
        if ($video->sale_enabled) return null;
        if ($video->source_type === 'import') {
            $url = trim((string) $video->import_url);
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

            return $video->import_provider === 'direct'
                && filter_var($url, FILTER_VALIDATE_URL)
                && in_array($scheme, ['http', 'https'], true)
                    ? $this->absoluteUrl(VideoImportSettings::directPlaybackUrl($url), $request)
                    : null;
        }
        if (! $video->video_path) return null;

        return $this->absoluteUrl(
            '/media/videos/'.$video->id.'/stream/high?v='.$video->mediaVersion('high'),
            $request,
            SeoSettings::settings()['canonicalBaseUrl'] ?? '',
        );
    }

    private function videoSocialImageVersion(Video $video): string
    {
        return substr(hash('sha256', json_encode([
            self::SOCIAL_IMAGE_VERSION,
            $video->thumbnail_path,
        ])), 0, 12);
    }

    private function videoThumbnailContents(Video $video): ?string
    {
        $path = trim((string) $video->thumbnail_path);
        if (preg_match('#^https?://#i', $path)) {
            return $this->remoteImageContents($path);
        }

        $path = ltrim(str_replace('\\', '/', $path), '/');
        if ($path === '' || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $path)) return null;

        try {
            $disk = MediaStorage::disk();
            if ($disk->exists($path) && $disk->size($path) <= self::SOCIAL_IMAGE_MAX_SOURCE_BYTES) {
                $contents = $disk->get($path);
                if (is_string($contents)) return $contents;
            }
        } catch (Throwable) {
            // Continue with local compatibility paths used by the media controller.
        }

        foreach ([
            base_path('uploads/'.$path),
            storage_path('app/private/'.$path),
            storage_path('app/public/'.$path),
            storage_path('app/'.$path),
        ] as $candidate) {
            if (is_file($candidate) && filesize($candidate) <= self::SOCIAL_IMAGE_MAX_SOURCE_BYTES) {
                $contents = @file_get_contents($candidate);
                if (is_string($contents)) return $contents;
            }
        }

        return null;
    }

    private function remoteImageContents(string $url): ?string
    {
        if (! $this->isPublicImageUrl($url)) return null;

        try {
            $response = Http::withOptions(['allow_redirects' => false, 'stream' => true])
                ->accept('image/avif,image/webp,image/png,image/jpeg,image/*')
                ->connectTimeout(4)
                ->timeout(10)
                ->get($url);
            $contentType = strtolower(trim(strtok((string) $response->header('Content-Type'), ';') ?: ''));
            $contentLength = (int) $response->header('Content-Length');
            if (! $response->successful() || ! str_starts_with($contentType, 'image/') || $contentLength > self::SOCIAL_IMAGE_MAX_SOURCE_BYTES) {
                return null;
            }

            $stream = $response->toPsrResponse()->getBody();
            $contents = '';
            while (! $stream->eof()) {
                $remaining = self::SOCIAL_IMAGE_MAX_SOURCE_BYTES - strlen($contents);
                if ($remaining <= 0) return null;
                $contents .= $stream->read(min(8192, $remaining + 1));
                if (strlen($contents) > self::SOCIAL_IMAGE_MAX_SOURCE_BYTES) return null;
            }

            return $contents !== '' ? $contents : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isPublicImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = trim((string) $parts['host'], '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : array_values(array_filter(array_map(
                fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
                dns_get_record($host, DNS_A | DNS_AAAA) ?: [],
            )));

        return $addresses !== [] && collect($addresses)->every(fn (string $address): bool => (bool) filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ));
    }

    private function publishedVideos(Builder|BelongsToMany $query, bool $allowUnlisted = false): Builder|BelongsToMany
    {
        return $query
            ->whereIn('videos.visibility', $allowUnlisted ? ['public', 'unlisted'] : ['public'])
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled())
            ->where('videos.status', 'ready')
            ->where(fn ($approval) => $approval->whereNull('videos.approval_status')->orWhere('videos.approval_status', 'approved'))
            ->where(fn ($schedule) => $schedule->whereNull('videos.scheduled_at')->orWhere('videos.scheduled_at', '<=', now()));
    }

    private function siteName(array $settings): string
    {
        return trim((string) ($settings['platformName'] ?? ''))
            ?: trim((string) ($settings['logoText'] ?? ''))
            ?: (string) config('app.name', 'PlayHub');
    }

    private function cleanText(mixed $value, string $fallback, int $limit = 220): string
    {
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return Str::limit($text ?: $fallback, $limit);
    }

    private function mediaUrl(?string $path, Request $request): ?string
    {
        return $this->absoluteUrl(MediaStorage::url($path), $request);
    }

    private function absoluteUrl(?string $url, Request $request, ?string $baseUrl = null): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = $request->getScheme().':'.$url;
        } elseif (str_starts_with($url, '/')) {
            $url = rtrim($baseUrl ?: $request->getSchemeAndHttpHost(), '/').$url;
        } elseif (! preg_match('#^https?://#i', $url)) {
            $url = rtrim($baseUrl ?: $request->getSchemeAndHttpHost(), '/').'/'.ltrim($url, '/');
        }

        if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return null;
        }

        $parts = parse_url($url);
        if ($request->isSecure() && strcasecmp((string) ($parts['host'] ?? ''), $request->getHost()) === 0 && ($parts['scheme'] ?? '') === 'http') {
            $url = 'https://'.substr($url, strlen('http://'));
        }

        return $url;
    }

    private function imageMimeType(?string $path): ?string
    {
        $extension = strtolower(pathinfo((string) parse_url((string) $path, PHP_URL_PATH), PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => null,
        };
    }

    private function imageColor(\GdImage $image, mixed $hex, string $fallback): int
    {
        $hex = ltrim((string) $hex, '#');
        if (! preg_match('/^[0-9a-f]{6}$/i', $hex)) {
            $hex = ltrim($fallback, '#');
        }

        return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    private function imageTextWidth(string $text, string $font, int $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box ? abs($box[2] - $box[0]) : 0;
    }

    private function wrapImageText(string $text, string $font, int $size, int $maxWidth, int $maxLines): array
    {
        $lines = [];
        $line = '';
        foreach (preg_split('/\s+/u', $text) ?: [] as $word) {
            $candidate = trim($line.' '.$word);
            if ($line !== '' && $this->imageTextWidth($candidate, $font, $size) > $maxWidth) {
                if (count($lines) >= $maxLines - 1) {
                    break;
                }
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '' && count($lines) < $maxLines) {
            $lines[] = $line;
        }

        return $lines;
    }
}
