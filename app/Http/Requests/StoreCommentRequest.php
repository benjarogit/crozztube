<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'min:1'],
            'sticker_id' => ['nullable', function ($attribute, $value, $fail): void {
                if ((! is_int($value) && ! is_string($value)) || (is_string($value) && strlen($value) > \App\Support\KlipyStickers::MAX_SELECTION_LENGTH)) {
                    $fail('Choose a valid KLIPY sticker.');
                }
            }],
            'images' => ['nullable', 'array', 'max:4'],
            'images.*' => ['image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (trim((string) $this->input('body', '')) === '' && ! $this->hasFile('images') && ! $this->filled('sticker_id')) {
                $validator->errors()->add('body', 'Add a comment, sticker, or at least one image.');
            }
        });
    }
}
