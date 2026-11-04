<?php

namespace App\Models;

use App\Services\LiveKitAdmissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LiveStream extends Model
{
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $fillable = [
        'uuid', 'user_id', 'channel_id', 'category_id', 'replay_video_id', 'slug', 'title', 'description', 'tags',
        'visibility', 'status', 'source_type', 'livekit_ingress_id', 'thumbnail_path', 'appearance', 'stream_settings',
        'monetization_settings', 'engagement_settings', 'preflight', 'scheduled_at', 'actual_started_at',
        'ended_at', 'replay_expires_at', 'preflight_tested_at',
    ];

    protected $casts = [
        'tags' => 'array',
        'appearance' => 'array',
        'stream_settings' => 'array',
        'monetization_settings' => 'array',
        'engagement_settings' => 'array',
        'preflight' => 'array',
        'scheduled_at' => 'datetime',
        'actual_started_at' => 'datetime',
        'ended_at' => 'datetime',
        'replay_expires_at' => 'datetime',
        'preflight_tested_at' => 'datetime',
    ];

    protected function performUpdate(Builder $query): bool
    {
        if (! $this->isDirty('stream_settings')) {
            return parent::performUpdate($query);
        }

        // A stale room/settings/recording save must never undo a concurrent kick.
        return $this->getConnection()->transaction(function () use ($query): bool {
            $current = static::query()->whereKey($this->getKey())->lockForUpdate()->first(['id', 'stream_settings']);
            $settings = (array) $this->stream_settings;
            $versions = (array) ($settings[LiveKitAdmissionService::VERSION_SETTINGS] ?? []);
            foreach ((array) data_get($current?->stream_settings, LiveKitAdmissionService::VERSION_SETTINGS, []) as $identity => $version) {
                $versions[$identity] = max((int) ($versions[$identity] ?? 0), (int) $version);
            }
            if ($versions !== []) {
                $settings[LiveKitAdmissionService::VERSION_SETTINGS] = $versions;
            }
            $this->stream_settings = $settings;

            return parent::performUpdate($query);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LiveStreamCategory::class, 'category_id');
    }

    public function replayVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'replay_video_id');
    }

    public function polls(): HasMany
    {
        return $this->hasMany(LiveStreamPoll::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(LiveStreamChatMessage::class);
    }

    public function contributions(): HasMany
    {
        return $this->hasMany(LiveStreamContribution::class);
    }

    public function adImpressions(): HasMany
    {
        return $this->hasMany(LiveStreamAdImpression::class);
    }
}
