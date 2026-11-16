<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * Canonical report reasons for videos, comments and profiles.
 *
 * The keys are stored in the report tables; labels are resolved for the admin
 * queue so renaming a label never rewrites existing rows.
 */
final class ReportReasons
{
    public const VIDEO = 'video';
    public const COMMENT = 'comment';
    public const PROFILE = 'profile';

    private const REASONS = [
        self::VIDEO => [
            'spam' => 'Spam or misleading',
            'abuse' => 'Abusive or harmful content',
            'violence' => 'Violence or graphic content',
            'copyright' => 'Copyright infringement',
            'other' => 'Something else',
        ],
        self::COMMENT => [
            'spam' => 'Spam',
            'harassment' => 'Harassment or bullying',
            'hate' => 'Hate speech',
            'sexual' => 'Sexual content',
            'other' => 'Something else',
        ],
        self::PROFILE => [
            'impersonation' => 'Impersonation',
            'spam' => 'Spam',
            'harassment' => 'Harassment or bullying',
            'other' => 'Something else',
        ],
    ];

    /** @return list<string> */
    public static function keys(string $type): array
    {
        return array_keys(self::REASONS[$type] ?? []);
    }

    public static function label(?string $type, ?string $reason): ?string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            return null;
        }

        return self::REASONS[(string) $type][$reason] ?? null;
    }

    public static function adminLabel(string $type, ?string $reason): string
    {
        return self::label($type, $reason) ?? 'No reason given';
    }

    /**
     * Validation rules shared by every report endpoint.
     *
     * A reason is required because reviewers need to know what to look for;
     * the free-text description stays optional and is capped at 500 characters.
     */
    public static function rules(string $type): array
    {
        return [
            'reason' => ['required', 'string', Rule::in(self::keys($type))],
            'details' => ['nullable', 'string', 'max:500'],
        ];
    }

    public static function details(?string $details): ?string
    {
        $details = trim((string) $details);

        return $details === '' ? null : mb_substr($details, 0, 500);
    }
}
