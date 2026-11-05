<?php

namespace App\Http\Resources\Api\V1;

use App\Support\KlipyStickers;
use App\Support\Images\ImageUploads;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\URL;

class CommentResource extends JsonResource
{
    public static function collection($resource): AnonymousResourceCollection
    {
        if (is_iterable($resource)) \App\Http\Resources\CommentResource::prefetchAttachments($resource);
        return parent::collection($resource);
    }

    public function toArray(Request $request): array
    {
        \App\Http\Resources\CommentResource::prefetchAttachments([$this->resource]);
        return [
            'id' => (int) $this->id,
            'parent_id' => $this->parent_id ? (int) $this->parent_id : null,
            'body' => (string) ($this->body ?? ''),
            'attachments' => collect($this->attachments ?: [])->map(function ($attachment, int $index): array {
                if (is_array($attachment) && ! empty($attachment['image_id'])) {
                    $payload = ImageUploads::payload($attachment);
                    return $payload ? array_merge($payload, [
                        'mime_type' => $payload['mime'] ?? null,
                        'size_bytes' => $payload['size'] ?? null,
                    ]) : [];
                }
                if (! is_array($attachment) || ($attachment['type'] ?? null) !== 'image' || empty($attachment['path'])) {
                    return [];
                }

                return array_filter([
                    'type' => 'image',
                    'url' => URL::temporarySignedRoute('media.comment-attachment', now()->addHours(6), [
                        'comment' => $this->getKey(),
                        'index' => $index,
                    ]),
                    'name' => $attachment['name'] ?? null,
                    'mime_type' => $attachment['mime'] ?? null,
                    'size_bytes' => isset($attachment['size']) ? (int) $attachment['size'] : null,
                ], static fn ($value): bool => $value !== null);
            })->filter()->values()->all(),
            'sticker' => $this->relationLoaded('sticker') && $this->sticker ? [
                'id' => (int) $this->sticker->id,
                'name' => (string) $this->sticker->name,
                'image_url' => KlipyStickers::imageUrl($this->sticker->image_path),
                'provider' => $this->sticker->provider,
            ] : null,
            'likes_count' => (int) $this->likes_count,
            'replies_count' => (int) ($this->replies_count ?? 0),
            'pinned' => (bool) $this->is_pinned,
            'pinned_at' => $this->pinned_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'author' => $this->relationLoaded('user') && $this->user
                ? (new UserPublicResource($this->user))->resolve($request)
                : null,
            'viewer' => [
                'liked' => (bool) ($this->viewer_liked ?? false),
                'reported' => (bool) ($this->viewer_reported ?? false),
                'can_update' => (bool) ($this->viewer_can_update ?? false),
                'can_delete' => (bool) ($this->viewer_can_delete ?? false),
                'can_pin' => (bool) ($this->viewer_can_pin ?? false),
                'can_report' => (bool) ($this->viewer_can_report ?? false),
            ],
        ];
    }
}
