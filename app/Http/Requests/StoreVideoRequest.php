<?php

namespace App\Http\Requests;

use App\Support\VideoChapters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $maxKb = config('playhub.max_upload_mb', 2048) * 1024;

        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'integer', Rule::exists('video_categories', 'id')],
            'language_id' => ['nullable', 'integer', Rule::exists('video_languages', 'id')],
            'playlist_id' => ['nullable', 'integer', Rule::exists('playlists', 'id')->where(fn ($query) => $query->where('user_id', $this->user()->id))],
            'playlist_title' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'made_for_kids' => ['nullable', 'boolean'],
            'monetization_enabled' => ['nullable', 'boolean'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'content_type' => ['nullable', Rule::in(['video', 'short'])],
            'video_width' => ['nullable', 'integer', 'min:1'],
            'video_height' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'video' => ['required', 'file', 'mimetypes:video/mp4,video/quicktime,video/x-msvideo,video/x-matroska,video/webm', 'max:'.$maxKb],
            'thumbnail' => ['nullable', 'image', 'max:5120'],
            'captions' => ['nullable', 'file', 'mimes:srt,vtt,txt', 'max:5120'],
            ...VideoChapters::rules(),
        ];
    }
}
