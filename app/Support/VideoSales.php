<?php

namespace App\Support;

use App\Models\User;
use App\Models\Video;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class VideoSales
{
    public static function isPaid(Video $video): bool
    {
        return ! $video->isShort() && (bool) $video->sale_enabled && (int) $video->price_cents > 0;
    }

    public static function viewer(Request $request): ?User
    {
        $user = $request->user() ?: auth('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    public static function hasAccess(Video $video, ?User $user): bool
    {
        if (! self::isPaid($video)) {
            return true;
        }

        if ($user && ((int) $video->user_id === (int) $user->id || self::isAdmin($user))) {
            return true;
        }

        return self::hasPurchased($video, $user);
    }

    public static function canViewPrivate(Video $video, ?User $user): bool
    {
        return $user && ((int) $video->user_id === (int) $user->id || self::isAdmin($user));
    }

    public static function payload(Video $video, Request $request): array
    {
        $user = self::viewer($request);
        $paid = self::isPaid($video);
        $privileged = $user && ((int) $video->user_id === (int) $user->id || self::isAdmin($user));
        $purchased = $paid && ! $privileged && self::hasPurchased($video, $user);
        $access = ! $paid || $purchased || $privileged;
        $settings = $paid ? MonetizationSettings::paidVideoSettings() : null;
        $priceCents = $paid ? (int) $video->price_cents : 0;
        $tax = self::taxBreakdown($priceCents, $settings ?: ['currency' => 'usd']);

        return [
            'is_paid' => $paid,
            'price_cents' => $priceCents,
            'price' => number_format($priceCents / 100, 2, '.', ''),
            'price_label' => $paid ? IntegrationSettings::formatMoney($priceCents, $settings['currency']) : null,
            'tax' => $tax,
            'total_cents' => $tax['total_cents'],
            'total_label' => $paid ? $tax['total_label'] : null,
            'has_access' => $access,
            'is_purchased' => (bool) $purchased,
            'purchase_available' => $paid && ! $access && (bool) $settings['enabled'] && $video->visibility === 'public' && $video->status === 'ready',
        ];
    }

    public static function streamUrl(Video $video, ?string $quality = null, ?DateTimeInterface $expiresAt = null): string
    {
        $version = $video->mediaVersion($quality);
        if (! self::requiresSignedMedia($video)) {
            return url('/media/videos/'.$video->id.'/stream'.($quality ? '/'.$quality : '')).'?v='.$version;
        }

        return URL::temporarySignedRoute('media.video', $expiresAt ?? now()->addHours(6), array_filter([
            'video' => $video->id,
            'quality' => $quality,
            'v' => $version,
            ...self::privateMediaViewerClaims($video),
        ], fn ($value): bool => $value !== null));
    }

    public static function captionsUrl(Video $video, ?DateTimeInterface $expiresAt = null, ?int $track = null, bool $transcript = false): ?string
    {
        if (! $video->captions_path && VideoCaptions::stored($video) === []) {
            return null;
        }

        $parameters = ['video' => $video->id, 'v' => $video->mediaVersion()];
        if ($track !== null) $parameters['track'] = $track;
        if ($transcript) $parameters['transcript'] = 1;

        if (! self::requiresSignedMedia($video)) {
            return route('media.video-captions', $parameters);
        }

        return URL::temporarySignedRoute('media.video-captions', $expiresAt ?? now()->addHours(6), [
            ...$parameters,
            ...self::privateMediaViewerClaims($video),
        ]);
    }

    public static function thumbnailUrl(Video $video, ?DateTimeInterface $expiresAt = null): ?string
    {
        $candidates = $video->thumbnail_candidates;
        if ($candidates !== []) return $candidates[0];
        if (! $video->thumbnail_path) {
            return null;
        }

        if ($video->visibility === 'public' && ! self::ownerHasPrivateProfile($video)) {
            return $video->thumbnail_url;
        }

        return URL::temporarySignedRoute('media.thumbnail', $expiresAt ?? now()->addHours(6), [
            'video' => $video->id,
            'v' => substr(hash('sha256', $video->thumbnail_path), 0, 12),
            ...self::privateMediaViewerClaims($video),
        ]);
    }

    public static function mediaExpiresAt(Video $video): ?string
    {
        return self::requiresSignedMedia($video) ? now()->addHours(6)->toIso8601String() : null;
    }

    public static function requiresSignedMedia(Video $video): bool
    {
        return self::isPaid($video) || $video->visibility !== 'public' || self::ownerHasPrivateProfile($video);
    }

    private static function ownerHasPrivateProfile(Video $video): bool
    {
        $owner = $video->relationLoaded('user') ? $video->user : null;
        if (! $owner && $video->relationLoaded('channel') && $video->channel?->relationLoaded('user')) {
            $owner = $video->channel->user;
        }
        if (! $owner) {
            $video->loadMissing('user');
            $owner = $video->user;
        }

        return ! $owner?->hasPublicProfileEnabled();
    }

    private static function privateMediaViewerClaims(Video $video): array
    {
        $privateProfile = self::ownerHasPrivateProfile($video);
        if ($video->visibility !== 'private' && ! $privateProfile) {
            return [];
        }

        $viewer = self::viewer(request());

        return self::canViewPrivate($video, $viewer)
            ? ['private_viewer' => $viewer->id, ...($privateProfile ? ['profile_owner' => $video->user_id] : [])]
            : [];
    }

    public static function pricing(bool $enabled, mixed $price, bool $eligible = true): array
    {
        if (! $enabled) {
            return ['sale_enabled' => false, 'price_cents' => null];
        }

        $settings = MonetizationSettings::paidVideoSettings();
        abort_unless($settings['enabled'], 403, 'Paid video sales are disabled by the administrator.');
        abort_unless($eligible, 422, 'Only standard uploaded videos can be sold.');

        $priceCents = (int) round(((float) $price) * 100);
        abort_if($priceCents < $settings['minimum_price_cents'] || $priceCents > $settings['maximum_price_cents'], 422, 'Video price is outside the allowed range.');

        return ['sale_enabled' => true, 'price_cents' => $priceCents];
    }

    public static function taxBreakdown(int $subtotalCents, ?array $settings = null): array
    {
        $settings ??= MonetizationSettings::paidVideoSettings();
        $tax = is_array($settings['sales_tax'] ?? null) ? $settings['sales_tax'] : [];
        $rate = max(0, min(100, (float) ($tax['rate_percent'] ?? 0)));
        $enabled = (bool) ($tax['enabled'] ?? false) && $rate > 0 && $subtotalCents > 0;
        $taxCents = $enabled ? (int) round($subtotalCents * ($rate / 100)) : 0;
        $currency = (string) ($settings['currency'] ?? 'usd');

        return [
            'enabled' => $enabled,
            'type' => $enabled ? (string) ($tax['type'] ?? 'vat') : null,
            'label' => $enabled ? (string) ($tax['label'] ?? 'VAT') : null,
            'rate_percent' => $enabled ? $rate : 0,
            'amount_cents' => $taxCents,
            'amount_label' => $enabled ? IntegrationSettings::formatMoney($taxCents, $currency) : null,
            'total_cents' => $subtotalCents + $taxCents,
            'total_label' => IntegrationSettings::formatMoney($subtotalCents + $taxCents, $currency),
        ];
    }

    private static function isAdmin(User $user): bool
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;

        return (is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin)
            || (bool) ($settings['is_admin'] ?? false)
            || in_array($role, ['admin', 'super admin', 'owner'], true);
    }

    private static function hasPurchased(Video $video, ?User $user): bool
    {
        return $user && Schema::hasTable('video_purchases') && DB::table('video_purchases')
            ->where('buyer_id', $user->id)
            ->where('video_id', $video->id)
            ->where('status', 'completed')
            ->exists();
    }
}
