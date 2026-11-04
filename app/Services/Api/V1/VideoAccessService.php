<?php

namespace App\Services\Api\V1;

use App\Models\User;
use App\Models\Video;
use App\Support\VideoSales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class VideoAccessService
{
    public function publicQuery(): Builder
    {
        return $this->constrainPublic(Video::query());
    }

    public function viewableQuery(?User $viewer, bool $allowUnlisted = true): Builder
    {
        return $this->constrainViewable(Video::query(), $viewer, $allowUnlisted);
    }

    public function constrainViewable(Builder|Relation $query, ?User $viewer, bool $allowUnlisted = true): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;
        if (! $viewer) {
            return $this->constrainPublic($query);
        }

        $model = $query->getModel();
        $visibility = $model->qualifyColumn('visibility');
        $status = $model->qualifyColumn('status');
        $approval = $model->qualifyColumn('approval_status');
        $scheduled = $model->qualifyColumn('scheduled_at');
        $owner = $model->qualifyColumn('user_id');

        return $query->where(function (Builder $query) use ($viewer, $allowUnlisted, $visibility, $status, $approval, $scheduled, $owner): void {
            $query->where($owner, $viewer->id)
                ->orWhere(function (Builder $visible) use ($allowUnlisted, $visibility, $status, $approval, $scheduled): void {
                    $visible->whereIn($visibility, $allowUnlisted ? ['public', 'unlisted'] : ['public'])
                        ->where($status, 'ready')
                        ->where(fn (Builder $approved) => $approved->whereNull($approval)->orWhere($approval, 'approved'))
                        ->where(fn (Builder $available) => $available->whereNull($scheduled)->orWhere($scheduled, '<=', now()))
                        ->whereHas('user', fn (Builder $owner) => $owner->publicProfileEnabled());
                });
        });
    }

    public function constrainPublic(Builder|Relation $query): Builder
    {
        $query = $query instanceof Relation ? $query->getQuery() : $query;

        return $query->public();
    }

    public function canView(Video $video, ?User $viewer, bool $allowUnlisted = true): bool
    {
        if (! \App\Support\UserBlocks::canSee($viewer, (int) $video->user_id)) return false;

        if (VideoSales::canViewPrivate($video, $viewer)) {
            return true;
        }

        $video->loadMissing('user');
        if (! $video->user?->hasPublicProfileEnabled()) {
            return false;
        }

        if ($video->status !== 'ready' || ($video->approval_status && $video->approval_status !== 'approved')) {
            return false;
        }

        if ($video->scheduled_at?->isFuture()) {
            return false;
        }

        return $video->visibility === 'public' || ($allowUnlisted && $video->visibility === 'unlisted');
    }

    public function assertViewable(Video $video, ?User $viewer, bool $allowUnlisted = true): void
    {
        abort_unless($this->canView($video, $viewer, $allowUnlisted), 404, 'Video not found.');
        if ($viewer && $viewer->isBannedFrom('watch')) {
            abort(403, $viewer->banMessage('watch'));
        }
    }

    public function assertPubliclyListable(Video $video): void
    {
        abort_unless($this->canView($video, null, false), 404, 'Video not found.');
    }
}
