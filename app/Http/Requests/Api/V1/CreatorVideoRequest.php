<?php

namespace App\Http\Requests\Api\V1;

use App\Support\VideoChapters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatorVideoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->id === $this->route('video')?->user_id;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:160'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'playlist_title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['required', 'string', 'max:100'],
            'category_id' => ['sometimes', 'required', 'integer', Rule::exists('video_categories', 'id')],
            'language_id' => ['sometimes', 'nullable', 'integer', Rule::exists('video_languages', 'id')],
            'visibility' => ['sometimes', Rule::in(['public', 'unlisted', 'private'])],
            'scheduled_at' => ['sometimes', 'nullable', 'date', 'after:now'],
            'publish_as_draft' => ['sometimes', 'boolean'],
            'made_for_kids' => ['sometimes', 'boolean'],
            'monetization_enabled' => ['sometimes', 'boolean'],
            ...VideoChapters::rules(),
        ];
    }
}
