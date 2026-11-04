<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWatchProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'uuid'],
            'position_seconds' => ['required', 'numeric', 'min:0', 'max:604800'],
            'duration_seconds' => ['nullable', 'numeric', 'min:1', 'max:604800'],
            'state' => ['nullable', Rule::in(['started', 'playing', 'paused', 'completed', 'restarted'])],
            'occurred_at' => ['nullable', 'date'],
        ];
    }
}
