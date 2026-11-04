<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['nullable', 'string', 'max:190'],
            'login' => ['nullable', 'string', 'max:190'],
            'password' => ['required', 'string'],
            'two_factor_code' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'captcha_id' => ['nullable', 'string', 'max:1000'],
            'captcha_answer' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (trim((string) ($this->input('login') ?: $this->input('email'))) === '') {
                $validator->errors()->add('login', 'A login identifier is required.');
            }
        });
    }
}
