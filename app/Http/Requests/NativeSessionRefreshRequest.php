<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UsesNativeClientContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NativeSessionRefreshRequest extends FormRequest
{
    use UsesNativeClientContext;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $platforms = array_merge(
            (array) config('native_auth.mobile_platforms', []),
            (array) config('native_auth.tv_platforms', []),
        );

        return [
            'refresh_token' => ['required', 'string', 'max:160', 'regex:/^[0-9a-fA-F-]{36}\.[A-Za-z0-9_-]{64}$/'],
            'platform' => ['required', 'string', Rule::in($platforms)],
            'device_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/'],
            'os_version' => ['nullable', 'string', 'max:80'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->mergeNativeClientContext();
    }
}
