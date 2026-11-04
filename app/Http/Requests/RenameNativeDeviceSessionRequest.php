<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RenameNativeDeviceSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_name' => ['required', 'string', 'max:120'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['device_name' => trim((string) $this->input('device_name'))]);
    }
}
