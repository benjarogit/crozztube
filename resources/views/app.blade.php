<!doctype html>
<html lang="{{ str_replace('_', '-', \App\Support\GeneralSettings::settings()['defaultLanguage'] ?? config('app.locale', 'en')) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $websiteSettings = \App\Support\GeneralSettings::settings();
        $publicWebsiteSettings = \App\Support\GeneralSettings::publicSettings();
        $publicPlugins = [];
        $googleImaEnabled = false;
        try {
            $publicPlugins = app(\App\Plugins\PluginManager::class)->publicPlugins();
        } catch (\Throwable $exception) {
            report($exception);
        }
        try {
            $publicPlayerAds = \App\Support\PlayerAdSettings::publicSettings();
            $googleImaEnabled = ($publicPlayerAds['enabled'] ?? false) && collect($publicPlayerAds['ads'] ?? [])
                ->contains(fn (array $ad): bool => ($ad['engine'] ?? '') === 'google_ima');
        } catch (\Throwable $exception) {
            report($exception);
        }
        $playHubVersion = app(\App\Updates\VersionRepository::class)->current();
        $pageTitle = $websiteSettings['websiteTitle'] ?? '';
        if (empty($pageTitle)) {
            $pageTitle = $websiteSettings['platformName'] ?? '';
        }
        if (empty($pageTitle)) {
            $pageTitle = config('app.name', 'PlayHub');
        }
        $socialPreview = is_array($socialPreview ?? null) ? $socialPreview : [];
        $pageTitle = $socialPreview['browser_title'] ?? $pageTitle;
    @endphp
    <title>{{ $pageTitle }}</title>
    @if(!empty($socialPreview))
        <meta name="description" content="{{ $socialPreview['description'] }}">
        <meta name="robots" content="{{ $socialPreview['robots'] }}">
        @if(!empty($socialPreview['keywords']))
            <meta name="keywords" content="{{ $socialPreview['keywords'] }}">
        @endif
        @if(!empty($socialPreview['google_site_verification']))
            <meta name="google-site-verification" content="{{ $socialPreview['google_site_verification'] }}">
        @endif
        @if(!empty($socialPreview['bing_site_verification']))
            <meta name="msvalidate.01" content="{{ $socialPreview['bing_site_verification'] }}">
        @endif
        <link rel="canonical" href="{{ $socialPreview['url'] }}">
        <meta property="og:site_name" content="{{ $socialPreview['site_name'] }}">
        <meta property="og:locale" content="{{ $socialPreview['locale'] }}">
        <meta property="og:type" content="{{ $socialPreview['type'] }}">
        <meta property="og:title" content="{{ $socialPreview['title'] }}">
        <meta property="og:description" content="{{ $socialPreview['description'] }}">
        <meta property="og:url" content="{{ $socialPreview['url'] }}">
        <meta property="og:image" content="{{ $socialPreview['image'] }}">
        <meta property="og:image:url" content="{{ $socialPreview['image'] }}">
        @if(str_starts_with($socialPreview['image'], 'https://'))
            <meta property="og:image:secure_url" content="{{ $socialPreview['image'] }}">
        @endif
        @if(!empty($socialPreview['image_type']))
            <meta property="og:image:type" content="{{ $socialPreview['image_type'] }}">
        @endif
        @if(!empty($socialPreview['image_width']) && !empty($socialPreview['image_height']))
            <meta property="og:image:width" content="{{ $socialPreview['image_width'] }}">
            <meta property="og:image:height" content="{{ $socialPreview['image_height'] }}">
        @endif
        <meta property="og:image:alt" content="{{ $socialPreview['image_alt'] }}">
        @if(!empty($socialPreview['profile_username']))
            <meta property="profile:username" content="{{ $socialPreview['profile_username'] }}">
        @endif
        @if(!empty($socialPreview['published_time']))
            <meta property="{{ $socialPreview['type'] === 'article' ? 'article:published_time' : 'video:release_date' }}" content="{{ $socialPreview['published_time'] }}">
        @endif
        @if(!empty($socialPreview['video_duration']))
            <meta property="video:duration" content="{{ $socialPreview['video_duration'] }}">
        @endif
        <meta name="twitter:card" content="summary_large_image">
        @if(!empty($socialPreview['twitter_site']))
            <meta name="twitter:site" content="{{ $socialPreview['twitter_site'] }}">
        @endif
        <meta name="twitter:title" content="{{ $socialPreview['title'] }}">
        <meta name="twitter:description" content="{{ $socialPreview['description'] }}">
        <meta name="twitter:url" content="{{ $socialPreview['url'] }}">
        <meta name="twitter:image" content="{{ $socialPreview['image'] }}">
        <meta name="twitter:image:alt" content="{{ $socialPreview['image_alt'] }}">
        @foreach($socialPreview['custom_meta_tags'] ?? [] as $metaTag)
            @if($metaTag['attribute'] === 'property')
                <meta property="{{ $metaTag['key'] }}" content="{{ $metaTag['content'] }}" data-playhub-custom-meta="true">
            @else
                <meta name="{{ $metaTag['key'] }}" content="{{ $metaTag['content'] }}" data-playhub-custom-meta="true">
            @endif
        @endforeach
        @if(!empty($socialPreview['structured_data']))
            <script type="application/ld+json" id="playhub-structured-data">{!! json_encode($socialPreview['structured_data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
        @endif
    @endif
    @if(!empty($websiteSettings['faviconUrl']))
        <link rel="icon" href="{{ $websiteSettings['faviconUrl'] }}">
    @endif
    @if($googleImaEnabled)
        <script defer data-playhub-google-ima="true" src="https://imasdk.googleapis.com/js/sdkloader/ima3.js"></script>
    @endif
    <script>
        (function () {
            var website = @json($publicWebsiteSettings);
            var plugins = @json($publicPlugins);
            var preference = website.defaultThemeMode || 'system';
            try { preference = localStorage.getItem('playhub_theme_preference') || localStorage.getItem('playhub_theme') || preference; } catch (error) {}
            var saved = preference === 'system' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : preference;
            var recoverAssets = function () {
                var key = 'playhub_asset_recovery';
                var now = Date.now();
                try {
                    var previous = Number(sessionStorage.getItem(key) || 0);
                    if (previous && now - previous < 60000) return;
                    sessionStorage.setItem(key, String(now));
                } catch (error) {
                    return;
                }
                window.location.reload();
            };
            window.addEventListener('vite:preloadError', function (event) {
                event.preventDefault();
                recoverAssets();
            });
            window.addEventListener('error', function (event) {
                var target = event.target;
                var asset = target && (target.src || target.href);
                if (asset && new URL(asset, window.location.href).pathname.indexOf('/plugin-assets/') === 0) recoverAssets();
            }, true);
            window.__PLAYHUB_WEBSITE__ = website;
            window.__PLAYHUB_PLUGINS__ = plugins;
            window.__PLAYHUB_VERSION__ = @json($playHubVersion);
            document.documentElement.dataset.theme = saved;
            if (website && website.showSidebarToggle === false) {
                document.documentElement.classList.add('sidebar-toggle-disabled');
            }
        })();
    </script>
    @php
        $manifestCandidates = [
            base_path('build/manifest.json'),
            base_path('build/.vite/manifest.json'),
        ];

        $manifestPath = null;
        foreach ($manifestCandidates as $candidate) {
            if (file_exists($candidate)) {
                $manifestPath = $candidate;
                break;
            }
        }

        $manifest = $manifestPath ? json_decode(file_get_contents($manifestPath), true) : [];
        $entry = $manifest['resources/js/main.jsx'] ?? null;
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
    @foreach($publicPlugins as $plugin)
        @foreach($plugin['styles'] ?? [] as $style)
            <link rel="stylesheet" href="{{ $style }}">
        @endforeach
    @endforeach
</head>
<body>
    <div id="root">
        <noscript>Please enable JavaScript to use {{ config('app.name', 'PlayHub') }}.</noscript>
    </div>
    @if($entry)
        <script type="module" src="/build/{{ $entry['file'] }}"></script>
        @foreach($publicPlugins as $plugin)
            @foreach($plugin['scripts'] ?? [] as $script)
                <script type="module" src="{{ $script }}"></script>
            @endforeach
        @endforeach
    @else
        <div style="font-family:Arial,sans-serif;max-width:760px;margin:80px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px">
            <h1 style="margin:0 0 12px">{{ config('app.name', 'PlayHub') }} is installed</h1>
            <p style="color:#4b5563;line-height:1.6">React assets were not built yet. Run <code>npm install</code> and <code>npm run build</code> from the project root, then refresh this page.</p>
        </div>
    @endif
</body>
</html>
