<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImageUpload;
use App\Support\Images\ImageUploads;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ImageUploadController extends Controller
{
    public function update(Request $request, string $imageUpload, ImageUploads $images): JsonResponse
    {
        // Metadata/target authorization precedes raw-body access and all external work.
        $data = validator($request->query(), [
            'purpose' => ['required', Rule::in(ImageUploads::PURPOSES)],
            'target_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        $images->assertTarget($request->user(), $data['purpose'], $data['target_id']);
        $mime = strtolower(trim(explode(';', $request->header('Content-Type', ''))[0]));
        abort_unless(in_array($mime, ImageUploads::TYPES, true), 415, 'Send a JPEG, PNG, WebP or GIF image directly, without multipart encoding.');
        $limit = ImageUploads::maxBytes($data['purpose']);
        abort_if((int) $request->header('Content-Length', 0) > $limit, 413, 'The image exceeds its size limit.');
        $imageUpload = $images->reserve($request->user(), $imageUpload, $data['purpose'], $data['target_id'])->id;
        $stream = $request->getContent(true);
        try { $bytes = stream_get_contents($stream, $limit + 1); }
        finally { if (is_resource($stream)) fclose($stream); }
        if ($bytes === false || strlen($bytes) > $limit) {
            ImageUpload::whereKey($imageUpload)->where('user_id', $request->user()->id)->where('status', 'uploading')
                ->whereNull('content_hash')->update(['status' => 'failed', 'last_result' => 'size-limit', 'updated_at' => now()]);
            abort(413, 'The image exceeds its size limit.');
        }
        try {
            $upload = $images->receive($request->user(), $imageUpload, $data['purpose'], $data['target_id'], $bytes,
                rawurldecode(mb_substr((string) $request->header('X-Image-Name', ''), 0, 2048)), $mime);
            $status = $upload->status === 'failed' ? 503 : 200;
            return response()->json($this->present($upload), $status)->header('Cache-Control', 'private, no-store');
        } catch (HttpExceptionInterface $exception) {
            // Known workflow errors carry safe status metadata, never upstream response bodies.
            $upload = $images->owned($request->user(), $imageUpload);
            return response()->json(['message' => $exception->getMessage(), ...$this->present($upload)], $exception->getStatusCode())
                ->header('Cache-Control', 'private, no-store')->header('Retry-After', '2');
        }
    }

    public function show(Request $request, string $imageUpload, ImageUploads $images): JsonResponse
    {
        $upload = $images->owned($request->user(), $imageUpload);
        if ($upload->status !== 'attached' && $upload->expires_at->isPast()) {
            return response()->json(['id' => $upload->id, 'status' => 'failed', 'code' => 'image_upload_expired',
                'attachment' => null, 'message' => 'This image selection has expired. Select the file again.'], 410)
                ->header('Cache-Control', 'private, no-store');
        }
        return response()->json($this->present($upload))->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request, string $imageUpload, ImageUploads $images): JsonResponse
    {
        return response()->json($this->present($images->abandon($request->user(), $imageUpload)))->header('Cache-Control', 'private, no-store');
    }

    private function present(ImageUpload $upload): array
    {
        return ['id' => $upload->id, 'status' => $upload->status,
            'attachment' => in_array($upload->status, ['ready', 'attached'], true) ? ImageUploads::attachment($upload) : null,
            ...($upload->status === 'failed' ? ['message' => 'Image hosting is unavailable. Select the file again or retry shortly.'] : [])];
    }
}
