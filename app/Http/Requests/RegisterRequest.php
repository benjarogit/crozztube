<?php

namespace App\Http\Requests;

use App\Support\AuthSettings;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = preg_replace('/\s+/u', ' ', trim((string) $this->input('name', '')));

        $this->merge([
            'name' => $name ?? '',
            'username' => $this->has('username') ? trim((string) $this->input('username')) : null,
        ]);
    }

    public function rules(): array
    {
        $password = ['required', 'string', 'min:6', 'confirmed'];
        $codeDigits = AuthSettings::registrationCodeDigits();
        $username = AuthSettings::registrationUsernameRequired() ? ['required', 'alpha_dash:ascii', 'regex:/[A-Za-z]/', 'min:4', 'max:30', 'unique:users,username'] : ['nullable', 'alpha_dash:ascii', 'regex:/[A-Za-z]/', 'min:4', 'max:30', 'unique:users,username'];

        return [
            'name' => ['required', 'string', 'max:80', 'regex:/^\p{L}[\p{L}\p{M}]*(?: \p{L}[\p{L}\p{M}]*)*$/u'],
            'username' => $username,
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'phone_country_code' => ['nullable', 'string', 'max:8'],
            'phone_country_iso' => ['nullable', 'string', 'size:2'],
            'email_confirmation_code' => ['nullable', 'string', 'regex:/^\d{'.$codeDigits.'}$/'],
            'phone_confirmation_code' => ['nullable', 'string', 'regex:/^\d{'.$codeDigits.'}$/'],
            'captcha_id' => ['nullable', 'string', 'max:1000'],
            'captcha_answer' => ['nullable', 'string', 'max:40'],
            'google_captcha_token' => ['nullable', 'string', 'max:4000'],
            'device_info' => ['nullable', 'array'],
            'device_info.userAgent' => ['nullable', 'string', 'max:500'],
            'device_info.platform' => ['nullable', 'string', 'max:120'],
            'device_info.language' => ['nullable', 'string', 'max:80'],
            'device_info.timezone' => ['nullable', 'string', 'max:120'],
            'device_info.screen' => ['nullable', 'string', 'max:40'],
            'location_info' => ['nullable', 'array'],
            'location_info.countryIso' => ['nullable', 'string', 'size:2'],
            'location_info.timezone' => ['nullable', 'string', 'max:120'],
            'password' => $password,
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'Name may contain only letters and spaces.',
            'username.regex' => 'Username must include at least one letter.',
        ];
    }
}
