<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClipStagingAccount;
use App\Models\ClipTransfer;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Support\Clips\ClipQueueRejected;
use App\Support\Clips\ClipStagingObject;
use App\Support\Clips\ClipTransferQueue;
use App\Support\Clips\OracleStagingConnection;
use App\Support\Clips\OracleStagingStore;
use App\Support\Clips\OdyseePublisher;
use App\Support\MediaProviderSettings;
use App\Support\VideoSales;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** Clip admission and owner lifecycle; video bytes never pass through this controller. */
class ClipTransferController extends Controller
{
    public function store(Request $request, Video $video): JsonResponse
    {
        abort_unless($this->installed(), 503, 'Clip creation is unavailable.');
        $this->authorizeVideo($request, $video);
        $input = $request->validate([
            'request_key' => ['required', 'uuid'],
            'bytes' => ['required', 'integer', 'min:1', 'max:'.ClipStagingObject::MAX_BYTES],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/D'],
            'title' => ['required', 'string', 'max:200'],
            'start' => ['required', 'numeric', 'min:0'],
            'end' => ['required', 'numeric', 'gt:start'],
            'visibility' => ['required', 'in:private,public'],
        ]);

        try {
            $settings = MediaProviderSettings::current()->effective('oracle');
            $queue = ClipTransferQueue::fromOracleSettings($settings);
            $queue->synchronizeOracle($settings);
            $row = $queue->enqueue((int) $request->user()->id, (int) $video->id, $input['request_key'], [
                ...$input,
                'bytes' => (int) $input['bytes'],
                'start' => (float) $input['start'],
                'end' => (float) $input['end'],
            ]);
        } catch (ClipQueueRejected $error) {
            return $this->rejected($error);
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['clip' => ['The clip details are invalid.']]);
        } catch (Throwable) {
            abort(503, 'Clip creation is temporarily unavailable.');
        }

