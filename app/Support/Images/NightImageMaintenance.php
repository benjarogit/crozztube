<?php

namespace App\Support\Images;

use App\Models\Comment;
use App\Models\CommunityPost;
use App\Models\ImageUpload;
use App\Models\ImageUploadMirror;
use App\Models\ThumbnailMirror;
use App\Models\Video;
use App\Support\Thumbnails\NightWindow;
use App\Support\Thumbnails\ThumbnailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** One shared budget and one transfer lock for every externally hosted image. */
class NightImageMaintenance
{
    private const PARENTS = [Comment::class => 'comments', 'comments' => 'comments',
        CommunityPost::class => 'community_posts', 'community_posts' => 'community_posts',
        'private_messages' => 'private_messages', 'App\\Models\\PrivateMessage' => 'private_messages'];

    public function run(ThumbnailService $thumbnails, ImageUploads $images): void
    {
        if (! NightWindow::contains() || NightWindow::paused()) return;
        $lock = Cache::lock('thumbnails:transfer', 90);
        if (! $lock->get()) return;
        $start = microtime(true);
        $end = now()->timezone(config('thumbnails.timezone'))->setTimeFromTimeString(config('thumbnails.night_end'));
        if ($end->lte(now())) $end->addDay();
        $deadline = min($start + (float) config('thumbnails.run_budget'), $start + now()->diffInSeconds($end, false));
        $stats = ['checks' => 0, 'copies' => 0, 'attempts' => 0, 'thumbnail_checks' => 0, 'attachment_checks' => 0,
            'thumbnail_attempts' => 0, 'attachment_attempts' => 0, 'started_at' => now()->toIso8601String()];
        $allowed = fn () => microtime(true) < $deadline - 0.1 && NightWindow::contains() && ! NightWindow::paused();
        try {
            $unseeded = Video::query()->whereNotNull('thumbnail_source_version')
                ->whereDoesntHave('thumbnailMirrors', fn ($q) => $q->whereColumn('source_version', 'videos.thumbnail_source_version'))
                ->limit(20)->get();
            foreach ($unseeded as $video) {
                if (! $allowed()) break;
                $thumbnails->ensureSource($video);
            }
            $legacy = $allowed() ? Video::query()->whereNull('thumbnail_source_version')->whereNotNull('thumbnail_path')->limit(5)->get() : [];
            foreach ($legacy as $video) {
                if (! $allowed()) break;
                $video->forceFill(['thumbnail_source_version' => hash('sha256', 'legacy:'.$video->id.':'.$video->thumbnail_path)])->save();
                $thumbnails->ensureSource($video);
            }
            $stats['expired_drafts'] = $allowed() ? $images->cleanupDrafts(20) : 0;
            $stats['detached_orphans'] = 0;
            // Bulk/admin deletes can bypass model events; never recreate their images.
            $orphans = $allowed() ? ImageUpload::where('status', 'attached')->where(function ($query): void {
                $query->whereNull('attachable_type')->orWhereNotIn('attachable_type', array_keys(self::PARENTS));
                foreach (self::PARENTS as $type => $table) {
                    $query->orWhere(fn ($parent) => $parent->where('attachable_type', $type)->whereNotExists(
                        fn ($row) => $row->selectRaw('1')->from($table)->whereColumn($table.'.id', 'image_uploads.attachable_id')));
                }
            })->oldest('updated_at')->limit(20)->get() : [];
            foreach ($orphans as $upload) {
                if (! $allowed()) break;
                if (! $this->parentExists($upload)) {
                    $images->detach([['image_id' => $upload->id]]);
                    $stats['detached_orphans']++;
                }
            }
            foreach ($allowed() ? $this->due('check', (int) config('thumbnails.check_limit')) : [] as $task) {
                if (! $allowed()) break;
                $mirror = $task['mirror'];
                $mirror->load('provider');
                if ($mirror->provider?->paused_until?->isFuture()) continue;
                if ($task['kind'] === 'attachment' && ! $this->parentExists($mirror->upload)) {
                    $images->detach([['image_id' => $mirror->image_upload_id]]);
                    $stats['detached_orphans']++;
                    continue;
                }
                $service = $task['kind'] === 'attachment' ? $images : $thumbnails;
                $service->check($mirror, $deadline);
                $stats['checks']++;
                $stats[$task['kind'].'_checks']++;
            }
            foreach ($allowed() ? $this->due('copy', (int) config('thumbnails.upload_limit')) : [] as $task) {
                if (! $allowed()) break;
                $mirror = $task['mirror'];
                $mirror->load('provider');
                if ($mirror->provider?->paused_until?->isFuture()) continue;
                if ($task['kind'] === 'attachment' && ! $this->parentExists($mirror->upload)) {
                    $images->detach([['image_id' => $mirror->image_upload_id]]);
                    $stats['detached_orphans']++;
                    continue;
                }
                $service = $task['kind'] === 'attachment' ? $images : $thumbnails;
                $stats['attempts']++;
                $stats[$task['kind'].'_attempts']++;
                if ($service->copy($mirror, $deadline)) $stats['copies']++;
            }
        } finally {
            $stats['seconds'] = round(microtime(true) - $start, 3);
            $stats['finished_at'] = now()->toIso8601String();
            Cache::put('thumbnails:last-run', $stats, now()->addDays(30));
            $lock->release();
        }
    }

    private function due(string $operation, int $limit): array
    {
        $ids = array_keys(ThumbnailService::providers());
        $thumbnail = ThumbnailMirror::query()->with(['video', 'provider'])
            ->whereIn('provider_id', [...$ids, 'original'])
            ->whereHas('video', fn ($q) => $q->whereColumn('videos.thumbnail_source_version', 'thumbnail_mirrors.source_version'));
        $attachment = ImageUploadMirror::query()->with(['upload', 'provider'])
            ->whereIn('provider_id', $ids)->whereHas('upload', fn ($q) => $q->where('status', 'attached'));
        $tasks = [];
        foreach (['thumbnail' => $thumbnail, 'attachment' => $attachment] as $kind => $query) {
            $query->whereHas('provider', fn ($q) => $q->whereNull('paused_until')->orWhere('paused_until', '<=', now()));
            $date = $operation === 'check' ? 'next_check_at' : 'next_attempt_at';
            if ($operation === 'check') $query->whereNotNull($kind === 'thumbnail' ? 'thumbnail_url' : 'image_url');
            else $query->whereIn('provider_id', $ids)->whereIn('status', ['pending', 'failed']);
            foreach ($query->where($date, '<=', now())->orderBy($date)->orderBy('id')->limit($limit)->get() as $mirror) {
                $tasks[] = ['kind' => $kind, 'mirror' => $mirror, 'due' => $mirror->$date->getTimestamp()];
            }
        }
        usort($tasks, fn ($a, $b) => ($a['due'] <=> $b['due']) ?: strcmp($a['kind'].':'.$a['mirror']->id, $b['kind'].':'.$b['mirror']->id));
        return array_slice($tasks, 0, $limit);
    }

    private function parentExists(?ImageUpload $upload): bool
    {
        if (! $upload || $upload->status !== 'attached' || ! $upload->attachable_id) return false;
        $table = self::PARENTS[$upload->attachable_type] ?? null;
        return $table && DB::table($table)->where('id', $upload->attachable_id)->exists();
    }
}
