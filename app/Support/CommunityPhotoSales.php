<?php

namespace App\Support;

use App\Models\CommunityPost;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class CommunityPhotoSales
{
    public static function isPaid(CommunityPost $post): bool
    {
        return $post->type === 'image'
            && (bool) $post->sale_enabled
            && (int) $post->price_cents > 0
            && collect($post->attachments ?: [])->contains(fn ($attachment): bool => is_array($attachment) && ($attachment['type'] ?? null) === 'image');
    }

    public static function viewer(Request $request): ?User
    {
        $user = $request->user() ?: auth('sanctum')->user();

        return $user instanceof User ? $user : null;
    }

    public static function hasAccess(CommunityPost $post, ?User $user): bool
    {
        if (! self::isPaid($post)) return true;
        if ($user && ((int) $post->user_id === (int) $user->id || self::isAdmin($user))) return true;

        return $user && Schema::hasTable('community_photo_purchases') && DB::table('community_photo_purchases')
            ->where('buyer_id', $user->id)
            ->where('community_post_id', $post->id)
            ->where('status', 'completed')
            ->exists();
    }

    public static function payload(CommunityPost $post, Request $request): array
    {
        $user = self::viewer($request);
        $paid = self::isPaid($post);
        $privileged = $user && ((int) $post->user_id === (int) $user->id || self::isAdmin($user));
        $purchased = $paid && ! $privileged && self::hasAccess($post, $user);
        $access = ! $paid || $privileged || $purchased;
        $settings = MonetizationSettings::paidCommunityPhotoSettings();
        $priceCents = $paid ? (int) $post->price_cents : 0;
        $tax = VideoSales::taxBreakdown($priceCents, $settings);

        return [
            'is_paid' => $paid,
            'price_cents' => $priceCents,
            'price' => number_format($priceCents / 100, 2, '.', ''),
            'price_label' => $paid ? IntegrationSettings::formatMoney($priceCents, $settings['currency']) : null,
            'currency' => $settings['currency'],
            'minimum_price' => $settings['minimum_price'],
            'maximum_price' => $settings['maximum_price'],
            'can_configure' => (bool) $settings['enabled'],
            'tax' => $tax,
            'total_cents' => $tax['total_cents'],
            'total_label' => $paid ? $tax['total_label'] : null,
            'photo_count' => collect($post->attachments ?: [])->where('type', 'image')->count(),
            'has_access' => $access,
            'is_purchased' => (bool) $purchased,
            'purchase_available' => $paid && ! $access && (bool) $settings['enabled'],
        ];
    }

    public static function pricing(bool $enabled, mixed $price, bool $eligible): array
    {
        if (! $enabled) return ['sale_enabled' => false, 'price_cents' => null];

        $settings = MonetizationSettings::paidCommunityPhotoSettings();
        abort_unless($settings['enabled'], 403, 'Paid photo sales are disabled by the administrator.');
        abort_unless($eligible, 422, 'Only community image posts can be sold.');

        $priceCents = (int) round(((float) $price) * 100);
        abort_if($priceCents < $settings['minimum_price_cents'] || $priceCents > $settings['maximum_price_cents'], 422, 'Photo price is outside the allowed range.');

        return ['sale_enabled' => true, 'price_cents' => $priceCents];
    }

    public static function attachmentUrl(CommunityPost $post, int $index): string
    {
        return URL::temporarySignedRoute('media.community-photo', now()->addHours(6), [
            'communityPost' => $post->id,
            'index' => $index,
        ]);
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
}
