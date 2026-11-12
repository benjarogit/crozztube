<?php

namespace App\Models;

use App\Support\VideoImportSettings;
use App\Support\VideoSales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Video extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    protected $with = ['thumbnailMirrors.provider'];

    protected $fillable = [
        'original_thumbnail_url', 'thumbnail_source_url', 'thumbnail_source_version', 'thumbnail_is_custom',
        'video_width', 'video_height', 'metadata_overrides',
        'user_id',
        'channel_id',
        'title',
        'slug',
        'description',
        'tags',
        'category_id',
        'language_id',
        'playlist_title',
        'scheduled_at',
        'made_for_kids',
        'monetization_enabled',
        'sale_enabled',
        'price_cents',
        'visibility',
        'requested_visibility',
        'status',
        'approval_status',
        'processing_progress',
        'processing_stage',
        'video_path',
        'renditions',
        'thumbnail_path',
        'captions_path',
        'chapters',
        'chapters_enabled',
        'chapters_source',
        'source_type',
        'import_provider',
        'import_url',
        'duration_seconds',
        'is_short',
        'views_count',
        'likes_count',
        'comments_count',
        'published_at',
        'publish_as_draft',
        'caption_metadata',
    ];

    protected $casts = [
        'thumbnail_is_custom' => 'boolean', 'video_width' => 'integer', 'video_height' => 'integer', 'metadata_overrides' => 'array',
        'duration_seconds' => 'integer',
        'is_short' => 'boolean',
        'tags' => 'array',
        'category_id' => 'integer',
        'language_id' => 'integer',
        'scheduled_at' => 'datetime',
        'made_for_kids' => 'boolean',
        'monetization_enabled' => 'boolean',
        'sale_enabled' => 'boolean',
        'price_cents' => 'integer',
        'processing_progress' => 'integer',
        'renditions' => 'array',
        'chapters' => 'array',
        'chapters_enabled' => 'boolean',
        'views_count' => 'integer',
        'likes_count' => 'integer',
        'comments_count' => 'integer',
        'published_at' => 'datetime',
        'publish_as_draft' => 'boolean',
        'caption_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (Video $video): void {
            if ($video->isDirty(['source_type', 'import_provider', 'import_url', 'video_path']) || ! $video->exists) {
                $video->forceFill(\App\Support\VideoImportIdentity::forVideo($video));
            }
        });
        static::deleting(function (Video $video): void {
            Like::query()
                ->where(function (Builder $query) use ($video): void {
                    $query->where(function (Builder $videoLikes) use ($video): void {
                        $videoLikes->where('likeable_type', Video::class)
                            ->where('likeable_id', $video->getKey());
                    })->orWhere(function (Builder $commentLikes) use ($video): void {
                        $commentLikes->where('likeable_type', Comment::class)
                            ->whereIn('likeable_id', Comment::query()
                                ->select('id')
                                ->where('video_id', $video->getKey()));
                    });
                })
                ->delete();
        });
    }

    public function scopePublic(Builder $query): Builder
    {
        return $this->scopePublishedPublic($query)
            ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled());
    }

    public function scopePublishedPublic(Builder $query): Builder
    {
        $visibility = $query->getModel()->qualifyColumn('visibility');
        $status = $query->getModel()->qualifyColumn('status');
        $approval = $query->getModel()->qualifyColumn('approval_status');
        $scheduled = $query->getModel()->qualifyColumn('scheduled_at');

        return $query
            ->where($visibility, 'public')
            ->where($status, 'ready')
            ->where(fn (Builder $builder) => $builder->whereNull($approval)->orWhere($approval, 'approved'))
            ->where(fn (Builder $builder) => $builder->whereNull($scheduled)->orWhere($scheduled, '<=', now()));
    }

    public function scopeShorts(Builder $query): Builder
    {
        return \App\Support\ShortVideos::scope($query);
    }

    public function scopeStandardVideos(Builder $query): Builder
    {
        return $query->where('source_type', '!=', 'live_replay')->where(function (Builder $q): void {
            $q->whereNull('duration_seconds')->orWhere('duration_seconds', '<=', 0)->orWhere('duration_seconds', '>', \App\Support\ShortVideos::MAX_SECONDS)
                ->orWhereNull('video_width')->orWhere('video_width', '<=', 0)->orWhereNull('video_height')->orWhere('video_height', '<=', 0)
                ->orWhereColumn('video_height', '<=', 'video_width');
        });
    }

    public static function isShortContent(?string $sourceType, mixed $durationSeconds, mixed $explicitIsShort = null, bool $saleEnabled = false, mixed $width = null, mixed $height = null): bool
    {
        // A historical flag cannot prove the current duration/orientation rule.
        // Callers keep their legacy arguments but must provide real dimensions.
        return \App\Support\ShortVideos::qualifies($durationSeconds, $width, $height, $sourceType);
    }

    public function isShort(): bool
    {
        return \App\Support\ShortVideos::qualifies($this->duration_seconds, $this->video_width, $this->video_height, $this->source_type);
    }

    public function thumbnailMirrors(): HasMany
    {
        return $this->hasMany(ThumbnailMirror::class)->whereIn('source_version', function ($q): void {
            $q->select('thumbnail_source_version')->from('videos')->whereColumn('videos.id', 'thumbnail_mirrors.video_id');
        });
    }

    public function getThumbnailCandidatesAttribute(): array
    {
        return \App\Support\Thumbnails\ThumbnailService::candidates($this);
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
        return $this->belongsTo(VideoCategory::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(VideoLanguage::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class)->whereNull('parent_id')->latest();
    }

    public function liveStream(): HasOne
    {
        return $this->hasOne(LiveStream::class, 'replay_video_id');
    }

    public function likes(): MorphMany
    {
        return $this->morphMany(Like::class, 'likeable');
    }

    public function getVideoUrlAttribute(): ?string
    {
        if ($this->source_type === 'import') {
            if ($this->import_provider === 'direct') {
                // yt-dlp may have resolved a direct playback URL (any supported
                // site). Fall back to the raw import URL for legacy direct files.
                if (class_exists(\CrzSourceResolver\Support\VideoUrl::class)
                    && \CrzSourceResolver\Resolver::available()) {
                    $resolved = \CrzSourceResolver\Support\VideoUrl::resolved($this);
                    if (is_string($resolved) && $resolved !== '') {
                        return $resolved;
                    }
                }

                return VideoImportSettings::directPlaybackUrl($this->import_url);
            }

            // CrozzTube-Patch: Resolver-Direkt-URL (yt-dlp) bevorzugen;otherwise Embed-Fallback.
            if (class_exists(\CrzSourceResolver\Support\VideoUrl::class)
                && \CrzSourceResolver\Resolver::available()) {
                return \CrzSourceResolver\Support\VideoUrl::resolved($this);
            }

            return null;
        }

        if (! $this->video_path) {
            return null;
        }

        $quality = $this->status === 'ready' ? 'high' : null;

        return VideoSales::streamUrl($this, $quality);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $candidates = $this->thumbnail_candidates;
        if ($candidates !== []) return $candidates[0];
        if (! $this->thumbnail_path) {
            return null;
        }

        if (! preg_match('/^(https?:)?\/\//', $this->thumbnail_path)
            && ($this->visibility !== 'public' || ! $this->user?->hasPublicProfileEnabled())) {
            return VideoSales::thumbnailUrl($this);
        }

        return preg_match('/^(https?:)?\/\//', $this->thumbnail_path)
            ? $this->thumbnail_path
            : url('/media/videos/'.$this->id.'/thumbnail').'?v='.substr(hash('sha256', $this->thumbnail_path), 0, 12);
    }

    public function mediaVersion(?string $quality = null): string
    {
        $path = $quality ? data_get(collect($this->renditions ?: [])->firstWhere('quality', $quality), 'path') : null;

        return substr(hash('sha256', (string) ($path ?: $this->video_path)), 0, 12);
    }
}
