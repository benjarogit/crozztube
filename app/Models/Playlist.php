<?php

namespace App\Models;

use App\Support\SeriesStructure;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;

class Playlist extends Model
{
    use HasFactory;
    use \App\Models\Concerns\FiltersBlockedAuthors;

    /** @var list<string>|null */
    private static ?array $pivotColumns = null;

    protected $fillable = [
        'user_id',
        'channel_id',
        'title',
        'description',
        'privacy',
        'kind',
        'series_status',
        'color',
        'cover_video_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function videos(): BelongsToMany
    {
        return $this->belongsToMany(Video::class)
            ->withTimestamps()
            ->withPivot(self::pivotColumns())
            ->orderByPivot('sort_order');
    }

    public function coverVideo(): BelongsTo
    {
        return $this->belongsTo(Video::class, 'cover_video_id');
    }

    public function isSeries(): bool
    {
        return SeriesStructure::isSeries($this);
    }

    /**
     * The season and episode columns are additive. Older deployments that have
     * not migrated yet keep working instead of failing every playlist query.
     *
     * @return list<string>
     */
    private static function pivotColumns(): array
    {
        if (self::$pivotColumns !== null) {
            return self::$pivotColumns;
        }

        $columns = ['sort_order', 'added_by_user_id'];
        foreach (['season_number', 'episode_number', 'episode_title', 'episode_description', 'aired_at'] as $column) {
            if (Schema::hasColumn('playlist_video', $column)) {
                $columns[] = $column;
            }
        }

        return self::$pivotColumns = $columns;
    }
}
