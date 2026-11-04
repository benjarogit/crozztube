<?php

namespace App\Http\Controllers;

use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\LiveStream;
use App\Models\User;
use App\Models\Video;
use App\Support\CommunityPhotoSales;
use App\Support\LiveStreamMedia;
use App\Support\MediaImageCache;
use App\Support\MediaStorage;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class MediaController extends Controller
{
    public function video(Request $request, Video $video, ?string $quality = null): Response
    {
        $this->authorizeVideoMedia($request, $video, true);
        $path = $this->videoPath($video, $quality);
        abort_unless($path, 404);

        return $this->stream($request, $path, $this->mimeTypeForPath($path), true, ! VideoSales::requiresSignedMedia($video));
    }

    public function thumbnail(Request $request, Video $video): Response
    {
        $this->authorizeVideoMedia($request, $video, false);
        abort_unless($video->thumbnail_path, 404);

        return $this->stream($request, $video->thumbnail_path, $this->mimeTypeForPath($video->thumbnail_path) ?: 'image/jpeg', false, $video->visibility !== 'private' && $video->user?->hasPublicProfileEnabled());
    }

    public function captions(Request $request, Video $video): Response
    {
        $this->authorizeVideoMedia($request, $video, true);
        abort_unless($video->captions_path, 404);

        return $this->stream($request, $video->captions_path, $this->mimeTypeForPath($video->captions_path) ?: 'text/vtt', false, ! VideoSales::requiresSignedMedia($video));
    }

    public function liveStreamThumbnail(Request $request, LiveStream $liveStream): Response
    {
        $hasValidSignature = $request->hasValidSignature();
        $publiclyAccessible = LiveStreamMedia::isPubliclyAccessible($liveStream);

        $liveStream->loadMissing('user');
        $privateProfile = ! $liveStream->user?->hasPublicProfileEnabled();
        abort_if($privateProfile && (! $hasValidSignature || (string) $request->query('profile_owner') !== (string) $liveStream->user_id), 404);

        abort_unless($hasValidSignature || $publiclyAccessible, 404);
        abort_unless($liveStream->thumbnail_path, 404);

        $version = trim((string) $request->query('v', ''));
        abort_if($version !== '' && ! hash_equals(LiveStreamMedia::version($liveStream->thumbnail_path), $version), 404);

        if (preg_match('#^https?://#i', $liveStream->thumbnail_path)) {
            $externalUrl = LiveStreamMedia::safeExternalUrl($liveStream->thumbnail_path);
            abort_unless($externalUrl, 404);

            return redirect()->away($externalUrl, 302, $this->mediaHeaders($publiclyAccessible && ! $hasValidSignature));
        }

        return $this->stream(
            $request,
            $liveStream->thumbnail_path,
            $this->mimeTypeForPath($liveStream->thumbnail_path) ?: 'image/jpeg',
            false,
            $publiclyAccessible && ! $hasValidSignature,
        );
    }

    public function communityPhoto(Request $request, CommunityPost $communityPost, int $index): Response
    {
        $paid = CommunityPhotoSales::isPaid($communityPost);
        abort_if($paid && ! $request->hasValidSignature(), 403, 'A valid paid photo link is required.');
        $attachment = collect($communityPost->attachments ?: [])->get($index);
        abort_unless(is_array($attachment) && ($attachment['type'] ?? null) === 'image' && ! empty($attachment['path']), 404);

        return $this->stream($request, $attachment['path'], $attachment['mime'] ?? $this->mimeTypeForPath($attachment['path']), false, ! $paid);
    }

    public function commentAttachment(Request $request, Comment $comment, int $index): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'A valid comment attachment link is required.');
        $attachment = collect($comment->attachments ?: [])->get($index);
        abort_unless(is_array($attachment) && ($attachment['type'] ?? null) === 'image' && ! empty($attachment['path']), 404);

        return $this->stream(
            $request,
            $attachment['path'],
            $attachment['mime'] ?? $this->mimeTypeForPath($attachment['path']),
            false,
            false,
        );
    }

    public function privateMessageAttachment(Request $request, int $message, int $index): Response
    {
        abort_unless($request->hasValidSignature(), 403, 'A valid message attachment link is required.');
        $row = DB::table('private_messages')->find($message);
        abort_unless($row, 404);
        $attachment = collect(json_decode((string) ($row->attachments ?? '[]'), true) ?: [])->get($index);
        abort_unless(is_array($attachment) && ($attachment['type'] ?? null) === 'image' && ! empty($attachment['path']), 404);

        return $this->stream($request, $attachment['path'], $attachment['mime'] ?? $this->mimeTypeForPath($attachment['path']), false, false);
    }

    /**
     * Legacy compatibility for older builds that returned /storage/videos/... URLs.
     * New API resources use /media/videos/{id}/stream to avoid Nginx static-file 404s.
     */
    public function legacyStorage(Request $request, string $path): Response
    {
        $path = $this->normalizeRelativePath($path);
        abort_if(
            str_starts_with($path, 'videos/')
                || str_starts_with($path, 'community/images/')
                || str_starts_with($path, 'comments/')
                || str_starts_with($path, 'messages/')
                || str_starts_with($path, 'live-streams/thumbnails/'),
            404,
        );

        return $this->stream($request, $path, null, true, true, true);
    }

    public function image(Request $request): Response
    {
        // Validate before consulting the cache. A warm cache is never an access bypass.
        abort_unless($request->hasValidSignature(), 403, 'A valid image link is required.');
        abort_unless(is_string($request->query('path')), 404);
        $path = $this->normalizeRelativePath($request->query('path'));
        abort_unless(MediaStorage::isPublicImagePath($path), 404);

        return $this->stream($request, $path, null, false, true);
    }

    private function stream(
        Request $request,
        string $path,
        ?string $fallbackMime = null,
        bool $allowRange = true,
        bool $publicCache = true,
        bool $legacyPublicOnly = false,
    ): Response {
        $path = $this->normalizeRelativePath($path);
        if (! MediaStorage::isLocal()) {
            if (MediaStorage::isImagePath($path)) {
                try {
                    $image = MediaImageCache::get($path);
                } catch (\Throwable) {
                    abort(503, 'Image delivery is temporarily unavailable.');
                }
                $response = new BinaryFileResponse($image['path'], 200, $this->mediaHeaders($publicCache) + [
                    'Content-Type' => $image['mime'],
                    'Content-Disposition' => 'inline',
                    'Content-Length' => (string) $image['size'],
                ], $publicCache);
                $response->setEtag($image['etag']);
                $response->setLastModified((new \DateTimeImmutable)->setTimestamp($image['modified']));
                if ($publicCache) {
                    $response->isNotModified($request);
                }

                return $response;
            }
            // Signing is local: do not precede every stream with a cloud HEAD.
            $expiresAt = now()->addHours(6);
            if ($request->hasValidSignature() && ctype_digit((string) $request->query('expires'))) {
                // Delivery must not extend a protected website link's
                // lifetime when issuing a provider-specific signed URL.
                $expiresAt->setTimestamp(min($expiresAt->getTimestamp(), (int) $request->query('expires')));
            }
            $url = $publicCache
                ? MediaStorage::streamUrl($path)
                : MediaStorage::temporaryStreamUrl($path, $expiresAt);
            abort_unless(filled($url), 503, 'Protected media delivery is unavailable for the configured storage provider.');

            // Keep website media on the application origin. Browser fetches
            // (including captions) must work without a separate bucket CORS
            // policy, and private objects still require the authorization above.
            return $this->streamCloud($request, $url, $fallbackMime, $allowRange, $publicCache);
        }

        [$absolutePath, $relativePath] = $this->resolvePath($path, $legacyPublicOnly);

        abort_unless($absolutePath && is_file($absolutePath), 404);

        $size = filesize($absolutePath);
        $start = 0;
        $end = max(0, $size - 1);
        $status = 200;

        $mime = $fallbackMime ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');
        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.basename($relativePath).'"',
            'Cache-Control' => $publicCache ? 'public, max-age=2592000' : 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Accept-Ranges' => $allowRange ? 'bytes' : 'none',
            'X-Content-Type-Options' => 'nosniff',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
        ];

        $range = $request->headers->get('Range');
        if ($allowRange && $range && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
            if ($matches[1] === '') {
                $suffixLength = (int) $matches[2];
                abort_if($suffixLength < 1, 416, '', ['Content-Range' => "bytes */{$size}"]);
                $start = max(0, $size - $suffixLength);
            } else {
                $start = (int) $matches[1];
                if ($matches[2] !== '') {
                    $end = min((int) $matches[2], $end);
                }
            }

            if ($start > $end || $start >= $size) {
                abort(416, '', ['Content-Range' => "bytes */{$size}"]);
            }

            $status = 206;
            $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        }

        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return response()->stream(function () use ($absolutePath, $start, $length): void {
            $handle = fopen($absolutePath, 'rb');
            if ($handle === false) {
                return;
            }

            try {
                fseek($handle, $start);
                $remaining = $length;
                while ($remaining > 0 && ! feof($handle)) {
                    $chunkSize = min(1024 * 1024, $remaining);
                    echo fread($handle, $chunkSize);
                    flush();
                    $remaining -= $chunkSize;
                }
            } finally {
                fclose($handle);
            }
        }, $status, $headers);
    }

    private function streamCloud(Request $request, string $url, ?string $mime, bool $allowRange, bool $publicCache): Response
    {
        $range = $allowRange ? $request->headers->get('Range') : null;
        abort_if($range !== null && ! preg_match('/^bytes=(?:\d+-\d*|-\d+)$/D', trim($range)), 416);

        try {
            // One authenticated GET provides the body and metadata. Streaming
            // prevents buffering an entire video in the PHP worker's memory.
            $upstream = Http::connectTimeout(5)->timeout(60)
                ->withOptions(['stream' => true, 'decode_content' => false])
                ->withHeaders(array_filter(['Range' => $range]))
                ->get($url);
        } catch (\Throwable) {
            abort(503, 'Media delivery is temporarily unavailable.');
        }

        $body = $upstream->toPsrResponse()->getBody();
        $headers = $this->mediaHeaders($publicCache) + [
            'Content-Type' => $mime ?: ($upstream->header('Content-Type') ?: 'application/octet-stream'),
            'Content-Disposition' => 'inline',
            'Accept-Ranges' => $allowRange ? 'bytes' : 'none',
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Expose-Headers' => 'Content-Length, Content-Range, Accept-Ranges',
            'X-Accel-Buffering' => 'no',
        ];
        $contentRange = $upstream->header('Content-Range');
        if (is_string($contentRange) && preg_match('/^bytes (?:\d+-\d+|\*)\/(?:\d+|\*)$/D', $contentRange)) {
            $headers['Content-Range'] = $contentRange;
        }
        if ($upstream->status() === 416) {
            $body->close();

            return response('', 416, $headers);
        }
        if (! in_array($upstream->status(), [200, 206], true)
            || ($upstream->status() === 206 && ! isset($headers['Content-Range']))) {
            $body->close();
            // Never return a provider's XML errors, credentials or signed URL.
            abort($upstream->status() === 404 ? 404 : 503, 'Media delivery is temporarily unavailable.');
        }
        $length = $upstream->header('Content-Length');
        if (is_string($length) && ctype_digit($length)) {
            $headers['Content-Length'] = $length;
        }

        if ($request->isMethod('HEAD')) {
            $body->close();

            return response('', $upstream->status(), $headers);
        }

        return response()->stream(function () use ($body): void {
            try {
                while (! $body->eof() && ! connection_aborted()) {
                    $chunk = $body->read(1024 * 1024);
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } catch (\Throwable) {
                // Headers may already be sent. Closing the short response lets
                // the player retry its range without exposing provider details.
            } finally {
                $body->close();
            }
        }, $upstream->status(), $headers);
    }

    private function videoPath(Video $video, ?string $quality): ?string
    {
        $renditions = collect($video->renditions ?: [])->keyBy('quality');
        $quality = $quality ?: ($video->status === 'ready' ? 'high' : 'original');

        return $renditions->get($quality)['path'] ?? $video->video_path;
    }

    private function mediaHeaders(bool $publicCache): array
    {
        $headers = [
            'Cache-Control' => $publicCache ? 'public, max-age=300' : 'private, no-store, no-cache, max-age=0, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if (! $publicCache) {
            $headers['Pragma'] = 'no-cache';
            $headers['Expires'] = '0';
        }

        return $headers;
    }

    private function authorizeVideoMedia(Request $request, Video $video, bool $paidSensitive): void
    {
        $hasValidSignature = $request->hasValidSignature();
        $viewer = VideoSales::viewer($request);
        $privileged = VideoSales::canViewPrivate($video, $viewer);
        abort_if($privileged && $viewer->isBannedFrom('watch'), 403, $viewer?->banMessage('watch'));

        $video->loadMissing('user');
        $privateProfile = ! $video->user?->hasPublicProfileEnabled();
        if (($video->visibility === 'private' || $privateProfile) && ! $privileged) {
            // Native video, caption and image requests cannot attach the website's
            // bearer token. The signed audience claim represents the authorized
            // viewer, whose current ownership/admin access is checked on delivery.
            $claimedViewer = $hasValidSignature && ctype_digit((string) $request->query('private_viewer'))
                ? User::query()->find($request->query('private_viewer'))
                : null;
            abort_unless(
                VideoSales::canViewPrivate($video, $claimedViewer)
                    && ! $claimedViewer->isBannedFrom('watch')
                    && (! $privateProfile || (string) $request->query('profile_owner') === (string) $video->user_id),
                404,
            );
        }
        abort_if($paidSensitive && VideoSales::isPaid($video) && ! $hasValidSignature, 403, 'A valid paid video playback link is required.');

        if ($hasValidSignature || $privileged) {
            return;
        }

        abort_unless(
            $video->visibility === 'public'
                && $video->status === 'ready'
                && (! $video->approval_status || $video->approval_status === 'approved')
                && (! $video->scheduled_at || ! $video->scheduled_at->isFuture()),
            404,
        );
    }

    private function mimeTypeForPath(string $path): ?string
    {
        return match (strtolower(pathinfo((string) parse_url($path, PHP_URL_PATH), PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
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
            'vtt' => 'text/vtt; charset=UTF-8',
            'srt' => 'application/x-subrip; charset=UTF-8',
            default => null,
        };
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        abort_if($path === '' || str_contains($path, "\0") || preg_match('#(^|/)\.\.(/|$)#', $path), 404);
        abort_if(preg_match('#^[a-z][a-z0-9+.-]*:#i', $path), 404);

        return ltrim($path, '/');
    }

    private function resolvePath(string $path, bool $publicOnly = false): array
    {
        if ($publicOnly) {
            $root = realpath(base_path('uploads'));
            $candidate = realpath(base_path('uploads/'.$path));
            $rootPrefix = $root ? rtrim(str_replace('\\', '/', $root), '/').'/' : null;
            $normalizedCandidate = $candidate ? str_replace('\\', '/', $candidate) : null;

            return $rootPrefix && $normalizedCandidate
                && str_starts_with(strtolower($normalizedCandidate), strtolower($rootPrefix))
                && is_file($candidate)
                    ? [$candidate, $path]
                    : [null, $path];
        }

        if (MediaStorage::isLocal()) {
            $disk = MediaStorage::diskName();
            try {
                if (Storage::disk($disk)->fileExists($path)) {
                    return [Storage::disk($disk)->path($path), $path];
                }
            } catch (\Throwable) {
                // Continue with safe fallback locations below.
            }
        }

        $candidates = [
            base_path('uploads/'.$path),
            storage_path('app/private/'.$path),
            storage_path('app/public/'.$path),
            storage_path('app/'.$path),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return [$candidate, $path];
            }
        }

        return [null, $path];
    }
}
