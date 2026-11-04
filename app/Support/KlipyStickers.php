<?php

namespace App\Support;

use App\Models\Sticker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/** Direct-client KLIPY integration: never proxies API requests or caches media. */
class KlipyStickers
{
    public const CDN_HOSTS = ['static.klipy.com', 'static1.klipy.com', 'static2.klipy.com'];
    public const MAX_SELECTION_LENGTH = 16384;

    public function catalog(Request $request, ?int $page = null, ?int $perPage = null, ?string $category = null): array
    {
        $settings = KlipyStickerSettings::settings();
        $configured = $settings['api_key'] !== '';
        $enabled = (bool) $settings['enabled'];
        $perPage = max(8, min(50, $perPage ?? (int) $settings['per_page']));

        return [
            'provider' => 'klipy', 'transport' => 'direct',
            'configured' => $configured, 'enabled' => $enabled,
            'message' => ! $enabled ? 'Stickers are disabled on this website.' : (! $configured ? 'KLIPY stickers have not been configured by the website administrator.' : null),
            'client' => $configured && $enabled ? $this->clientConfiguration($request, $settings) : null,
            // Catalog results belong to KLIPY and are fetched by the end-user client.
            'stickers' => [], 'categories' => [],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'per_page' => $perPage, 'total' => 0, 'has_more' => false],
        ];
    }

    public function clientConfiguration(Request $request, ?array $settings = null): array
    {
        $settings ??= KlipyStickerSettings::settings();
        $parts = preg_split('/[_-]/', (string) $settings['locale']);
        $locale = strtolower((string) end($parts));
        if (! preg_match('/^[a-z]{2}$/D', $locale)) {
            $locale = 'us';
        }

        return [
            'app_key' => $settings['api_key'],
            'base_url' => 'https://api.klipy.com/api/v1',
            'locale' => $locale, 'content_filter' => $settings['content_filter'],
            'per_page' => max(8, min(50, (int) $settings['per_page'])),
            'customer_id' => hash_hmac('sha256', 'klipy:'.($request->user()?->id ?? 'guest'), (string) config('app.key')),
            'live_price_cents' => (int) $settings['live_price_cents'],
            'currency' => strtoupper(IntegrationSettings::currency()),
        ];
    }

    /** Validate a selected remote reference, not downloaded/provider-owned content. */
    public function registerSelection(array $selection, ?Request $request = null): array
    {
        abort_unless(KlipyStickerSettings::isAvailable(), 503, 'KLIPY stickers are not configured or are disabled.');
        $settings = KlipyStickerSettings::settings();
        $slug = (string) ($selection['slug'] ?? '');
        $name = (string) ($selection['name'] ?? '');
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,239}$/D', $slug) || trim($name) === '' || mb_strlen($name) > 80) {
            $this->invalidSelection();
        }
        $image = self::imageUrl($selection['image_url'] ?? null);
        $preview = self::imageUrl($selection['preview_url'] ?? $image);
        if (! $image || ! $preview) {
            $this->invalidSelection();
        }
        $payload = [
            'v' => 1, 'slug' => $slug, 'name' => trim($name),
            'image_url' => $image, 'preview_url' => $preview,
            'expires_at' => now()->addHours(6)->timestamp,
            'configuration' => $this->fingerprint($settings),
        ];
        $id = 'klipy:'.Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return [
            'id' => $id, 'provider' => 'klipy', 'slug' => $slug, 'name' => $payload['name'],
            'image_url' => $image, 'preview_url' => $preview, 'category' => 'KLIPY',
            'price_cents' => (int) $settings['live_price_cents'],
            'price' => number_format($settings['live_price_cents'] / 100, 2, '.', ''),
            'currency' => strtoupper(IntegrationSettings::currency()),
        ];
    }

    /** Store a remote reference only when a comment or payment actually uses it. */
    public function resolveSelection(int|string|null $selection, ?Request $request = null, bool $forReplay = false): ?Sticker
    {
        if ($selection === null || $selection === '') {
            return null;
        }
        if (! $forReplay && ! KlipyStickerSettings::isAvailable()) {
            $this->invalidSelection();
        }
        $settings = KlipyStickerSettings::settings();
        if (is_int($selection) || ctype_digit((string) $selection)) {
            $sticker = Sticker::query()->whereKey($selection)->first();
            if (! $sticker || (! $forReplay && (! $sticker->enabled || $sticker->provider !== 'klipy' || ! self::imageUrl($sticker->image_path)))) {
                $this->invalidSelection();
            }
            if (! $forReplay) {
                $sticker->forceFill(['price_cents' => (int) $settings['live_price_cents']])->save();
            }

            return $sticker;
        }
        if (! str_starts_with($selection, 'klipy:') || strlen($selection) > self::MAX_SELECTION_LENGTH) {
            $this->invalidSelection();
        }
        try {
            $payload = json_decode(Crypt::decryptString(substr($selection, 6)), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->invalidSelection();
        }
        if (! is_array($payload) || ($payload['v'] ?? null) !== 1 || ! is_string($payload['slug'] ?? null)
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,239}$/D', $payload['slug'])
            || ! is_string($payload['name'] ?? null) || mb_strlen($payload['name']) > 80
            || ! self::imageUrl($payload['image_url'] ?? null)) {
            $this->invalidSelection();
        }
        if ($forReplay) {
            $sticker = Sticker::query()->where('provider', 'klipy')->where('reference_hash', $this->referenceHash($payload))->first();
            if (! $sticker) {
                $this->invalidSelection();
            }

            return $sticker;
        }
        if (($payload['expires_at'] ?? 0) < now()->timestamp || ! hash_equals($this->fingerprint($settings), (string) ($payload['configuration'] ?? ''))) {
            $this->invalidSelection();
        }

        $referenceHash = $this->referenceHash($payload);
        $sticker = Sticker::query()->firstOrCreate(
            ['provider' => 'klipy', 'reference_hash' => $referenceHash],
            ['slug' => 'klipy-'.$referenceHash, 'provider_id' => $payload['slug'], 'name' => $payload['name'],
                'image_path' => $payload['image_url'], 'category' => 'KLIPY',
                'enabled' => true, 'price_cents' => (int) $settings['live_price_cents'], 'sort_order' => 0],
        );
        if (! $sticker->enabled) {
            $this->invalidSelection();
        }
        // Client-supplied references never rewrite another user's historical media.
        $sticker->forceFill(['price_cents' => (int) $settings['live_price_cents']])->save();

        return $sticker;
    }

    /** Preserve the exact approved CDN URL, including provider delivery parameters. */
    public static function imageUrl(?string $path): ?string
    {
        if (! is_string($path) || strlen($path) > 2048 || preg_match('/[\x00-\x20\\\\]/', $path)) {
            return null;
        }
        $url = parse_url($path);
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https'
            || ! in_array(strtolower($url['host'] ?? ''), self::CDN_HOSTS, true)
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || (isset($url['port']) && $url['port'] !== 443)
            || ! preg_match('/\.(gif|webp|png)$/iD', $url['path'] ?? '')) {
            return null;
        }

        return $path;
    }

    private function fingerprint(array $settings): string
    {
        return hash('sha256', json_encode([$settings['api_key'], $settings['enabled'], $settings['content_filter'], $settings['locale'], $settings['live_price_cents']], JSON_THROW_ON_ERROR));
    }

    private function referenceHash(array $payload): string
    {
        return hash('sha256', json_encode([$payload['slug'], $payload['name'], $payload['image_url']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function invalidSelection(): never
    {
        throw ValidationException::withMessages(['sticker_id' => ['This sticker is unavailable or its selection has expired. Please choose it again.']]);
    }
}
