<?php

namespace App\Support;

class LiveKitDockerStatus
{
    public static function read(string $statusPath = 'livekit/runtime/status/docker-status.json'): array
    {
        if (app()->environment('testing')) return self::unavailable();
        if (! LiveKitSettings::projectPathIsValid($statusPath)) return self::unavailable();
        $path = base_path($statusPath);
        if (! is_file($path) || ! is_readable($path) || filesize($path) > 131072) {
            return self::unavailable();
        }

        $status = json_decode((string) @file_get_contents($path), true);
        if (! is_array($status) || ! is_array($status['container'] ?? null)) {
            return self::unavailable();
        }

        $updatedAt = trim((string) ($status['updated_at'] ?? ''));
        $updatedTimestamp = $updatedAt !== '' ? strtotime($updatedAt) : false;
        $container = $status['container'];
        $image = is_array($status['image'] ?? null) ? $status['image'] : [];
        $ingressContainer = is_array($status['ingress_container'] ?? null) ? $status['ingress_container'] : [];
        $ingressImage = is_array($status['ingress_image'] ?? null) ? $status['ingress_image'] : [];
        $egressContainer = is_array($status['egress_container'] ?? null) ? $status['egress_container'] : [];
        $egressImage = is_array($status['egress_image'] ?? null) ? $status['egress_image'] : [];

        return [
            'available' => true,
            'stale' => $updatedTimestamp === false || $updatedTimestamp < time() - 30,
            'updated_at' => $updatedAt,
            'container' => self::container($container),
            'image' => self::image($image),
            'ingress_container' => self::container($ingressContainer),
            'ingress_image' => self::image($ingressImage),
            'egress_container' => self::container($egressContainer),
            'egress_image' => self::image($egressImage),
        ];
    }

    private static function unavailable(): array
    {
        return [
            'available' => false,
            'stale' => true,
            'updated_at' => '',
            'container' => [],
            'image' => [],
            'ingress_container' => [],
            'ingress_image' => [],
            'egress_container' => [],
            'egress_image' => [],
        ];
    }

    private static function container(array $container): array
    {
        return [
            'exists' => (bool) ($container['exists'] ?? false),
            'name' => self::text($container['name'] ?? '', 128),
            'id' => self::text($container['id'] ?? '', 128),
            'image' => self::text($container['image'] ?? '', 255),
            'image_id' => self::text($container['image_id'] ?? '', 128),
            'status' => self::status($container['status'] ?? 'unknown'),
            'running' => (bool) ($container['running'] ?? false),
            'restarting' => (bool) ($container['restarting'] ?? false),
            'restart_count' => max(0, (int) ($container['restart_count'] ?? 0)),
            'exit_code' => (int) ($container['exit_code'] ?? 0),
            'health' => self::status($container['health'] ?? 'none'),
            'started_at' => self::text($container['started_at'] ?? '', 80),
            'finished_at' => self::text($container['finished_at'] ?? '', 80),
            'error' => self::text($container['error'] ?? '', 500),
        ];
    }

    private static function image(array $image): array
    {
        return [
            'id' => self::text($image['id'] ?? '', 128),
            'tags' => array_values(array_slice(array_filter(array_map(fn ($tag): string => self::text($tag, 255), (array) ($image['tags'] ?? []))), 0, 20)),
            'created' => self::text($image['created'] ?? '', 80),
            'size' => max(0, (int) ($image['size'] ?? 0)),
            'architecture' => self::text($image['architecture'] ?? '', 40),
            'os' => self::text($image['os'] ?? '', 40),
        ];
    }

    private static function status(mixed $value): string
    {
        $status = strtolower(self::text($value, 32));

        return in_array($status, ['created', 'running', 'restarting', 'paused', 'exited', 'dead', 'removing', 'missing', 'healthy', 'unhealthy', 'starting', 'none', 'unknown'], true)
            ? $status
            : 'unknown';
    }

    private static function text(mixed $value, int $length): string
    {
        return mb_substr(trim((string) $value), 0, $length);
    }
}
