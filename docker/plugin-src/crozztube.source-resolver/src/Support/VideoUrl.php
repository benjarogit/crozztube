<?php

namespace CrzSourceResolver\Support;

use App\Models\Video;

/**
 * Alias-Entry, wie vom Core-Patch in app/Models/Video.php genutzt:
 * \CrzSourceResolver\Support\VideoUrl::resolved($video)
 */
final class VideoUrl
{
    public static function resolved(Video $video): ?string
    {
        return VideoSources::resolvedUrl($video);
    }
}
