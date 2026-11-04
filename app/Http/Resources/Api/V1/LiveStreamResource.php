<?php

namespace App\Http\Resources\Api\V1;

use App\Services\Api\V1\VideoAccessService;
use App\Support\LiveStreamingSettings;
use App\Support\LiveStreamMedia;
use App\Support\VideoSales;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class LiveStreamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $replay = $this->relationLoaded('replayVideo') ? $this->replayVideo : null;
        $viewer = $request->user();
        if ($replay && (! app(VideoAccessService::class)->canView($replay, $viewer, false)
            || ($this->replay_expires_at?->isPast() && (int) ($viewer?->id ?? 0) !== (int) $this->user_id))) {
            $replay = null;
        }
        $status = match ($this->status) {
            'scheduled' => 'upcoming',
            'ended' => $replay ? 'replay' : 'ended',
            default => (string) $this->status,
        };
        $channel = $this->relationLoaded('channel') ? $this->channel : null;
        $settings = LiveStreamingSettings::settings();
        $chat = (bool) $settings['features']['chat'] && (bool) data_get($this->stream_settings, 'enable_chat', true);
        $polls = (bool) $settings['features']['polls'];
        $followersOnly = (bool) data_get($this->stream_settings, 'followers_only', false);
        $subscribed = $viewer && DB::table('subscriptions')
            ->where('user_id', $viewer->id)->where('channel_id', $this->channel_id)->exists();
        $chatRestricted = $viewer && DB::table('live_stream_restrictions')
            ->where('live_stream_id', $this->id)->where('user_id', $viewer->id)->where('type', 'chat')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
        $canInteract = $viewer && $viewer->tokenCan('live:view') && $this->status === 'live';
        $contributions = collect(['tips', 'super_chat', 'super_stickers', 'gifts'])
            ->contains(fn ($feature) => (bool) $settings['features'][$feature]
                && (bool) data_get($this->monetization_settings, $feature, false));

        return [
            'id' => (int) $this->id,
            'uuid' => (string) $this->uuid,
            'slug' => (string) $this->slug,
            'title' => (string) ($this->title ?: 'Live stream'),
            'description' => (string) ($this->description ?? ''),
            'tags' => array_values(array_map('strval', $this->tags ?: [])),
            'status' => $status,
            'thumbnail_url' => $this->thumbnail_path
                ? LiveStreamMedia::thumbnailUrl($this->resource)
                : ($replay ? VideoSales::thumbnailUrl($replay) : null),
            'viewer_count' => (int) ($this->viewer_count ?? 0),
            'scheduled_at' => optional($this->scheduled_at)->toIso8601String(),
            'started_at' => optional($this->actual_started_at)->toIso8601String(),
            'ended_at' => optional($this->ended_at)->toIso8601String(),
            'category' => $this->relationLoaded('category') && $this->category ? [
                'id' => (int) $this->category->id,
                'slug' => (string) $this->category->slug,
                'name' => (string) $this->category->name,
            ] : null,
            'creator' => $channel ? (new ChannelSummaryResource($channel))->resolve($request) : null,
            'features' => [
                'chat' => $chat,
                'polls' => $polls,
                'contributions' => $contributions,
                'followers_only' => $followersOnly,
                'slow_mode_interval' => data_get($this->stream_settings, 'slow_mode', false)
                    ? max(1, (int) data_get($this->stream_settings, 'slow_mode_interval', $settings['defaults']['slow_mode_interval'])) : 0,
                'dvr' => (bool) data_get($this->stream_settings, 'enable_dvr', false),
                'audience' => (string) data_get($this->stream_settings, 'audience_mode', 'everyone'),
            ],
            'viewer' => [
                'subscribed' => (bool) $subscribed,
                'chat_restricted' => (bool) $chatRestricted,
                'can_chat' => $canInteract && $chat && ! $chatRestricted
                    && (! $followersOnly || $subscribed || (int) $viewer->id === (int) $this->user_id),
                'can_vote' => $canInteract && $polls,
                'can_contribute' => $canInteract && $contributions && (int) $viewer->id !== (int) $this->user_id,
            ],
            'replay' => $replay ? (new VideoCardResource($replay))->resolve($request) : null,
            'links' => [
                'details' => route('api.v1.live.show', ['liveStream' => $this->slug]),
                'viewer_join' => route('api.v1.live.join', ['liveStream' => $this->slug]),
            ],
        ];
    }
}
