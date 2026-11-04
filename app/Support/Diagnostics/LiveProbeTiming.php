<?php

namespace App\Support\Diagnostics;

use App\Http\Middleware\Api\V1\NormalizeApiResponse;
use App\Http\Middleware\Api\V1\RequireTokenAbility;
use App\Http\Middleware\MeasureLiveProbe;
use App\Http\Middleware\TrackNativeDeviceSession;
use App\Providers\PluginSystemServiceProvider;
use Closure;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpFoundation\Response;

/** Opt-in local diagnostics; records durations/counts, never SQL or credentials. */
final class LiveProbeTiming
{
    public const HEADER = 'X-PlayHub-Probe-Run';

    public const ATTRIBUTE = 'playhub.live_probe_timing';

    private const METRICS = [
        'pre_autoload', 'autoload', 'app_setup', 'pre_bootstrap', 'framework_bootstrap',
        'boot_environment', 'boot_configuration', 'boot_exceptions', 'boot_facades',
        'boot_register', 'boot_providers', 'plugin_boot', 'request_pipeline', 'php_request_handled',
        'auth', 'bindings', 'native_session', 'ability', 'envelope', 'controller',
        'owner_access', 'publishing_access', 'file_validation', 'initial_json', 'db',
    ];

    private const TABLES = [
        'personal_access_tokens', 'users', 'native_device_sessions', 'live_streams',
        'channels', 'playhub_plugins', 'other',
    ];

    private array $durations = [];

    private array $starts = [];

    private array $queries = [];

    private ?int $entry = null;

    public function __construct(private readonly string $runId, array $entryMarkers = [])
    {
        $entry = $entryMarkers['entry'] ?? null;
        $autoloadStart = $entryMarkers['autoload_start'] ?? null;
        $autoloadEnd = $entryMarkers['autoload_end'] ?? null;
        $appReady = $entryMarkers['app_ready'] ?? null;
        if (is_int($entry) && is_int($autoloadStart) && is_int($autoloadEnd)
            && $entry <= $autoloadStart && $autoloadStart <= $autoloadEnd && $autoloadEnd <= hrtime(true)) {
            $this->entry = $entry;
            $this->record('pre_autoload', ($autoloadStart - $entry) / 1e6);
            $this->record('autoload', ($autoloadEnd - $autoloadStart) / 1e6);
            if (is_int($appReady) && $appReady >= $autoloadEnd && $appReady <= hrtime(true)) {
                $this->record('app_setup', ($appReady - $autoloadEnd) / 1e6);
                $this->record('pre_bootstrap', (hrtime(true) - $appReady) / 1e6);
            }
        }
    }

    public static function eligible(Request $request): bool
    {
        $runId = $request->header(self::HEADER);
        if (! is_string($runId) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $runId)) {
            return false;
        }

