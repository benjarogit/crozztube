<?php

namespace App\Exceptions;

use App\Http\Middleware\Api\V1\AttachRequestId;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeviceAuthorizationException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $responseHeaders = [],
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        $requestId = AttachRequestId::for($request);
        $error = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'status' => $this->httpStatus,
            'request_id' => $requestId,
        ];

        if ($this->details !== []) {
            $error['details'] = $this->details;
        }

        return response()->json(['error' => $error], $this->httpStatus, array_merge([
            'X-Request-ID' => $requestId,
            'X-PlayHub-API-Version' => '1',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ], $this->responseHeaders));
    }
}
