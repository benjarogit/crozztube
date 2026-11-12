<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClipCollection;
use App\Models\ClipTransfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ClipCollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $collections = ClipCollection::query()->where('user_id', $request->user()->id)
            ->withCount('clips')->latest('updated_at')->get();

        return $this->response(['collections' => $collections->map(fn ($item) => $this->payload($item))->all()]);
    }

    public function show(Request $request, ClipCollection $collection): JsonResponse
    {
        $this->owned($request, $collection);
        $collection->load(['clips' => fn ($query) => $query->latest('clip_transfers.id')]);

        return $this->response([
            'collection' => $this->payload($collection),
            'clips' => $collection->clips->map(fn (ClipTransfer $clip) => $this->clipPayload($clip))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $collection = ClipCollection::query()->create([
            ...$data,
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
        ]);

        return $this->response(['collection' => $this->payload($collection->loadCount('clips'))], 201);
    }

    public function update(Request $request, ClipCollection $collection): JsonResponse
    {
        $this->owned($request, $collection);
        $collection->update($this->validated($request, true));

        return $this->response(['collection' => $this->payload($collection->fresh()->loadCount('clips'))]);
    }

    public function destroy(Request $request, ClipCollection $collection): JsonResponse
    {
        $this->owned($request, $collection);
        $collection->delete();

        return $this->response([], 204);
    }

    public function addClip(Request $request, ClipCollection $collection, string $uuid): JsonResponse
    {
        $this->owned($request, $collection);
        $clip = ClipTransfer::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
        abort_if($collection->clips()->count() >= 500 && ! $collection->clips()->whereKey($clip->id)->exists(), 422,
            'A clip collection can contain at most 500 clips.');
        DB::transaction(function () use ($collection, $clip): void {
            $locked = ClipCollection::query()->lockForUpdate()->findOrFail($collection->id);
            $position = (int) DB::table('clip_collection_clip')
                ->where('clip_collection_id', $locked->id)->max('sort_order');
            DB::table('clip_collection_clip')->insertOrIgnore([
                'clip_collection_id' => $locked->id,
                'clip_transfer_id' => $clip->id,
                'sort_order' => $position + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $locked->touch();
        }, 3);

        return $this->response(['collection_id' => $collection->uuid, 'clip_id' => $clip->uuid, 'included' => true]);
    }

    public function removeClip(Request $request, ClipCollection $collection, string $uuid): JsonResponse
    {
        $this->owned($request, $collection);
        $clipId = ClipTransfer::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->value('id');
        if ($clipId !== null) {
            DB::table('clip_collection_clip')->where('clip_collection_id', $collection->id)
                ->where('clip_transfer_id', $clipId)->delete();
            $collection->touch();
        }

        return $this->response(['collection_id' => $collection->uuid, 'clip_id' => $uuid, 'included' => false]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        if (is_string($request->input('title'))) {
            $request->merge(['title' => trim($request->input('title'))]);
        }
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => [$required, 'string', 'min:1', 'max:120'],
            'visibility' => [$required, Rule::in(['private', 'public'])],
        ]);
    }

    private function owned(Request $request, ClipCollection $collection): void
    {
        abort_unless((int) $collection->user_id === (int) $request->user()->id, 404, 'Clip collection not found.');
    }

    private function payload(ClipCollection $collection): array
    {
        return [
            'id' => $collection->uuid,
            'title' => $collection->title,
            'visibility' => $collection->visibility,
            'clips_count' => (int) ($collection->clips_count ?? $collection->clips()->count()),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ];
    }

    private function clipPayload(ClipTransfer $clip): array
    {
        return [
            'id' => $clip->uuid,
            'title' => $clip->metadata['title'],
            'visibility' => $clip->metadata['visibility'],
            'state' => $clip->phase,
            'can_watch' => $clip->phase === 'ready' && $clip->available_at !== null && ! $clip->cancel_requested,
        ];
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }
}
