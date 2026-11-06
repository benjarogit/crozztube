<?php

namespace App\Http\Middleware;

use App\Services\LocalizationService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LocalizeResponseMessages
{
    public function handle(Request $request, Closure $next): Response
    {
        return self::apply($next($request), $request);
    }

    public static function apply(Response $response, Request $request): Response
    {
        if (! $response instanceof JsonResponse) return $response;
        // Preserve objects/lists and never descend into user or provider data.
        $body = $response->getData();
        if (! is_object($body)) return $response;
        $service = app(LocalizationService::class);
        $locale = $service->resolve($request->header('X-PlayHub-Language') ?: $request->header('X-PlayHub-Locale') ?: $request->getPreferredLanguage());
        $hasMessages = false;
        $translate = static fn (string $message): string => $service->translate($message, $locale);
        if (isset($body->message) && is_string($body->message)) {
            $hasMessages = true;
            $body->message = $translate($body->message);
        }
        if (isset($body->error) && is_object($body->error) && isset($body->error->message) && is_string($body->error->message)) {
            $hasMessages = true;
            $body->error->message = $translate($body->error->message);
        }
        foreach ([$body, isset($body->error) && is_object($body->error) ? $body->error : null] as $container) {
            if ($container === null) continue;
            foreach (['errors', 'validation'] as $property) {
                if (! isset($container->$property) || ! is_object($container->$property)) continue;
                foreach ($container->$property as $field => $messages) {
                    if (! is_array($messages)) continue;
                    $hasMessages = true;
                    $container->$property->$field = array_map(static fn ($message) => is_string($message) ? $translate($message) : $message, $messages);
                }
            }
        }
        if ($hasMessages) {
            $response->setData($body);
            $response->setVary(['X-PlayHub-Language', 'X-PlayHub-Locale', 'Accept-Language'], false);
        }

        return $response;
    }
}
