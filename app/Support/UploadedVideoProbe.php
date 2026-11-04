<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

class UploadedVideoProbe
{
    /** Probe assembled bytes, never the mobile client's claimed MIME/dimensions. */
    public function inspect(string $path): array
    {
        $process = new Process([MediaBinaries::ffprobe(), '-v', 'error', '-protocol_whitelist', 'file,pipe', '-format_whitelist', 'mov,matroska,webm', '-show_format', '-show_streams', '-of', 'json', $path]);
        $process->setTimeout(60);
        $process->run();
        $data = json_decode($process->getOutput(), true);
        $stream = collect($data['streams'] ?? [])->first(fn ($item) => ($item['codec_type'] ?? '') === 'video' && ! ($item['disposition']['attached_pic'] ?? false));
        $duration = (float) ($data['format']['duration'] ?? $stream['duration'] ?? 0);
        $formats = explode(',', (string) ($data['format']['format_name'] ?? ''));
        if (! $process->isSuccessful() || ! $stream || $duration <= 0 || ! array_intersect($formats, ['mov', 'mp4', 'm4a', '3gp', '3g2', 'mj2', 'matroska', 'webm'])) {
            throw ValidationException::withMessages(['file' => 'Select a valid MP4, MOV, M4V, WebM, or MKV video.']);
        }
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        if ($width < 1 || $height < 1) {
            throw ValidationException::withMessages(['file' => 'The selected video has no valid video dimensions.']);
        }
        $rotation = (int) (collect($stream['side_data_list'] ?? [])->firstWhere('side_data_type', 'Display Matrix')['rotation'] ?? $stream['tags']['rotate'] ?? 0);
        if (abs($rotation) % 180 === 90) {
            [$width, $height] = [$height, $width];
        }

        return ['duration_seconds' => (int) ceil($duration), 'video_width' => $width, 'video_height' => $height];
    }
}
