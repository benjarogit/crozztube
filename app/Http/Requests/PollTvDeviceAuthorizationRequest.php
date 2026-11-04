<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PollTvDeviceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_code' => ['required', 'string', 'min:40', 'max:255'],
        ];
    }
}
