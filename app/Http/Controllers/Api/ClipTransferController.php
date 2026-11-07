<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClipTransfer;
use App\Models\Video;
use App\Services\Api\V1\VideoAccessService;
use App\Support\VideoSales;
use App\Support\Clips\ClipTransferQueue;
use App\Support\Clips\OdyseePublisher;
use App\Support\MediaProviderSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/** Owner status only; no file upload or clip-creation route is enabled here. */
class ClipTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->installed()) {
            return $this->response(['transfers' => []]);
        }
        $items = ClipTransfer::query()->where('user_id', $request->user()->id)->latest('id')->limit(50)->get();

        return $this->response(['transfers' => $items->map(fn ($row) => $this->payload($row))->all()]);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return $this->response(['transfer' => $this->payload($this->owned($request, $uuid))]);
    }

    public function cancel(Request $request, string $uuid): JsonResponse
    {
        $row = $this->owned($request, $uuid);
        (new ClipTransferQueue())->cancel((int) $request->user()->id, $row->uuid);
        // A sleeping upload or confirmation wait must observe cancellation promptly.
        $row->update(['next_check_at' => now()]);

        return $this->response(['transfer' => $this->payload($row->fresh())]);
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

    private function payload(ClipTransfer $row): array
    {
        $status = (new ClipTransferQueue())->status((int) $row->user_id, $row->uuid);

        return [...$status, 'title' => $row->metadata['title'], 'available_at' => $row->available_at?->toIso8601String(),
            'can_watch' => $row->phase === 'ready' && $row->available_at !== null && ! $row->cancel_requested,
            'created_at' => $row->created_at?->toIso8601String()];
    }

    private function owned(Request $request, string $uuid): ClipTransfer
    {
        abort_unless($this->installed(), 404);

        return ClipTransfer::query()->where('user_id', $request->user()->id)->where('uuid', $uuid)->firstOrFail();
    }

    private function installed(): bool
    {
        return Schema::hasTable('clip_transfers') && Schema::hasColumn('clip_transfers', 'available_at');
    }

    private function response(array $data): JsonResponse
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    protected function publisher(#[\SensitiveParameter] array $settings): OdyseePublisher
    {
        return new OdyseePublisher($settings);
    }

    protected function authorizeSource(Request $request, ClipTransfer $row): void
    {
        $video = Video::query()->without('thumbnailMirrors')->findOrFail($row->video_id);
        app(VideoAccessService::class)->assertViewable($video, $request->user());
        abort_unless(VideoSales::hasAccess($video, $request->user()), 404, 'Video not found.');
    }
}