        return ($request->isMethod('POST') && preg_match('#^api/(?:v1/)?live-streams/[^/]+/connection-probe$#D', $request->path()))
            || ($request->isMethod('GET') && in_array($request->path(), ['api/health', 'api/v1/bootstrap', 'api/v1/auth/me'], true));
    }

    public static function current(Request $request): ?self
    {
        $timing = $request->attributes->get(self::ATTRIBUTE);

        return $timing instanceof self ? $timing : null;
    }

    public static function measure(Request $request, string $name, Closure $operation): mixed
    {
        $timing = self::current($request);
        if (! $timing) {
            return $operation();
        }

        $started = hrtime(true);
        try {
            return $operation();
        } finally {
            $timing->record($name, (hrtime(true) - $started) / 1e6);
        }
    }

    public function begin(string $name): void
    {
        $this->starts[$name] = hrtime(true);
    }

    public function end(string $name): void
    {
        if (isset($this->starts[$name])) {
            $this->record($name, (hrtime(true) - $this->starts[$name]) / 1e6);
            unset($this->starts[$name]);
        }
    }

    public function record(string $name, float $milliseconds): void
    {
        if (in_array($name, self::METRICS, true) && is_finite($milliseconds) && $milliseconds >= 0 && $milliseconds <= 3_600_000) {
            $this->durations[$name] = ($this->durations[$name] ?? 0) + $milliseconds;
        }
    }

    public function query(QueryExecuted $event): void
    {
        // Parse only the first SQL table identifier. No query/bindings are retained.
        $table = 'other';
        if (preg_match('/\b(?:from|join|into|update)\s+["`\[]?([a-z_][a-z0-9_]*)/i', $event->sql, $match)
            && in_array(strtolower($match[1]), self::TABLES, true)) {
            $table = strtolower($match[1]);
        }
        $this->queries[$table]['count'] = ($this->queries[$table]['count'] ?? 0) + 1;
        $milliseconds = (float) $event->time;
        if (is_finite($milliseconds) && $milliseconds >= 0 && $milliseconds <= 3_600_000) {
            $this->queries[$table]['duration_ms'] = round(($this->queries[$table]['duration_ms'] ?? 0) + $milliseconds, 3);
        }
        $this->record('db', (float) $event->time);
    }

    public function decorate(Response $response): void
    {
        $this->end('request_pipeline');
        if ($this->entry !== null) {
            $this->record('php_request_handled', (hrtime(true) - $this->entry) / 1e6);
        }
        $metrics = [];
        foreach (self::METRICS as $name) {
            if (isset($this->durations[$name])) {
                $metrics[] = $name.';dur='.number_format($this->durations[$name], 3, '.', '');
            }
        }
        $response->headers->set('Server-Timing', implode(', ', $metrics));
        $response->headers->set(self::HEADER, $this->runId);
        $response->headers->set('X-PlayHub-Probe-Queries', json_encode($this->queries, JSON_THROW_ON_ERROR));
        $response->headers->set('X-PlayHub-Probe-Runtime', json_encode(self::runtime(), JSON_THROW_ON_ERROR));
        $response->headers->set('Cache-Control', 'private, no-store');
    }

    /** Install before HTTP bootstrapping so provider/DB work is observable. */
    public static function install(Application $app): void
    {
        $installedAt = hrtime(true);
        $bootstrapTiming = null;
        $bootstrappers = [
            LoadEnvironmentVariables::class => 'boot_environment',
            LoadConfiguration::class => 'boot_configuration',
            HandleExceptions::class => 'boot_exceptions',
            RegisterFacades::class => 'boot_facades',
            RegisterProviders::class => 'boot_register',
            BootProviders::class => 'boot_providers',
        ];
        foreach ($bootstrappers as $bootstrapper => $name) {
            $app->beforeBootstrapping($bootstrapper, function (Application $app) use ($bootstrapper, $name, $installedAt, &$bootstrapTiming): void {
                if ($bootstrapper === LoadEnvironmentVariables::class && $app->bound('request') && self::eligible($app['request'])) {
                    $request = $app['request'];
                    $markers = $request->server->get('PLAYHUB_PROBE_ENTRY', []);
                    $markers = is_array($markers) ? $markers : [];
                    $markers['app_ready'] = $installedAt;
                    $bootstrapTiming = new self(strtolower($request->header(self::HEADER)), $markers);
                    $request->attributes->set(self::ATTRIBUTE, $bootstrapTiming);
                    $bootstrapTiming->begin('framework_bootstrap');
                }
                $bootstrapTiming?->begin($name);
            });
            $app->afterBootstrapping($bootstrapper, function (Application $app) use ($bootstrapper, $name, &$bootstrapTiming): void {
                $bootstrapTiming?->end($name);
                if ($bootstrapper === LoadConfiguration::class) {
                    if (! $app->environment(['local', 'testing'])) {
                        if ($app->bound('request')) {
                            $app['request']->attributes->remove(self::ATTRIBUTE);
                        }
                        $bootstrapTiming = null;

                        return;
                    }
                    $app->make(Kernel::class)->prependMiddleware(MeasureLiveProbe::class);
                    $app['events']->listen(QueryExecuted::class, function (QueryExecuted $event) use ($app): void {
                        if ($app->environment(['local', 'testing']) && $app->bound('request')) {
                            self::current($app['request'])?->query($event);
                        }
                    });
                    $app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($app): void {
                        if ($app->environment(['local', 'testing'])) {
                            self::current($event->request)?->decorate($event->response);
                        }
                    });
                }
                if ($bootstrapper === RegisterProviders::class && $bootstrapTiming) {
                    $provider = $app->getProvider(PluginSystemServiceProvider::class);
                    $provider?->booting(fn () => $bootstrapTiming->begin('plugin_boot'));
                    $provider?->booted(fn () => $bootstrapTiming->end('plugin_boot'));
                }
                if ($bootstrapper === BootProviders::class) {
                    $bootstrapTiming?->end('framework_bootstrap');
                }
            });
        }
    }

    public static function observeMiddleware(Application $app): void
    {
        if ($app->bound('playhub.probe.middleware_observed')) {
            return;
        }
        $app->instance('playhub.probe.middleware_observed', true);
        foreach ([
            Authenticate::class => 'auth',
            SubstituteBindings::class => 'bindings',
            TrackNativeDeviceSession::class => 'native_session',
            RequireTokenAbility::class => 'ability',
            NormalizeApiResponse::class => 'envelope',
        ] as $middleware => $name) {
            $app->extend($middleware, fn (object $original) => new ObservedProbeMiddleware($original, $name));
        }
    }

    private static function runtime(): array
    {
        $status = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
        $statistics = is_array($status) ? ($status['opcache_statistics'] ?? []) : [];
        $iniPath = php_ini_loaded_file();
        $runtime = [
            'pid' => getmypid(),
            'sapi' => substr(PHP_SAPI, 0, 32),
            'php_version' => substr(PHP_VERSION, 0, 32),
            'loaded_ini' => is_string($iniPath) ? substr($iniPath, 0, 256) : null,
            'opcache_extension' => extension_loaded('Zend OPcache'),
            'xdebug_extension' => extension_loaded('xdebug'),
            'opcache_enable' => ini_get('opcache.enable') === false ? null : ini_get('opcache.enable') === '1',
            'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps') === false ? null : ini_get('opcache.validate_timestamps') === '1',
            'opcache_revalidate_freq' => ini_get('opcache.revalidate_freq') === false ? null : max(0, min(3600, (int) ini_get('opcache.revalidate_freq'))),
            'opcache_enabled' => is_array($status) ? (bool) ($status['opcache_enabled'] ?? false) : null,
            'opcache_full' => is_array($status) ? (bool) ($status['cache_full'] ?? false) : null,
        ];
        foreach (['num_cached_scripts', 'num_cached_keys', 'hits', 'misses'] as $key) {
            $runtime[$key] = isset($statistics[$key]) && is_numeric($statistics[$key]) ? max(0, (int) $statistics[$key]) : null;
        }

        return $runtime;
    }
}
