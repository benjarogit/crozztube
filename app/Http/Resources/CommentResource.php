<?php

namespace App\Http\Resources;

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
        if (is_iterable($resource)) self::prefetchAttachments($resource);
        return parent::collection($resource);
    }

    /** Batch the whole loaded thread, including any eagerly loaded nested replies. */
    public static function prefetchAttachments(iterable $comments): void
    {
        $pending = [];
        foreach ($comments as $comment) $pending[] = $comment;
        $seen = [];
        $attachments = [];
        for ($index = 0; $index < count($pending); $index++) {
            $comment = $pending[$index];
            if (! $comment instanceof \App\Models\Comment || isset($seen[spl_object_id($comment)])) continue;
            $seen[spl_object_id($comment)] = true;
            array_push($attachments, ...($comment->attachments ?: []));
            if ($comment->relationLoaded('replies')) array_push($pending, ...$comment->replies->all());
        }
        ImageUploads::prefetch($attachments);
    }

    public function toArray(Request $request): array
    {
        self::prefetchAttachments([$this->resource]);
        return [
            'id' => $this->id,
            'body' => $this->body,
            'attachments' => collect($this->attachments ?: [])->map(function ($attachment, int $index): array {
                if (is_array($attachment) && ! empty($attachment['image_id'])) {
                    return ImageUploads::payload($attachment);
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
                    'mime' => $attachment['mime'] ?? null,
                    'size' => isset($attachment['size']) ? (int) $attachment['size'] : null,
                ], static fn ($value): bool => $value !== null);
            })->filter()->values()->all(),
            'sticker' => $this->when($this->relationLoaded('sticker') && $this->sticker, fn () => [
                'id' => $this->sticker->id,
                'name' => $this->sticker->name,
                'image_url' => KlipyStickers::imageUrl($this->sticker->image_path),
                'provider' => $this->sticker->provider,
            ]),
            'likes_count' => $this->likes_count,
            'replies_count' => (int) ($this->replies_count ?? ($this->relationLoaded('replies') ? $this->replies->count() : 0)),
            'pinned' => (bool) $this->is_pinned,
            'pinned_at' => optional($this->pinned_at)->toIso8601String(),
            'created_at' => optional($this->created_at)->toIso8601String(),
            'updated_at' => optional($this->updated_at)->toIso8601String(),
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'replies' => CommentResource::collection($this->whenLoaded('replies')),
            'is_liked' => $this->when(isset($this->is_liked), (bool) $this->is_liked),
            'reported' => $this->when(isset($this->reported), (bool) $this->reported),
            'can_delete' => $this->when(isset($this->can_delete), (bool) $this->can_delete),
            'can_pin' => $this->when(isset($this->can_pin), (bool) $this->can_pin),
            'can_report' => $this->when(isset($this->can_report), (bool) $this->can_report),
            'can_mute' => $this->when(isset($this->can_mute), (bool) $this->can_mute),
        ];
    }
}
