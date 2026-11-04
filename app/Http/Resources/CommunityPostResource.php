<?php

namespace App\Http\Resources;

use App\Models\CommunityPost;
use App\Support\CommunityPhotoSales;
use App\Support\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isAuthor = $user && (int) $user->id === (int) $this->user_id;
        $isCommunityOwner = $user && $this->relationLoaded('channel') && (int) $user->id === (int) $this->channel?->user_id;
        $canEdit = (bool) ($this->can_edit ?? $this->can_manage ?? $isAuthor);
        $canDelete = (bool) ($this->can_delete ?? ($canEdit || $isCommunityOwner || $this->canModeratePosts($user)));
        $sale = CommunityPhotoSales::payload($this->resource, $request);
        $attachments = collect($this->attachments ?: [])->map(function (array $attachment, int $index) use ($sale): array {
            if (($attachment['type'] ?? null) === 'image' && filled($attachment['path'] ?? null)) {
                unset($attachment['path']);
                $attachment['url'] = $sale['is_paid']
                    ? ($sale['has_access'] ? CommunityPhotoSales::attachmentUrl($this->resource, $index) : null)
                    : route('media.community-photo', ['communityPost' => $this->id, 'index' => $index]);
                $attachment['locked'] = ! $sale['has_access'];

                return $attachment;
            }

            return $attachment;
        })->values()->all();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'body' => $this->body,
            'hashtags' => CommunityPost::hashtagLabels($this->body),
            'url' => $this->url,
            'url_preview' => $this->url ? [
                'url' => $this->url,
                'title' => $this->url_title ?: parse_url($this->url, PHP_URL_HOST),
                'description' => $this->url_description,
                'image' => $this->url_image,
                'icon' => $this->metadata['url_icon'] ?? $this->fallbackIconUrl($this->url),
            ] : null,
            'metadata' => $this->publicMetadata(),
            'attachments' => $attachments,
            'sale' => $sale,
            'poll_options' => $this->pollOptions(),
            'user_poll_vote' => $this->when(isset($this->user_poll_vote), $this->user_poll_vote),
            'likes_count' => $this->likes_count,
            'comments_count' => $this->comments_count,
            'shares_count' => $this->shares_count,
            'votes_count' => $this->votes_count,
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'can_manage' => $canEdit,
            'can_edit' => $canEdit,
            'can_delete' => $canDelete,
            'author' => [
                'id' => $this->channel?->id,
                'user_id' => (int) $this->user_id,
                'name' => $this->channel?->name ?: $this->user?->name,
                'slug' => $this->channel?->slug,
                'avatar_url' => $this->assetUrl($this->channel?->avatar_path ?: $this->user?->avatar_path),
                'verified' => (bool) $this->user?->verified_at,
            ],
            'video' => new VideoResource($this->whenLoaded('video')),
            'is_liked' => $this->when(isset($this->is_liked), (bool) $this->is_liked),
            'is_bookmarked' => $this->when(isset($this->is_bookmarked), (bool) $this->is_bookmarked),
        ];
    }

    private function pollOptions(): array
    {
        $options = collect($this->metadata['options'] ?? []);
        $total = max(0, (int) $this->votes_count);

        return $options->map(function (array $option, int $index) use ($total): array {
            $votes = (int) ($option['votes'] ?? 0);

            return [
                'index' => $index,
                'label' => (string) ($option['label'] ?? ''),
                'votes' => $votes,
                'percent' => $total > 0 ? (int) round(($votes / $total) * 100) : 0,
            ];
        })->values()->all();
    }

    private function canModeratePosts(?object $user): bool
    {
        if (! $user) return false;
        $settings = is_array($user->settings ?? null) ? $user->settings : [];
        $role = strtolower(str_replace(['_', '-'], ' ', (string) ($settings['role'] ?? $settings['account_role'] ?? '')));
        $admin = $settings['admin'] ?? false;
        $adminEnabled = is_array($admin) ? (bool) ($admin['enabled'] ?? false) : (bool) $admin;

        return $adminEnabled || (bool) ($settings['is_admin'] ?? false) || in_array($role, ['admin', 'super admin', 'owner', 'moderator'], true);
    }

    private function publicMetadata(): ?array
    {
        $metadata = $this->metadata ?: [];

        if ($this->type === 'event') {
            return [
                'title' => $metadata['title'] ?? null,
                'starts_at' => $metadata['starts_at'] ?? null,
                'ends_at' => $metadata['ends_at'] ?? null,
                'timezone' => $metadata['timezone'] ?? null,
                'location' => $metadata['location'] ?? null,
                'url' => $metadata['url'] ?? null,
            ];
        }

        if ($this->type === 'cover_update') {
            return [
                'cover_position_y' => $metadata['cover_position_y'] ?? null,
            ];
        }

        return null;
    }

    private function assetUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        if (preg_match('/^(https?:)?\/\//', $path) || str_starts_with($path, '/')) {
            return url($path);
        }

        return MediaStorage::url($path);
    }

    private function fallbackIconUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(! empty($parts['port']) ? ':'.$parts['port'] : '').'/favicon.ico';
    }
}