        return $this->response(['transfer' => $this->payload($row)], 202);
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->installed()) {
            return $this->response(['transfers' => []]);
        }
        $items = ClipTransfer::query()->where('user_id', $request->user()->id)
            ->with(['video:id,slug,title', 'collections:id,uuid,title'])
            ->latest('id')->limit(50)->get();

        return $this->response(['transfers' => $items->map(fn ($row) => $this->payload($row))->all()]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->response(['transfer' => $this->payload($this->owned($request, $uuid))]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $row = $this->owned($request, $uuid);
        try {
            (new ClipTransferQueue())->cancel((int) $request->user()->id, $row->uuid);
        } catch (ClipQueueRejected $error) {
            return $this->rejected($error);
        }
        // A sleeping upload or confirmation wait must observe cancellation promptly.
        $row->update(['next_check_at' => now()]);

        return $this->response(['transfer' => $this->payload($row->fresh())]);
    }

    public function uploadGrant(Request $request, string $uuid): JsonResponse
    {
        $row = $this->owned($request, $uuid);
        abort_unless($row->phase === 'uploading' && ! $row->cancel_requested, 409, 'The clip is not accepting an upload.');
        abort_unless($row->grant_until?->isFuture() && is_string($row->account_id), 409, 'The clip upload authorization expired.');
        $account = ClipStagingAccount::query()->findOrFail($row->account_id);

        try {
            $grant = (new OracleStagingStore(new OracleStagingConnection($account->connection)))
                ->uploadGrant($row->stagingObject(), DateTimeImmutable::createFromInterface($row->grant_until));
        } catch (Throwable) {
            abort(503, 'Clip upload authorization is temporarily unavailable.');
        }

        return $this->response(['upload' => $grant]);
    }

    public function uploaded(Request $request, string $uuid): JsonResponse
    {
        try {
            $row = (new ClipTransferQueue())->uploaded((int) $request->user()->id, $uuid);
        } catch (ClipQueueRejected $error) {
            return $this->rejected($error);
        }

        return $this->response(['transfer' => $this->payload($row)]);
    }

    public function update(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['visibility' => ['required', 'in:private,public']]);
        $row = $this->owned($request, $uuid);
        abort_unless($row->phase === 'ready' && $row->available_at !== null && ! $row->cancel_requested, 409,
            'The clip must be ready before its visibility can change.');
        $this->authorizeSource($request, $row);
        if ($row->metadata['visibility'] !== $data['visibility']) {
            $settings = MediaProviderSettings::current()->effective('odysee');
            $settings['channel_id'] = $row->publisher_channel_id;
            try {
                $this->publisher($settings)->visibility(
                    $row->uuid,
                    $row->provider_state['claim'],
                    $data['visibility'] === 'public',
                );
            } catch (Throwable) {
                abort(503, 'The clip visibility could not be confirmed.');
            }
            $metadata = $row->metadata;
            $metadata['visibility'] = $data['visibility'];
            $row->update(['metadata' => $metadata]);
        }

        return $this->response(['transfer' => $this->payload($row->fresh(['video', 'collections']))]);
    }

    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $row = $this->owned($request, $uuid);
        if ($row->phase === 'ready' && isset($row->provider_state['claim'])) {
            $settings = MediaProviderSettings::current()->effective('odysee');
            $settings['channel_id'] = $row->publisher_channel_id;
            try {
                $this->publisher($settings)->abandon($row->uuid, $row->provider_state['claim']);
            } catch (Throwable) {
                abort(503, 'Clip deletion is waiting for provider confirmation.');
            }
            $row->update([
                'phase' => 'cancelled',
                'cancel_requested' => true,
                'available_at' => null,
            ]);
        } else {
            try {
                $row = (new ClipTransferQueue())->cancel((int) $request->user()->id, $row->uuid);
            } catch (ClipQueueRejected $error) {
                return $this->rejected($error);
            }
        }
        $row->collections()->detach();

        return $this->response(['transfer' => $this->payload($row->fresh(['video', 'collections']))], 202);
    }

    public function playback(Request $request, string $uuid): JsonResponse
    {
        $row = $this->owned($request, $uuid);
        abort_unless($row->phase === 'ready' && $row->available_at !== null && ! $row->cancel_requested, 409, 'Your clip is still waiting for Odysee confirmation.');
        $this->authorizeSource($request, $row);
        $settings = MediaProviderSettings::current()->effective('odysee');
        $settings['channel_id'] = $row->publisher_channel_id;
        $embed = $this->publisher($settings)->embed($row->uuid, $row->provider_state['claim']);

        return $this->response(['embed_url' => $embed]);
    }

    public function publicShow(Request $request, string $uuid): JsonResponse
    {
        $row = $this->publicClip($request, $uuid);

        return $this->response(['clip' => $this->publicPayload($row)]);
    }

    public function publicPlayback(Request $request, string $uuid): JsonResponse
    {
        $row = $this->publicClip($request, $uuid);
        $settings = MediaProviderSettings::current()->effective('odysee');
        $settings['channel_id'] = $row->publisher_channel_id;
        $embed = $this->publisher($settings)->embed($row->uuid, $row->provider_state['claim']);

        return $this->response(['embed_url' => $embed]);
    }

    private function payload(ClipTransfer $row): array
    {
        $status = (new ClipTransferQueue())->status((int) $row->user_id, $row->uuid);

        $row->loadMissing(['video:id,slug,title', 'collections:id,uuid,title']);

        return [...$status, 'title' => $row->metadata['title'], 'start' => $row->metadata['start'],
            'end' => $row->metadata['end'], 'visibility' => $row->metadata['visibility'],
            'available_at' => $row->available_at?->toIso8601String(),
            'can_watch' => $row->phase === 'ready' && $row->available_at !== null && ! $row->cancel_requested,
            'can_retry' => in_array($row->phase, ['failed', 'cancelled'], true) && $row->video !== null,
            'original' => $row->video ? ['slug' => $row->video->slug, 'title' => $row->video->title] : null,
            'collections' => $row->collections->map(fn ($collection) => [
                'id' => $collection->uuid, 'title' => $collection->title,
            ])->all(),
            'created_at' => $row->created_at?->toIso8601String()];
    }

    private function owned(Request $request, string $uuid): ClipTransfer
    {
        abort_unless($this->installed(), 404);

        return ClipTransfer::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)
            ->with(['video:id,slug,title', 'collections:id,uuid,title'])->firstOrFail();
    }

    private function publicClip(Request $request, string $uuid): ClipTransfer
    {
        abort_unless($this->installed(), 404);
        $row = ClipTransfer::query()->available()->where('uuid', $uuid)
            ->where('metadata->visibility', 'public')->with('video:id,slug,title')->firstOrFail();
        $this->authorizeSource($request, $row);

        return $row;
    }

    private function publicPayload(ClipTransfer $row): array
    {
        return [
            'id' => $row->uuid,
            'title' => $row->metadata['title'],
            'start' => $row->metadata['start'],
            'end' => $row->metadata['end'],
            'original' => $row->video ? ['slug' => $row->video->slug, 'title' => $row->video->title] : null,
            'available_at' => $row->available_at?->toIso8601String(),
        ];
    }

    private function installed(): bool
    {
        return Schema::hasTable('clip_transfers') && Schema::hasColumn('clip_transfers', 'available_at');
    }

    private function response(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'private, no-store');
    }

    private function rejected(ClipQueueRejected $error): JsonResponse
    {
        $status = match ($error->reason) {
            'not_found' => 404,
            'daily_limit', 'monthly_limit' => 429,
            'queue_unavailable' => 503,
            default => 409,
        };

        return $this->response(['error' => ['code' => $error->reason]], $status);
    }

    protected function publisher(#[\SensitiveParameter] array $settings): OdyseePublisher
    {
        return new OdyseePublisher($settings);
    }

    protected function authorizeSource(Request $request, ClipTransfer $row): void
    {
        $video = Video::query()->without('thumbnailMirrors')->findOrFail($row->video_id);
        $this->authorizeVideo($request, $video);
    }

    protected function authorizeVideo(Request $request, Video $video): void
    {
        app(VideoAccessService::class)->assertViewable($video, $request->user());
        abort_unless(VideoSales::hasAccess($video, $request->user()), 404, 'Video not found.');
    }
}
