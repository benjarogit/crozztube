<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePlatformAppVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $settings = is_array($this->user()?->settings) ? $this->user()->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;

        return (is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin)
            || (bool) ($settings['is_admin'] ?? false)
            || in_array($role, ['admin', 'super admin', 'owner'], true);
    }

    public function rules(): array
    {
        return [
            'latest_version' => ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9a-z.-]+)?$/i'],
            'minimum_version' => ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){0,3}(?:[-+][0-9a-z.-]+)?$/i'],
            'supported' => ['required', 'boolean'],
            'update_url' => ['nullable', 'url:http,https', 'max:2048'],
            'release_notes_url' => ['nullable', 'url:http,https', 'max:2048'],
            'update_message' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $latest = (string) $this->input('latest_version');
            $minimum = (string) $this->input('minimum_version');
            $valid = static fn (string $version): bool => strlen($version) <= 40
                && preg_match('/^\d+(?:\.\d+){0,3}(?:[-+][0-9a-z.-]+)?$/i', $version) === 1;
            if ($valid($latest) && $valid($minimum) && version_compare($minimum, $latest, '>')) {
                $validator->errors()->add('minimum_version', 'The minimum version cannot be newer than the latest version.');
            }
        });
    }
}
