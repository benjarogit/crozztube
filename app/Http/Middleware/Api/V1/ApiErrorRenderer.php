<?php

namespace App\Http\Middleware\Api\V1;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiErrorRenderer
{
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/v1', 'api/v1/*')) {
            return null;
        }

        [$status, $code, $message, $validation] = self::details($exception);
        $requestId = AttachRequestId::for($request);
        $response = response()->json(self::payload($code, $message, $status, $validation, $requestId), $status);
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-PlayHub-API-Version', '1');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if ($request->is('api/v1/build/license/verify')) {
            $response->headers->set('Cache-Control', 'private, no-store');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Referrer-Policy', 'no-referrer');
        }

        if ($exception instanceof HttpExceptionInterface) {
            foreach ($exception->getHeaders() as $name => $value) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    public static function payload(
        string $code,
        string $message,
        int $status,
        ?array $validation,
        string $requestId,
    ): array {
        $error = [
            'code' => $code,
            'message' => $message,
            'status' => $status,
            'request_id' => $requestId,
        ];

        if ($validation !== null && $validation !== []) {
            $error['validation'] = $validation;
        }

        return ['error' => $error];
    }

    public static function codeForStatus(int $status): string
    {
        return match ($status) {
            400 => 'bad_request',
            401 => 'unauthenticated',
            403 => 'forbidden',
            404 => 'resource_not_found',
            405 => 'method_not_allowed',
            406 => 'not_acceptable',
            409 => 'conflict',
            410 => 'resource_gone',
            413 => 'payload_too_large',
            415 => 'unsupported_media_type',
            422 => 'validation_failed',
            429 => 'rate_limit_exceeded',
            503 => 'service_unavailable',
            default => $status >= 500 ? 'internal_error' : 'request_failed',
        };
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'The request could not be processed.',
            401 => 'Authentication is required.',
            403 => 'You are not allowed to perform this action.',
            404 => 'The requested resource was not found.',
            405 => 'This method is not supported for the requested endpoint.',
            406 => 'No acceptable representation is available for this client.',
            409 => 'The request conflicts with the current resource state.',
            410 => 'The requested resource is no longer available.',
            413 => 'The request payload is too large.',
            415 => 'The request media type is not supported.',
            422 => 'The submitted data is invalid.',
            429 => 'Too many requests. Please try again later.',
            503 => 'The service is temporarily unavailable.',
            default => 'An unexpected server error occurred.',
        };
    }

    private static function details(Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return [422, 'validation_failed', 'The submitted data is invalid.', $exception->errors()];
        }

        if ($exception instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Authentication is required.', null];
        }

        if ($exception instanceof AuthorizationException) {
            return [403, 'forbidden', self::safeClientMessage($exception->getMessage(), 403), null];
        }

        if ($exception instanceof ModelNotFoundException) {
            return [404, 'resource_not_found', self::defaultMessage(404), null];
        }

        if ($exception instanceof ThrottleRequestsException) {
            return [429, 'rate_limit_exceeded', self::defaultMessage(429), null];
        }

        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();

            return [
                $status,
                self::codeForStatus($status),
                self::safeClientMessage($exception->getMessage(), $status),
                null,
            ];
        }

        return [500, 'internal_error', self::defaultMessage(500), null];
    }

    private static function safeClientMessage(string $message, int $status): string
    {
        $message = trim($message);

        return $status < 500 && $message !== '' ? $message : self::defaultMessage($status);
    }
}
