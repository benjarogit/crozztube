<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->id === $this->route('video')?->user_id;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['nullable', 'integer', Rule::exists('video_categories', 'id')],
            'language_id' => ['nullable', 'integer', Rule::exists('video_languages', 'id')],
            'playlist_title' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'made_for_kids' => ['nullable', 'boolean'],
            'monetization_enabled' => ['nullable', 'boolean'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'thumbnail' => ['nullable', 'image', 'max:5120'],
        ];
    }
}
