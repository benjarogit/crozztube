<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveTvDeviceAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_code' => ['required', 'string', 'max:16', 'regex:/^[A-Za-z0-9 -]+$/'],
        ];
    }
}
