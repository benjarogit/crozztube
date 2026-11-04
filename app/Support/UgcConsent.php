<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Optional legacy receipts; publishing never requires policy acceptance. */
class UgcConsent
{
    public static function policy(): array
    {
        $material = ContentPages::safetyPolicyMaterial();
        $unavailable = [];
        foreach (['terms', 'privacy'] as $key) {
            $page = $material[$key] ?? [];
            $resolved = ContentPages::page($key, null, true);
            $sourceLocale = $resolved['source_locale'] ?? null;
            if (! is_array($page) || ! is_string($page['updated_at'] ?? null) || trim($page['updated_at']) === '' || empty($page['locales'])) {
                $unavailable[$key] = 'not_published';
            } elseif (($page['enabled'] ?? false) !== true) {
                $unavailable[$key] = 'disabled';
            } elseif (! is_string($sourceLocale) || ! ContentPages::completeLegalContent($page['locales'][$sourceLocale] ?? null)) {
                $unavailable[$key] = 'incomplete';
            }
            $material[$key] = is_array($page) ? $page : [];
            $material[$key]['resolved_source_locale'] = $sourceLocale;
        }
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($canonical, $value);
        };
        $origin = rtrim((string) config('app.url'), '/');

        return [
            'version' => hash('sha256', json_encode($canonical([$origin, $material]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'terms_url' => $origin.'/terms',
            'privacy_url' => $origin.'/privacy',
            'available' => $unavailable === [],
            'unavailable_documents' => array_keys($unavailable),
            'unavailable_reasons' => $unavailable,
        ];
    }

    public static function status(User $user): array
    {
        $policy = self::policy();
        $receipts = Schema::hasTable('user_ugc_consents') ? DB::table('user_ugc_consents')->where('user_id', $user->id) : null;
        $accepted = $policy['available'] && $receipts && (clone $receipts)->where('policy_version', $policy['version'])->exists();

        return ['policy' => $policy, 'consent' => [
            'required' => false, 'accepted' => (bool) $accepted,
            'accepted_version' => $accepted ? $policy['version'] : ($receipts ? $receipts->orderByDesc('id')->value('policy_version') : null),
        ]];
    }

    public static function accept(User $user, string $version): array
    {
        $policy = self::policy();
        if (! $policy['available'] || ! Schema::hasTable('user_ugc_consents') || ! hash_equals($policy['version'], $version)) {
            throw ValidationException::withMessages(['policy_version' => ['The published policy changed or is unavailable. Review it again before accepting.']]);
        }
        DB::table('user_ugc_consents')->insertOrIgnore(['user_id' => $user->id, 'policy_version' => $version, 'accepted_at' => now()]);

        return self::status($user);
    }
}
