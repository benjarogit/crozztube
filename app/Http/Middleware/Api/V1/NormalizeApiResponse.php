<?php

namespace App\Http\Middleware\Api\V1;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeApiResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response = \App\Http\Middleware\LocalizeResponseMessages::apply($response, $request);
        $requestId = AttachRequestId::for($request);

        if (! $response instanceof JsonResponse || $response->getStatusCode() === 204) {
            return $this->withHeaders($response, $requestId);
        }

        $status = $response->getStatusCode();
        // Keep JSON object/list distinctions in response data. Associative decoding
        // turns an empty capability or translation dictionary into a JSON list.
        $original = $response->getData();
        $body = $response->getData(true);
        $body = is_array($body) ? $body : ['value' => $body];

        if ($status >= 400) {
            $payload = isset($body['error']) && is_array($body['error'])
                ? $body
                : ApiErrorRenderer::payload(
                    ApiErrorRenderer::codeForStatus($status),
                    $this->errorMessage($body, $status),
                    $status,
                    is_array($body['errors'] ?? null) ? $body['errors'] : null,
                    $requestId,
                );

            $payload['error']['request_id'] = $requestId;
            $response->setData($payload);

            return $this->withHeaders($response, $requestId);
        }

        if ($this->isResourceEnvelope($body)) {
            $data = is_object($original) ? $original->data : $body['data'];
            $meta = $this->normalizeResourceMeta($body);
        } else {
            $data = is_object($original) || is_array($original) ? $original : $body;
            $meta = [];
        }

        $meta['request_id'] = $requestId;
        $response->setData(['data' => $data, 'meta' => $meta]);

        return $this->withHeaders($response, $requestId);
    }

    private function isResourceEnvelope(array $body): bool
    {
        if (! array_key_exists('data', $body)) {
            return false;
        }

        $keys = array_keys($body);

        return count(array_diff($keys, ['data', 'links', 'meta'])) === 0;
    }

    private function normalizeResourceMeta(array $body): array
    {
        $source = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        $paginationKeys = ['current_page', 'from', 'last_page', 'path', 'per_page', 'to', 'total'];
        $hasPageMeta = count(array_intersect($paginationKeys, array_keys($source))) > 0;
        $meta = $hasPageMeta ? array_diff_key($source, array_flip($paginationKeys)) : $source;

        if ($hasPageMeta) {
            $meta['pagination'] = [
                'page' => (int) ($source['current_page'] ?? 1),
                'per_page' => (int) ($source['per_page'] ?? 0),
                'total' => (int) ($source['total'] ?? 0),
                'last_page' => (int) ($source['last_page'] ?? 1),
                'has_more' => (int) ($source['current_page'] ?? 1) < (int) ($source['last_page'] ?? 1),
            ];
        }

        if (isset($source['next_cursor']) || isset($source['prev_cursor'])) {
            $meta['pagination'] = [
                'next_cursor' => $source['next_cursor'] ?? null,
                'previous_cursor' => $source['prev_cursor'] ?? null,
                'per_page' => (int) ($source['per_page'] ?? 0),
                'has_more' => filled($source['next_cursor'] ?? null),
            ];
        }

        if (is_array($body['links'] ?? null)) {
            $meta['links'] = array_filter([
                'next' => $body['links']['next'] ?? null,
                'previous' => $body['links']['prev'] ?? null,
            ], static fn ($value): bool => $value !== null);
        }

        return $meta;
    }

    private function errorMessage(array $body, int $status): string
    {
        $message = trim((string) ($body['message'] ?? ''));

        if ($message !== '' && $status < 500) {
            return $message;
        }

        return ApiErrorRenderer::defaultMessage($status);
    }

    private function withHeaders(Response $response, string $requestId): Response
    {
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-PlayHub-API-Version', '1');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
