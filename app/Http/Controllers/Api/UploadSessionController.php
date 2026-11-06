<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Jobs\ProcessVideoUpload;
use App\Models\Playlist;
use App\Models\UploadSession;
use App\Models\Video;
use App\Support\MediaStorage;
use App\Support\PlatformFeatureSettings;
use App\Support\PublishingAccess;
use App\Support\Slugs;
use App\Support\UploadedVideoProbe;
use App\Support\VideoChapters;
use App\Support\VideoSales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UploadSessionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_if(config('thumbnails.streaming_only'), 403, 'Video file uploads are disabled. Import a video URL instead.');
        PublishingAccess::authorizeUpload($request->user());
        $data = $request->validate([
            'file_name' => ['required', 'string', 'max:255'],
            'file_size' => ['required', 'integer', 'min:1', 'max:'.(config('playhub.max_upload_mb', 51200) * 1024 * 1024)],
            'mime_type' => ['nullable', 'string', Rule::in(['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'video/x-matroska'])],
            'chunk_size' => ['required', 'integer', 'min:1048576', 'max:'.(config('playhub.chunk_size_mb', 8) * 1024 * 1024)],
            'total_chunks' => ['required', 'integer', 'min:1', 'max:20000'],
            'client_upload_id' => ['nullable', 'uuid'],
        ]);
        $extension = strtolower(pathinfo($data['file_name'], PATHINFO_EXTENSION));
        abort_unless(in_array($extension, ['mp4', 'mov', 'm4v', 'webm', 'mkv'], true), 422, 'The selected video format is not supported.');
        abort_unless((int) ceil($data['file_size'] / $data['chunk_size']) === (int) $data['total_chunks'], 422, 'The upload chunk count does not match the file size.');

        $session = DB::transaction(function () use ($request, $data): UploadSession {
            // Reserve capacity while holding an account-scoped lock so two
            // simultaneous upload sessions cannot spend the same quota.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            if (! empty($data['client_upload_id'])) {
                $existing = UploadSession::query()->where('user_id', $request->user()->id)->where('client_upload_id', $data['client_upload_id'])->first();
                if ($existing) {
                    abort_unless($existing->file_size === (int) $data['file_size'] && $existing->chunk_size === (int) $data['chunk_size'] && $existing->original_filename === $data['file_name'], 409, 'This upload identity belongs to a different file.');

                    return $existing;
                }
            }
            $storage = MediaStorage::storageSummaryFor($request->user());
            abort_if(! ($storage['unlimited'] ?? false) && (int) $data['file_size'] > (int) $storage['remaining_bytes'], 422, 'Not enough storage available for this upload.');

            return UploadSession::query()->create([
                'uuid' => (string) Str::uuid(),
                'client_upload_id' => $data['client_upload_id'] ?? null,
                'user_id' => $request->user()->id,
                'status' => 'pending',
                'original_filename' => $data['file_name'],
                'mime_type' => $data['mime_type'] ?? null,
                'file_size' => $data['file_size'],
                'chunk_size' => $data['chunk_size'],
                'total_chunks' => $data['total_chunks'],
                'received_chunk_indexes' => [],
            ]);
        }, 3);

        return response()->json([
            'upload_session' => $this->sessionPayload($session),
        ], 201);
    }

    public function chunk(Request $request, UploadSession $uploadSession): JsonResponse
    {
        abort_if(config('thumbnails.streaming_only'), 403, 'Video file uploads are disabled. Import a video URL instead.');
        $this->authorizeSession($request, $uploadSession);
        PublishingAccess::authorizeUpload($request->user());
        abort_if($uploadSession->created_at->addDays(7)->isPast(), 410, 'This upload session has expired. Select the file again.');

        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0', 'max:'.($uploadSession->total_chunks - 1)],
            'chunk' => ['required', 'file', 'max:'.(config('playhub.chunk_size_mb', 8) * 1024)],
        ]);

        $index = (int) $data['index'];
        $expectedBytes = $index === $uploadSession->total_chunks - 1
            ? $uploadSession->file_size - ($uploadSession->chunk_size * $index)
            : $uploadSession->chunk_size;
        abort_unless((int) $data['chunk']->getSize() === $expectedBytes, 422, 'The uploaded chunk size does not match this session.');

        $uploadSession = DB::transaction(function () use ($uploadSession, $request, $data, $index): UploadSession {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($uploadSession->id);
            $this->authorizeSession($request, $locked);
            abort_if(in_array($locked->status, ['assembling', 'processing', 'completed', 'cancelled', 'failed'], true), 409, 'Upload session is not accepting chunks.');

            $disk = MediaStorage::isLocal() ? MediaStorage::diskName() : 'local';
            $stored = Storage::disk($disk)->putFileAs($this->chunkDir($locked), $data['chunk'], $index.'.part');
            abort_unless(is_string($stored) && $stored !== '', 500, 'Could not store the uploaded chunk.');

            $indexes = collect($locked->received_chunk_indexes ?: [])
                ->push($index)
                ->unique()
                ->sort()
                ->values()
                ->all();
            $locked->forceFill([
                'status' => 'uploading',
                'received_chunk_indexes' => $indexes,
                'received_chunks' => count($indexes),
            ])->save();

            return $locked;
        }, 3);

        return response()->json([
            'upload_session' => $this->sessionPayload($uploadSession->fresh()),
        ]);
    }

    public function complete(Request $request, UploadSession $uploadSession): JsonResponse
    {
        abort_if(config('thumbnails.streaming_only'), 403, 'Video file uploads are disabled. Import a video URL instead.');
        $this->authorizeSession($request, $uploadSession);
        PublishingAccess::authorizeUpload($request->user());
        if ($uploadSession->video_id) {
            return $this->show($request, $uploadSession);
        }
        abort_if($uploadSession->created_at->addDays(7)->isPast(), 410, 'This upload session has expired. Select the file again.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'category_id' => ['required', 'integer', Rule::exists('video_categories', 'id')],
            'language_id' => ['nullable', 'integer', Rule::exists('video_languages', 'id')],
            'playlist_id' => ['nullable', 'integer', Rule::exists('playlists', 'id')->where(fn ($query) => $query->where('user_id', $request->user()->id))],
            'playlist_title' => ['nullable', 'string', 'max:160'],
            'scheduled_at' => ['nullable', 'date'],
            'made_for_kids' => ['nullable', 'boolean'],
            'monetization_enabled' => ['nullable', 'boolean'],
            'sale_enabled' => ['nullable', 'boolean'],
            'sale_price' => ['nullable', 'numeric', 'min:0.01', 'max:1000000'],
            'visibility' => ['nullable', Rule::in(['public', 'unlisted', 'private'])],
            'content_type' => ['nullable', Rule::in(['video', 'short'])],
            'publish_as_draft' => ['nullable', 'boolean'],
            'video_width' => ['nullable', 'integer', 'min:1'],
            'video_height' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'thumbnail' => ['nullable', 'image', 'max:5120'],
            'captions' => ['nullable', 'file', 'mimes:srt,vtt,txt', 'max:5120'],
            ...VideoChapters::rules(),
        ]);

        $playlist = ! empty($data['playlist_id'])
            ? Playlist::query()->where('user_id', $request->user()->id)->findOrFail((int) $data['playlist_id'])
            : null;

        $disk = MediaStorage::diskName();
        $uploadSession = DB::transaction(function () use ($uploadSession, $request): UploadSession {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($uploadSession->id);
            $this->authorizeSession($request, $locked);
            abort_if($locked->video_id, 409, 'Upload session is already completed.');
            abort_unless(in_array($locked->status, ['pending', 'uploading'], true), 409, 'Upload session is not accepting completion.');
            abort_unless($locked->received_chunks >= $locked->total_chunks, 422, 'Upload is missing chunks.');
            $locked->forceFill(['status' => 'assembling'])->save();

            return $locked;
        }, 3);
        $chunkDisk = $this->chunkDisk($uploadSession, MediaStorage::isLocal() ? $disk : 'local');

        try {
            $assembled = $this->assembleChunks($uploadSession, $chunkDisk, $disk);
            $videoPath = $assembled['path'];
            $data = array_replace($data, $assembled['probe']);
            if (($data['content_type'] ?? 'video') === 'short') {
                abort_if(! \App\Support\ShortVideos::qualifies($data['duration_seconds'], $data['video_width'] ?? null, $data['video_height'] ?? null), 422, 'Shorts must be vertical videos of at most 60 seconds.');
            }
            $isShort = \App\Support\ShortVideos::qualifies($data['duration_seconds'], $data['video_width'] ?? null, $data['video_height'] ?? null);
            $pricing = VideoSales::pricing($request->boolean('sale_enabled'), $data['sale_price'] ?? null, ! $isShort);
            $data['content_type'] = $isShort ? 'short' : 'video';
            $chapterData = VideoChapters::attributes($isShort ? [...$data, 'chapters_enabled' => false] : $data, $data['duration_seconds']);
            abort_unless(MediaStorage::exists($videoPath), 500, 'Cannot assemble uploaded video.');
            $thumbnailDirectory = 'videos/thumbnails/'.now()->format('Y/m');
            $captionsDirectory = 'videos/captions/'.now()->format('Y/m');
            if (MediaStorage::isLocal()) {
                MediaStorage::ensureLocalDirectory($thumbnailDirectory);
                MediaStorage::ensureLocalDirectory($captionsDirectory);
            }
            $thumbnailPath = $request->file('thumbnail')?->store($thumbnailDirectory, $disk);
            abort_unless(! $request->hasFile('thumbnail') || ($thumbnailPath && MediaStorage::exists($thumbnailPath)), 500, 'Cannot store uploaded thumbnail.');
            $captionsPath = $request->file('captions')?->store($captionsDirectory, $disk);
            abort_unless(! $request->hasFile('captions') || ($captionsPath && MediaStorage::exists($captionsPath)), 500, 'Cannot store uploaded captions.');
            $requestedVisibility = $data['visibility'] ?? 'public';
            $approvalStatus = PlatformFeatureSettings::requiresUploadApproval() ? 'pending' : 'approved';
            $tags = $this->normalizeTags($data['tags'] ?? '');
            $scheduledAt = $data['scheduled_at'] ?? null;

            $video = DB::transaction(function () use ($request, $data, $playlist, $videoPath, $thumbnailPath, $captionsPath, $requestedVisibility, $approvalStatus, $tags, $uploadSession, $scheduledAt, $pricing, $chapterData): Video {
                $user = $request->user()->loadMissing('channel');

                $video = Video::query()->create([
                    'user_id' => $user->id,
                    'channel_id' => $user->channel->id,
                    'title' => $data['title'],
                    'is_short' => ($data['content_type'] ?? 'video') === 'short',
                    'duration_seconds' => $data['duration_seconds'],
                    'publish_as_draft' => $request->boolean('publish_as_draft'),
                    'slug' => Slugs::unique($data['title'], Video::class),
                    'description' => $data['description'] ?? null,
                    'tags' => $tags,
                    'category_id' => $data['category_id'] ?? null,
                    'language_id' => $data['language_id'] ?? null,
                    'playlist_title' => $playlist?->title ?? ($data['playlist_title'] ?? null),
                    'scheduled_at' => $scheduledAt,
                    'made_for_kids' => $request->boolean('made_for_kids'),
                    'monetization_enabled' => $data['content_type'] !== 'short' && $request->boolean('monetization_enabled'),
                    ...$pricing,
                    'visibility' => 'private',
                    'requested_visibility' => $requestedVisibility,
                    'status' => 'processing',
                    'approval_status' => $approvalStatus,
                    'processing_progress' => 0,
                    'processing_stage' => 'Queued for processing',
                    'video_path' => $videoPath,
                    'thumbnail_path' => $thumbnailPath,
                    'captions_path' => $captionsPath,
                    ...$chapterData,
                    'published_at' => null,
                ]);

                if ($playlist) {
                    $playlist->videos()->attach($video->id, [
                        'sort_order' => ((int) DB::table('playlist_video')->where('playlist_id', $playlist->id)->max('sort_order')) + 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $playlist->touch();
                }

                $uploadSession->update([
                    'video_id' => $video->id,
                    'status' => 'processing',
                    'metadata' => $data,
                ]);

                $user->channel()->increment('videos_count');

                return $video;
            });

            ProcessVideoUpload::dispatch($video->id)->onQueue('videos');
            try {
                $this->deleteChunkDirectories($uploadSession, $chunkDisk, $disk);
            } catch (\Throwable $cleanupException) {
                // Staged chunks are disposable; cleanup must not leave a stored
                // video in processing without ever dispatching its queue job.
                report($cleanupException);
            }
            $uploadSessionPayload = $this->sessionPayload($uploadSession->fresh());

            return response()->json([
                'message' => 'Video uploaded. Processing has started.',
                'upload_session' => $uploadSessionPayload,
                'video' => new VideoResource($video->load(['channel.user', 'user', 'category', 'language'])),
            ], 201);
        } catch (\Throwable $exception) {
            if (! $uploadSession->fresh()->video_id) {
                try {
                    MediaStorage::delete(array_filter([$videoPath ?? null, $thumbnailPath ?? null, $captionsPath ?? null]));
                } catch (\Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            $uploadSession->update([
                'status' => $uploadSession->fresh()->video_id ? 'processing' : 'uploading',
                'error_message' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    public function show(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $this->authorizeSession($request, $uploadSession);

        return response()->json([
            'upload_session' => $this->sessionPayload($uploadSession->load('video')),
            'video' => $uploadSession->video ? new VideoResource($uploadSession->video->load(['channel.user', 'user', 'category', 'language'])) : null,
        ]);
    }

    public function destroy(Request $request, UploadSession $uploadSession): JsonResponse
    {
        $this->authorizeSession($request, $uploadSession);

        DB::transaction(function () use ($uploadSession): void {
            $locked = UploadSession::query()->lockForUpdate()->findOrFail($uploadSession->id);
            abort_if($locked->video_id || in_array($locked->status, ['assembling', 'processing', 'completed'], true), 409, 'Upload session can no longer be cancelled.');
            $disk = MediaStorage::diskName();
            $chunkDisk = $this->chunkDisk($locked, MediaStorage::isLocal() ? $disk : 'local');
            $this->deleteChunkDirectories($locked, $chunkDisk, $disk);
            $locked->update(['status' => 'cancelled', 'error_message' => 'Upload cancelled by user.']);
        }, 3);

        return response()->json(['message' => 'Upload cancelled.']);
    }

    private function authorizeSession(Request $request, UploadSession $uploadSession): void
    {
        abort_unless($uploadSession->user_id === $request->user()->id, 403);
    }

    private function assembleChunks(UploadSession $uploadSession, string $chunkDisk, string $targetDisk): array
    {
        $safeName = Str::slug(pathinfo($uploadSession->original_filename, PATHINFO_FILENAME)) ?: 'video';
        $extension = strtolower(pathinfo($uploadSession->original_filename, PATHINFO_EXTENSION) ?: 'mp4');
        $target = 'videos/originals/'.now()->format('Y/m').'/'.$uploadSession->uuid.'-'.$safeName.'.'.$extension;
        $targetPath = Storage::disk('local')->path('upload-assemblies/'.$uploadSession->uuid.'-'.$safeName.'.'.$extension);

        if (! is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0775, true);
        }

        $output = fopen($targetPath, 'wb');
        abort_if($output === false, 500, 'Cannot assemble uploaded video.');

        $writtenBytes = 0;
        $failed = false;
        try {
            for ($index = 0; $index < $uploadSession->total_chunks; $index++) {
                $chunk = $this->chunkDir($uploadSession).'/'.$index.'.part';
                abort_unless(Storage::disk($chunkDisk)->exists($chunk), 422, 'Upload is missing chunk '.$index.'.');
                $input = fopen(Storage::disk($chunkDisk)->path($chunk), 'rb');
                abort_if($input === false, 500, 'Cannot read uploaded chunk.');
                $copied = stream_copy_to_stream($input, $output);
                fclose($input);
                abort_if($copied === false, 500, 'Cannot write uploaded chunk.');
                $writtenBytes += $copied;
            }
        } catch (\Throwable $exception) {
            $failed = true;
            throw $exception;
        } finally {
            fclose($output);
            if ($failed) {
                @unlink($targetPath);
            }
        }

        clearstatcache(true, $targetPath);
        if (! is_file($targetPath) || filesize($targetPath) !== $uploadSession->file_size || $writtenBytes !== $uploadSession->file_size) {
            @unlink($targetPath);
            abort(500, 'Cannot assemble uploaded video completely.');
        }

        try {
            $probe = app(UploadedVideoProbe::class)->inspect($targetPath);
        } catch (\Throwable $exception) {
            @unlink($targetPath);
            throw $exception;
        }
        $stream = fopen($targetPath, 'rb');
        abort_if($stream === false, 500, 'Cannot upload assembled video.');

        try {
            $stored = $targetDisk === MediaStorage::diskName()
                ? MediaStorage::put($target, $stream)
                : Storage::disk($targetDisk)->put($target, $stream);
            abort_unless($stored, 500, 'Cannot upload assembled video.');
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($targetPath);
        }

        return ['path' => $target, 'probe' => $probe];
    }

    private function chunkDisk(UploadSession $uploadSession, string $preferredDisk): string
    {
        $firstChunk = $this->chunkDir($uploadSession).'/0.part';
        foreach (array_unique([$preferredDisk, 'public', 'local']) as $disk) {
            if (Storage::disk($disk)->exists($firstChunk)) {
                return $disk;
            }
        }

        return $preferredDisk;
    }

    private function deleteChunkDirectories(UploadSession $uploadSession, string ...$disks): void
    {
        $chunkDir = $this->chunkDir($uploadSession);
        foreach (array_unique([...$disks, 'public', 'local']) as $disk) {
            // Cloud uploads stage chunks locally. Listing a cloud prefix here
            // needlessly requires bucket-list permission and can fail even
            // when the provider successfully accepted the completed object.
            if (config('filesystems.disks.'.$disk.'.driver') !== 'local') {
                continue;
            }
            Storage::disk($disk)->deleteDirectory($chunkDir);
        }
    }

    private function chunkDir(UploadSession $uploadSession): string
    {
        return 'videos/chunks/'.$uploadSession->uuid;
    }

    private function normalizeTags(string $tags): array
    {
        return collect(explode(',', $tags))
            ->map(fn (string $tag): string => trim($tag))
            ->filter()
            ->unique(fn (string $tag): string => mb_strtolower($tag))
            ->take(10)
            ->values()
            ->all();
    }

    private function sessionPayload(UploadSession $uploadSession): array
    {
        return [
            'uuid' => $uploadSession->uuid,
            'status' => $uploadSession->status,
            'file_name' => $uploadSession->original_filename,
            'file_size' => $uploadSession->file_size,
            'chunk_size' => $uploadSession->chunk_size,
            'total_chunks' => $uploadSession->total_chunks,
            'received_chunks' => $uploadSession->received_chunks,
            'received_chunk_indexes' => array_values($uploadSession->received_chunk_indexes ?? []),
            'expires_at' => $uploadSession->created_at->addDays(7)->toIso8601String(),
            'progress' => $uploadSession->total_chunks > 0 ? round(($uploadSession->received_chunks / $uploadSession->total_chunks) * 100) : 0,
            'video_id' => $uploadSession->video_id,
            'error_message' => $uploadSession->error_message,
        ];
    }
}
