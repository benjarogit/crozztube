<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\UsesNativeClientContext;
use Illuminate\Validation\Rule;

class NativeRegisterRequest extends RegisterRequest
{
    use UsesNativeClientContext;

    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'platform' => ['required', 'string', Rule::in((array) config('native_auth.mobile_platforms', []))],
            'device_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9A-Za-z.-]+)?$/'],
            'os_version' => ['nullable', 'string', 'max:80'],
        ]);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->mergeNativeClientContext();
    }
}
