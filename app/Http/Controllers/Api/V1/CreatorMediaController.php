<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Support\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CreatorMediaController extends Controller
{
    public function thumbnail(Request $request, Video $video, CreatorController $creator): JsonResponse
    {
        $this->owner($request, $video);
        $request->validate(['thumbnail' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=120,min_height=90,max_width=8192,max_height=8192']]);
        $path = $request->file('thumbnail')->store('videos/thumbnails/'.now()->format('Y/m'), MediaStorage::diskName());
        abort_unless($path, 500, 'The thumbnail could not be saved.');
        $old = $video->thumbnail_path;
        $video->update(['thumbnail_path' => $path]);
        if ($old && ! preg_match('#^https?://#i', $old)) {
            MediaStorage::delete($old);
        }

        return $creator->show($request, $video->fresh());
    }

    public function caption(Request $request, Video $video, CreatorController $creator): JsonResponse
    {
        $this->owner($request, $video);
        abort_if($video->source_type === 'import', 422, 'Captions for imported videos are managed by their provider.');
        $data = $request->validate([
            'caption' => ['required', 'file', 'max:5120'],
            'language' => ['required', 'string', 'max:35', 'regex:/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/'],
            'label' => ['required', 'string', 'max:80'], 'default' => ['sometimes', 'boolean'],
        ]);
        $file = $request->file('caption');
        $extension = strtolower($file->getClientOriginalExtension());
        $text = (string) file_get_contents($file->getRealPath());
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        abort_unless(in_array($extension, ['srt', 'vtt'], true) && mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0") && preg_match('/\d{2}:\d{2}[.,]\d{3}\s+-->\s+/', $text), 422, 'Choose a valid UTF-8 WebVTT or SRT caption file.');
        if ($extension === 'srt') {
            $text = "WEBVTT\n\n".preg_replace('/(\d{2}:\d{2}:\d{2}),(\d{3})/', '$1.$2', $text);
        }
        abort_unless(str_starts_with(ltrim($text), 'WEBVTT'), 422, 'The caption file must have a WEBVTT header.');
        $path = 'videos/captions/'.now()->format('Y/m').'/'.Str::uuid().'.vtt';
        abort_unless(MediaStorage::put($path, $text), 500, 'The captions could not be saved.');
        $old = $video->captions_path;
        $video->update(['captions_path' => $path, 'caption_metadata' => ['label' => $data['label'], 'language' => $data['language'], 'default' => $request->boolean('default')]]);
        if ($old && ! preg_match('#^https?://#i', $old)) {
            MediaStorage::delete($old);
        }

        return $creator->show($request, $video->fresh());
    }

    public function removeCaption(Request $request, Video $video, CreatorController $creator): JsonResponse
    {
        $this->owner($request, $video);
        abort_if($video->source_type === 'import', 422, 'Captions are managed by the provider.');
        $old = $video->captions_path;
        $video->update(['captions_path' => null, 'caption_metadata' => null]);
        if ($old && ! preg_match('#^https?://#i', $old)) {
            MediaStorage::delete($old);
        }

        return $creator->show($request, $video->fresh());
    }

    private function owner(Request $request, Video $video): void
    {
        abort_unless((int) $request->user()->id === (int) $video->user_id, 404);
        abort_if($video->status === 'processing', 409, 'Wait for processing to finish before changing media assets.');
    }
}
