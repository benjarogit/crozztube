<?php

namespace CrzSourceResolver\Support;

use App\Models\Video;
use CrzSourceResolver\Jobs\RefreshSource;
use CrzSourceResolver\Models\CrozztubeImportSource;

/**
 * Verbindet das PlayHub-Model mit dem Resolver. Von Core-Patches benutzt:
 *  - CrzSourceResolver\Support\VideoUrl::resolved($video)   (Model accessors)
 *  - CrzSourceResolver\Support\VideoSources::forVideo($video) (VideoResource)
 */
final class VideoSources
{
    public static function resolvedUrl(Video $video): ?string
    {
        $resolver = \CrzSourceResolver\Resolver::instance();
        if (! $resolver) {
            return null;
        }

        $row = CrozztubeImportSource::query()->where('video_id', $video->id)->first();
        if (! $row) {
            // noch unbekannt: Refresh anstoßen, keinerlei Direkt-URL liefern -> Embed-Fallback greift
            RefreshSource::request($video->id);

            return null;
        }

        if ($row->audio_tracks === null) {
            // Sources from before language-aware resolution may point at a dub.
            RefreshSource::request($video->id, force: true);

            return null;
        }

        if ($row->isFresh()) {
            return $row->media_url ?: null;
        }

        if ($row->state === 'fresh') {
            // frisch markiert, aber TTL abgelaufen: Refresh triggern
            RefreshSource::request($video->id);

            return null;
        }

        // refreshing/stale/error: keinBlocked Player-Download; Embed-Fallback
        return null;
    }

    /**
     * Quelle-Liste fuer VideoResource::videoSources(): Direkt-URL im eigenen Player
     * bevorzugt; wird leer geliefert, greift der normale PlayHub-Pfad
     * (YouTube-Embed/Mirror).
     */
    public static function forVideo(Video $video): array
    {
        $url = self::resolvedUrl($video);
        if (! $url) {
            return [];
        }

        $row = CrozztubeImportSource::query()->where('video_id', $video->id)->first();
        $isHls = $row !== null && in_array($row->kind, ['hls', 'hls-synth'], true);
        $type = $isHls ? 'application/x-mpegurl' : 'video/mp4';

        return [[
            'src' => $url,
            'type' => $type,
            'quality' => 'original',
        ]];
    }
}
