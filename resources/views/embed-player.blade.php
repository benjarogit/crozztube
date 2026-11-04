<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <title>{{ $embedConfig['title'] }} - {{ $embedConfig['siteName'] }}</title>
    @if($faviconUrl)
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif
    @php
        $manifestPath = file_exists(base_path('build/manifest.json')) ? base_path('build/manifest.json') : base_path('build/.vite/manifest.json');
        $manifest = file_exists($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : [];
        $entry = $manifest['resources/js/embed-player.jsx'] ?? null;
        $entryCss = $entry['css'] ?? [];
        $collectImportedCss = function (array $item) use (&$collectImportedCss, $manifest, &$entryCss): void {
            foreach ($item['imports'] ?? [] as $import) {
                $dependency = $manifest[$import] ?? null;
                if (! is_array($dependency)) continue;
                $entryCss = array_merge($entryCss, $dependency['css'] ?? []);
                $collectImportedCss($dependency);
            }
        };
        if ($entry) $collectImportedCss($entry);
        $entryCss = array_values(array_unique($entryCss));
    @endphp
    @if($entry && $entryCss)
        @foreach($entryCss as $css)
            <link rel="stylesheet" href="/build/{{ $css }}">
        @endforeach
    @endif
    <script>window.__PLAYHUB_EMBED__ = @json($embedConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);</script>
</head>
<body>
    <main id="embed-player-root">
        <noscript>Please enable JavaScript to play this video.</noscript>
    </main>
    @if($entry)
        <script type="module" src="/build/{{ $entry['file'] }}"></script>
    @else
        <div class="embed-state" role="alert">The video player assets are unavailable.</div>
    @endif
</body>
</html>
