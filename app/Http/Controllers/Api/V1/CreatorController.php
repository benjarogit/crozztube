<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\VideoController as WebVideos;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreatorVideoRequest;
use App\Http\Resources\Api\V1\CreatorVideoResource;
use App\Jobs\ProcessVideoUpload;
use App\Models\LiveStream;
use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\MonetizationSettings;
use App\Support\PlatformFeatureSettings;
use App\Support\PublishingAccess;
use App\Support\VideoChapters;
use App\Support\VideoImportSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CreatorController extends Controller
{
    public function options(Request $request, UploadController $uploads): JsonResponse
    {
        $capabilities = $uploads->capabilities($request)->getData(true);

        return response()->json([
            ...$capabilities,
            'categories' => DB::table('video_categories')->where('enabled', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name'])->all(),
            'languages' => DB::table('video_languages')->orderBy('sort_order')->orderBy('name')->get(['id', 'name', 'code'])->all(),
            'defaults' => ['visibility' => PlatformFeatureSettings::defaultVideoVisibility()],
            'limits' => ['title' => 160, 'description' => 10000, 'tag_count' => 10, 'tag_length' => 100, 'thumbnail_bytes' => 5242880, 'caption_bytes' => 5242880, 'short_seconds' => 60],
            'features' => ['drafts' => true, 'scheduling' => true, 'captions' => true, 'chapters' => PlatformFeatureSettings::videoChaptersEnabled(), 'comments_settings' => false, 'midroll_cues' => false, 'multiple_caption_tracks' => false, 'generated_thumbnail_choices' => false],
            'import_providers' => array_values(array_filter(array_keys(VideoImportSettings::providers()), fn ($provider) => VideoImportSettings::providerEnabled($provider))),
            'live_studio_url' => url('/go-live'),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function dashboard(Request $request): JsonResponse
    {
        $query = Video::query()->where('user_id', $request->user()->id);
        $earnings = MonetizationSettings::userEarnings($request->user()->id);

        return response()->json([
            'summary' => [
                'videos' => (clone $query)->count(), 'views' => (int) (clone $query)->sum('views_count'),
                'likes' => (int) (clone $query)->sum('likes_count'), 'comments' => (int) (clone $query)->sum('comments_count'),
                'subscribers' => (int) ($request->user()->channel?->subscribers_count ?? 0),
                'processing' => (clone $query)->where('status', 'processing')->count(),
                'failed' => (clone $query)->where('status', 'failed')->count(),
                'drafts' => (clone $query)->where('status', 'draft')->count(),
                'scheduled' => (clone $query)->where('scheduled_at', '>', now())->count(),
                'earnings' => $earnings['total'],
            ],
            'recent' => CreatorVideoResource::collection((clone $query)->with('language')->latest()->take(8)->get())->resolve($request),
            'publishing_access' => PublishingAccess::status($request->user()),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function live(Request $request): JsonResponse
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);
        $page = LiveStream::query()->where('user_id', $request->user()->id)
            ->with('replayVideo:id,slug')->latest()->paginate(20);

        return response()->json([
            'items' => $page->getCollection()->map(fn ($stream) => [
                'id' => $stream->id, 'slug' => $stream->slug, 'title' => $stream->title,
                'status' => $stream->status, 'visibility' => $stream->visibility,
                'scheduled_at' => $stream->scheduled_at?->toIso8601String(),
                'replay_slug' => $stream->replayVideo?->slug,
            ])->all(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['all', 'videos', 'shorts', 'live', 'drafts', 'scheduled', 'processing', 'failed'])],
            'q' => ['nullable', 'string', 'max:160'], 'page' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'status' => ['nullable', Rule::in(['published', 'processing', 'failed', 'draft', 'scheduled', 'live'])],
            'monetization' => ['nullable', Rule::in(['on', 'off'])],
            'sort' => ['nullable', Rule::in(['latest', 'oldest', 'views', 'comments', 'likes', 'title'])],
        ]);
        $query = Video::query()->where('user_id', $request->user()->id)->with('language');
        if (! empty($data['q'])) {
            $like = '%'.addcslashes($data['q'], '\\%_').'%';
            $query->where(fn ($search) => $search->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)->orWhere('playlist_title', 'like', $like)
                ->orWhere('tags', 'like', $like)
                ->orWhereHas('category', fn ($category) => $category->where('name', 'like', $like))
                ->orWhereHas('language', fn ($language) => $language->where('name', 'like', $like)));
        }
        if (! empty($data['visibility'])) {
            $query->where('requested_visibility', $data['visibility']);
        }
        if (isset($data['monetization'])) {
            $query->where('monetization_enabled', $data['monetization'] === 'on');
        }
        match ($data['status'] ?? '') {
            'published' => $query->where('status', 'ready')->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now())),
            'scheduled' => $query->where('scheduled_at', '>', now()),
            'live' => $query->where('source_type', 'live_replay'),
            'processing', 'failed', 'draft' => $query->where('status', $data['status']), default => null,
        };
        $counts = [
            'all' => (clone $query)->count(), 'videos' => (clone $query)->standardVideos()->count(),
            'shorts' => (clone $query)->shorts()->count(), 'live' => (clone $query)->where('source_type', 'live_replay')->count(),
            'drafts' => (clone $query)->where('status', 'draft')->count(), 'scheduled' => (clone $query)->where('scheduled_at', '>', now())->count(),
            'processing' => (clone $query)->where('status', 'processing')->count(), 'failed' => (clone $query)->where('status', 'failed')->count(),
        ];
        match ($data['tab'] ?? 'all') {
            'videos' => $query->standardVideos(), 'shorts' => $query->shorts(),
            'live' => $query->where('source_type', 'live_replay'), 'drafts' => $query->where('status', 'draft'),
            'scheduled' => $query->where('scheduled_at', '>', now()),
            'processing', 'failed' => $query->where('status', $data['tab']), default => null,
        };
        match ($data['sort'] ?? 'latest') {
            'oldest' => $query->oldest(), 'views' => $query->orderByDesc('views_count')->latest(),
            'comments' => $query->orderByDesc('comments_count')->latest(), 'likes' => $query->orderByDesc('likes_count')->latest(),
            'title' => $query->orderBy('title')->latest(), default => $query->latest(),
        };
        $page = $query->orderByDesc('id')->paginate(20);

        return response()->json(['items' => CreatorVideoResource::collection($page)->resolve($request), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null, 'total' => $page->total(), 'counts' => $counts])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Video $video): JsonResponse
    {
        $this->owner($request, $video);

        return response()->json((new CreatorVideoResource($video->load('language')))->resolve($request))->header('Cache-Control', 'private, no-store');
    }

    public function update(CreatorVideoRequest $request, Video $video): JsonResponse
    {
        $data = $request->validated();
        $data = DB::transaction(function () use ($request, $data, $video): Video {
            $locked = Video::query()->lockForUpdate()->findOrFail($video->id);
            $this->owner($request, $locked);
            $native = $locked->source_type !== 'import';
            if (array_key_exists('monetization_enabled', $data)) {
                abort_if($data['monetization_enabled'] && (! $native || $locked->isShort() || $locked->sale_enabled), 422, 'PlayHub ads are unavailable for this content.');
            }
            if (array_key_exists('chapters', $data) || array_key_exists('chapters_enabled', $data)) {
                abort_unless($native && ! $locked->isShort(), 422, 'Chapter editing is available for native videos.');
                $data = array_replace($data, VideoChapters::attributes($data, $locked->duration_seconds));
            }
            if (isset($data['tags'])) {
                $data['tags'] = collect($data['tags'])->map(fn ($tag) => trim($tag))->filter()->unique(fn ($tag) => mb_strtolower($tag))->values()->all();
            }
            if (isset($data['title'])) {
                $data['title'] = trim($data['title']);
            }
            if (array_key_exists('visibility', $data)) {
                $data['requested_visibility'] = $data['visibility'];
            }
            $draft = $data['publish_as_draft'] ?? ($locked->publish_as_draft || $locked->status === 'draft');
            if (! $draft && ($locked->publish_as_draft || $locked->status === 'draft')) {
                PublishingAccess::authorizeUpload($request->user());
            }
            if (in_array($locked->status, ['ready', 'draft'], true)) {
                $data['status'] = $draft ? 'draft' : 'ready';
            }
            $scheduled = array_key_exists('scheduled_at', $data) ? ($data['scheduled_at'] ? Carbon::parse($data['scheduled_at'])->utc() : null) : $locked->scheduled_at;
            $canPublish = ! $draft && ($data['status'] ?? $locked->status) === 'ready' && ($locked->approval_status ?: 'approved') === 'approved' && ! $scheduled?->isFuture();
            $data['visibility'] = $canPublish ? ($data['requested_visibility'] ?? $locked->requested_visibility ?: $locked->visibility) : 'private';
            $data['published_at'] = $canPublish ? ($locked->published_at ?? now()) : null;
            if (array_key_exists('scheduled_at', $data)) {
                $data['scheduled_at'] = $scheduled;
            }
            // Keep slugs stable so shared URLs and active viewer routes survive title edits.
            $locked->fill($data)->save();

            return $locked;
        });

        return $this->show($request, $data);
    }

    public function destroy(Request $request, Video $video, WebVideos $web): JsonResponse
    {
        $this->owner($request, $video);
        abort_if($video->status === 'processing', 409, 'Wait for processing to finish before deleting this video.');

        return $web->destroy($request, $video);
    }

    public function retry(Request $request, Video $video): JsonResponse
    {
        $this->owner($request, $video);
        PublishingAccess::authorizeUpload($request->user());
        DB::transaction(function () use ($video): void {
            $locked = Video::query()->lockForUpdate()->findOrFail($video->id);
            abort_unless($locked->status === 'failed' && $locked->source_type !== 'import' && MediaStorage::exists($locked->video_path), 409, 'This video cannot be retried.');
            $locked->update(['status' => 'processing', 'processing_progress' => 0, 'processing_stage' => 'Queued for processing']);
            ProcessVideoUpload::dispatch($locked->id)->onQueue('videos')->afterCommit();
        });

        return $this->show($request, $video->fresh());
    }

    public function import(Request $request, WebVideos $web): JsonResponse
    {
        $request->merge(['monetization_enabled' => false, 'sale_enabled' => false]);
        $response = $web->import($request)->getData(true);

        return $this->show($request, Video::query()->findOrFail(data_get($response, 'video.id')));
    }

    private function owner(Request $request, Video $video): void
    {
        abort_unless((int) $request->user()->id === (int) $video->user_id, 404);
    }
}
