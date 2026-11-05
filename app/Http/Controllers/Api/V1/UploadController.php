<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\UploadSessionController as LegacyUploadSessionController;
use App\Http\Controllers\Controller;
use App\Models\UploadSession;
use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use App\Support\PublishingAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class UploadController extends Controller
{
    public function capabilities(Request $request): JsonResponse
    {
        $settings = PlatformFeatureSettings::settings();

        return response()->json([
            'publishing_access' => PublishingAccess::status($request->user()),
            'transport' => [
                'chunked_upload' => ! config('thumbnails.streaming_only'),
                'direct_to_cloud' => false,
                'multipart_field' => 'chunk',
                'minimum_chunk_bytes' => 1024 * 1024,
                'maximum_chunk_bytes' => (int) config('playhub.chunk_size_mb', 8) * 1024 * 1024,
                'maximum_file_bytes' => (int) config('playhub.max_upload_mb', 51200) * 1024 * 1024,
            ],
            'formats' => [
                'extensions' => ['mp4', 'mov', 'm4v', 'webm', 'mkv'],
                'mime_types' => ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'video/x-matroska'],
                'caption_extensions' => ['vtt', 'srt', 'txt'],
            ],
            'processing' => [
                'asynchronous' => true,
                'renditions' => array_values((array) data_get($settings, 'uploads.renditions', [])),
                'delivery' => ['progressive'],
                'requires_moderation' => PlatformFeatureSettings::requiresUploadApproval($settings),
            ],
            'storage' => MediaStorage::storageSummaryFor($request->user()),
        ]);
    }

    public function store(Request $request, LegacyUploadSessionController $legacy): JsonResponse
    {
        return $this->sessionResponse($legacy->store($request));
    }

    public function chunk(
        Request $request,
        UploadSession $uploadSession,
        LegacyUploadSessionController $legacy,
    ): JsonResponse {
        return $this->sessionResponse($legacy->chunk($request, $uploadSession));
    }

    public function show(
        Request $request,
        UploadSession $uploadSession,
        LegacyUploadSessionController $legacy,
    ): JsonResponse {
        return $this->sessionResponse($legacy->show($request, $uploadSession));
    }

    public function complete(
        Request $request,
        UploadSession $uploadSession,
        LegacyUploadSessionController $legacy,
    ): JsonResponse {
        abort_unless($uploadSession->user_id === $request->user()->id, 403);
        if (! $uploadSession->video_id) {
            $request->validate([
                'scheduled_at' => ['nullable', 'date', 'after:now'],
                'tags' => ['nullable', 'string', function ($attribute, $value, $fail): void {
                    $tags = array_filter(array_map('trim', explode(',', $value)));
                    if (count($tags) > 10 || collect($tags)->contains(fn ($tag) => mb_strlen($tag) > 100)) {
                        $fail('Use up to 10 tags, each at most 100 characters.');
                    }
                }],
            ]);
            if ($request->input('content_type') === 'short') {
                $request->merge(['monetization_enabled' => false]);
            }
            if ($request->filled('scheduled_at')) {
                $request->merge(['scheduled_at' => Carbon::parse($request->input('scheduled_at'))->utc()->toDateTimeString()]);
            }
        }
        $response = $legacy->complete($request, $uploadSession);
        $body = $response->getData(true);
        $videoId = (int) data_get($body, 'video.id');
        $video = Video::query()->findOrFail($videoId);

        return response()->json([
            'upload_session' => $this->safeSession((array) ($body['upload_session'] ?? [])),
            'video' => $this->processingVideo($video),
        ], 201);
    }

    public function destroy(
        Request $request,
        UploadSession $uploadSession,
        LegacyUploadSessionController $legacy,
    ): JsonResponse {
        $legacy->destroy($request, $uploadSession);

        return response()->json(['upload_session' => [
            'id' => (string) $uploadSession->uuid,
            'status' => 'cancelled',
        ]]);
    }

    private function sessionResponse(JsonResponse $response): JsonResponse
    {
        $body = $response->getData(true);
        $payload = ['upload_session' => $this->safeSession((array) ($body['upload_session'] ?? []))];
        $videoId = (int) data_get($body, 'video.id');
        if ($videoId > 0 && ($video = Video::query()->find($videoId))) {
            $payload['video'] = $this->processingVideo($video);
        }

        return response()->json($payload, $response->getStatusCode());
    }

    private function safeSession(array $session): array
    {
        $status = (string) ($session['status'] ?? 'pending');

        return [
            'id' => (string) ($session['uuid'] ?? ''),
            'status' => $status,
            'file_name' => (string) ($session['file_name'] ?? ''),
            'file_size_bytes' => (int) ($session['file_size'] ?? 0),
            'chunk_size_bytes' => (int) ($session['chunk_size'] ?? 0),
            'total_chunks' => (int) ($session['total_chunks'] ?? 0),
            'received_chunks' => (int) ($session['received_chunks'] ?? 0),
            'received_chunk_indexes' => (array) ($session['received_chunk_indexes'] ?? []),
            'expires_at' => $session['expires_at'] ?? null,
            'progress_percent' => (float) ($session['progress'] ?? 0),
            'video_id' => isset($session['video_id']) ? (int) $session['video_id'] : null,
            'error' => match ($status) {
                'failed' => 'Upload processing failed.',
                'cancelled' => 'Upload cancelled.',
                default => null,
            },
        ];
    }

    private function processingVideo(Video $video): array
    {
        return [
            'id' => (int) $video->id,
            'slug' => (string) $video->slug,
            'title' => (string) $video->title,
            'content_type' => $video->isShort() ? 'short' : 'video',
            'status' => (string) $video->status,
            'approval_status' => $video->approval_status,
            'requested_visibility' => (string) $video->requested_visibility,
            'processing' => [
                'progress_percent' => (int) $video->processing_progress,
                'stage' => $video->processing_stage,
            ],
        ];
    }
}
