<?php

declare(strict_types=1);

namespace App\Studio;

use App\Activation\ActivationRegistry;
use App\Broadcasting\BroadcastManager;
use App\Config\ConfigCache;
use App\Events\EventDispatcher;
use App\Foundation\Application;
use App\Frontend\AssetManifest;
use App\Health\HealthManager;
use App\Profiler\ProfilerManager;
use App\Queue\QueueManager;
use App\Queue\QueueStatusDriver;
use App\Redis\RedisManager;
use App\Routing\RouteCache;
use App\Routing\RouteRegistry;
use App\Scheduler\ScheduleLoader;
use App\Scheduler\Scheduler;
use Throwable;

/**
 * Bounded local inspection of existing Application metadata. Studio never
 * resolves handlers, invokes Package lifecycle hooks, or probes a backend
 * while building its ordinary snapshot. Route inspection loads normal route
 * declarations; the Scheduler page alone loads schedule declarations. Neither
 * path executes registered handlers or tasks. Results distinguish known,
 * configured, observed, and unavailable state.
 */
final class StudioInspector
{
    private bool $routesLoaded = false;
    /** @var list<array<string,mixed>>|null */
    private ?array $cachedRoutes = null;
    /** @var array<string,mixed>|null */
    private ?array $activationSnapshot = null;
    private bool $schedulerLoadAttempted = false;
    private bool $schedulerLoaded = false;

    public function __construct(private Application $app)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        return [
            'overview' => $this->overview(),
            'routes' => $this->routes(),
            'activation' => $this->activation(),
            'profiles' => $this->profiles(),
            'health' => $this->health(),
            'queue' => $this->queue(),
            'scheduler' => $this->scheduler(),
            'events' => $this->events(),
            'broadcast' => $this->broadcast(),
            'infrastructure' => $this->infrastructure(),
            'contract' => $this->contract(),
            'observations' => $this->observations(),
        ];
    }

    /**
     * Overview reads only selected configuration fields and metadata status.
     * Cache status checks file fingerprints, never cached configuration values.
     *
     * @return array<string,mixed>
     */
    public function overview(): array
    {
        $config = $this->app->config();
        $routeCache = ['state' => 'unavailable', 'files' => 0, 'routes' => 0];
        $configCache = ['active' => false, 'status' => 'unavailable',
            'created_at' => null, 'files' => 0];
        try {
            $status = (new RouteCache($this->app))->status();
            $routeCache = ['state' => $status['state'], 'files' => $status['files'],
                'routes' => $status['routes']];
        } catch (Throwable) {
            // A stale or unsafe source is unavailable, not a reason to load PHP.
        }
        try {
            $configCache = $this->app->container()->make(ConfigCache::class)
                ->status($this->app->configPath(), $this->app->container()->make(\App\Foundation\Environment::class));
        } catch (Throwable) {
            // Cache metadata is optional; never disclose the underlying path.
        }
        $applicationName = $config->get('app.name', 'SqueHub');
        $basePath = $config->get('http.base_path', '');
        $activation = $this->activation();
        $packages = $activation['state'] === 'observed'
            ? count($this->activationSnapshot['packages']) : null;
        $kits = $activation['state'] === 'observed'
            ? count($this->activationSnapshot['kits']) : null;
        $redis = $this->redisCapability();
        return [
            'state' => 'known',
            'application' => [
                'name' => self::text($applicationName, 128, 'SqueHub'),
                'environment' => self::text($this->app->environment(), 32, 'unknown'),
                'php' => PHP_VERSION,
                'framework' => 'SqueHub v2',
                'base_path' => self::text($basePath, 128, ''),
                'debug' => $this->app->isDebug(),
            ],
            'profiler' => ['enabled' => $this->profilerEnabled()],
            'route_cache' => $routeCache,
            'config_cache' => $configCache,
            'frontend' => $this->frontendStatus(),
            'drivers' => $this->driverLabels(),
            'health' => $this->health()['live']['status'] ?? 'unavailable',
            'package_count' => $packages,
            'kit_count' => $kits,
            'redis' => $redis['status'],
        ];
    }

    /**
     * Report only selected frontend capability and local build validity.
     * Studio never starts Node, reaches Vite, or exposes development URLs,
     * source paths, manifest contents, or application configuration values.
     *
     * @return array{adapter:string,development:string,manifest:string}
     */
    private function frontendStatus(): array
    {
        $config = $this->app->config();
        $adapter = $config->get('frontend.adapter', 'none');
        if ($adapter === 'none') {
            return ['adapter' => 'none', 'development' => 'not_required',
                'manifest' => 'not_required'];
        }
        if ($adapter !== 'vite') {
            return ['adapter' => 'invalid', 'development' => 'unavailable',
                'manifest' => 'unavailable'];
        }
        $enabled = $config->get('frontend.development.enabled', false);
        $development = is_bool($enabled)
            ? ($enabled ? 'configured' : 'disabled') : 'invalid';
        $build = $config->get('frontend.build', []);
        if (!is_array($build) || !is_string($build['directory'] ?? null)
            || !is_string($build['manifest'] ?? null)) {
            return ['adapter' => 'vite', 'development' => $development,
                'manifest' => 'unavailable'];
        }
        try {
            (new AssetManifest($this->app->basePath(),
                $build['directory'], $build['manifest']))->fingerprint();
            $manifest = 'valid';
        } catch (Throwable) {
            $manifest = 'unavailable';
        }
        return ['adapter' => 'vite', 'development' => $development,
            'manifest' => $manifest];
    }

    /**
     * Use the registered routes seen by route:list. A cache is optional: the
     * normal loader executes declaration PHP on a miss, while this projection
     * never invokes a handler, middleware, binding, or contract callback.
     *
     * @return array{state:string,items:list<array<string,mixed>>,truncated:bool}
     */
    public function routes(int $limit = 200): array
    {
        $limit = self::limit($limit);
        $rows = $this->routeRows();
        if ($rows === null) return self::unavailableList();
        return ['state' => 'observed', 'items' => array_slice($rows, 0, $limit),
            'truncated' => count($rows) > $limit];
    }

    /** @return array<string,mixed> */
    public function activation(int $limit = 100): array
    {
        $limit = self::limit($limit);
        try {
            $snapshot = $this->activationSnapshot ??=
                $this->app->container()->make(ActivationRegistry::class)->snapshot();
            $project = static function (array $rows, string $kind, int $max): array {
                $items = [];
                foreach (array_slice($rows, 0, $max) as $row) {
                    if (!is_array($row)) continue;
                    $items[] = [
                        'kind' => $kind,
                        'name' => $row['name'] ?? '',
                        'status' => $row['status'] ?? 'unknown',
                        'installed' => ($row['installed'] ?? false) === true,
                        'enabled' => ($row['enabled'] ?? false) === true,
                        'registered' => ($row['registered'] ?? false) === true,
                        'source_kind' => $row['source_kind'] ?? 'unmanaged',
                        'dependencies' => array_slice(is_array($row['dependencies'] ?? null)
                            ? $row['dependencies'] : [], 0, 25),
                    ];
                }
                return ['items' => $items, 'truncated' => count($rows) > $max];
            };
            $packages = is_array($snapshot['packages'] ?? null) ? $snapshot['packages'] : [];
            $kits = is_array($snapshot['kits'] ?? null) ? $snapshot['kits'] : [];
            return ['state' => 'observed',
                'packages' => $project($packages, 'package', $limit),
                'kits' => $project($kits, 'kit', $limit)];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'packages' => self::unavailableList(),
                'kits' => self::unavailableList()];
        }
    }

    /** @return array{state:string,items:list<array<string,mixed>>,truncated:bool} */
    public function profiles(int $limit = 20): array
    {
        $limit = min(self::limit($limit), 20);
        if (!$this->profilerEnabled()
            || !$this->app->container()->has(ProfilerManager::class)) {
            return self::unavailableList();
        }
        try {
            $records = $this->app->container()->make(ProfilerManager::class)->latest($limit + 1);
            $items = [];
            foreach (array_slice($records, 0, $limit) as $record) {
                $items[] = ['id' => $record->id, 'recorded_at' => $record->recordedAt,
                    'operation' => $record->operation, 'correlation_id' => $record->correlationId,
                    'duration_ms' => $record->durationMs, 'event_count' => count($record->events)];
            }
            return ['state' => 'observed', 'items' => $items,
                'truncated' => count($records) > $limit];
        } catch (Throwable) {
            return self::unavailableList();
        }
    }

    /** @return array<string,mixed>|null */
    public function profile(string $id): ?array
    {
        if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1 || !$this->profilerEnabled()
            || !$this->app->container()->has(ProfilerManager::class)) return null;
        try {
            return $this->app->container()->make(ProfilerManager::class)->find($id)?->toArray();
        } catch (Throwable) {
            return null;
        }
    }

    /** Only liveness is automatic; readiness and Doctor execute project checks or backend probes. */
    public function health(): array
    {
        try {
            $manager = $this->app->container()->has(HealthManager::class)
                ? $this->app->container()->make(HealthManager::class)
                : new HealthManager($this->app, $this->app->container());
            return ['state' => 'known', 'live' => $manager->live()->toArray(),
                'dependencies' => 'not_probed'];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'live' => null,
                'dependencies' => 'not_probed'];
        }
    }

    /** @return array{state:string,connection:string,driver:string,counts:?array} */
    public function queue(): array
    {
        $connection = self::text($this->app->config()->get('queue.default', 'sync'), 128, 'unknown');
        $connections = $this->app->config()->get('queue.connections', []);
        $settings = is_array($connections) ? ($connections[$connection] ?? null) : null;
        $driver = is_array($settings) ? ($settings['driver'] ?? null) : null;
        return ['state' => 'configured', 'connection' => $connection,
            'driver' => self::driver($driver), 'counts' => null];
    }

    /** A deliberate Queue-page read; never called by snapshot() or overview(). */
    public function queueStatus(): array
    {
        $result = $this->queue();
        if (!$this->app->container()->has(QueueManager::class)) {
            $result['state'] = 'unavailable';
            return $result;
        }
        try {
            $driver = $this->app->container()->make(QueueManager::class)->driver($result['connection']);
            if (!$driver instanceof QueueStatusDriver) return $result;
            $status = $driver->status('default');
            $result['state'] = 'observed';
            $result['counts'] = ['ready' => $status->ready, 'delayed' => $status->delayed,
                'reserved' => $status->reserved, 'failed' => $status->failed];
        } catch (Throwable) {
            $result['state'] = 'unavailable';
        }
        return $result;
    }

    /**
     * Project existing definitions without running a task. The ordinary
     * snapshot reads memory only; the Scheduler page loads declarations first.
     *
     * @return array{state:string,items:list<array<string,mixed>>,truncated:bool}
     */
    public function scheduler(int $limit = 100): array
    {
        if (!$this->app->container()->has(Scheduler::class)) return self::unavailableList();
        $limit = self::limit($limit);
        try {
            $tasks = $this->app->container()->make(Scheduler::class)->inspectDefinitions();
            $items = [];
            foreach (array_slice($tasks, 0, $limit) as $task) {
                $items[] = $task;
            }
            return ['state' => 'observed', 'items' => $items,
                'truncated' => count($tasks) > $limit];
        } catch (Throwable) {
            return self::unavailableList();
        }
    }

    /**
     * The Scheduler panel follows schedule:list through its trusted definition
     * loader. Loading declarations does not run tasks; the ordinary dashboard
     * snapshot deliberately remains passive.
     *
     * @return array{state:string,items:list<array<string,mixed>>,truncated:bool}
     */
    public function registeredSchedules(int $limit = 100): array
    {
        if (!$this->schedulerLoadAttempted) {
            $this->schedulerLoadAttempted = true;
            try {
                (new ScheduleLoader())->load($this->app);
                $this->schedulerLoaded = true;
            } catch (Throwable) {
                return self::unavailableList();
            }
        }
        return $this->schedulerLoaded ? $this->scheduler($limit) : self::unavailableList();
    }

    /** @return array{state:string,items:list<array<string,mixed>>,truncated:bool} */
    public function events(int $limit = 100): array
    {
        if (!$this->app->container()->has(EventDispatcher::class)) return self::unavailableList();
        try {
            $summary = $this->app->container()->make(EventDispatcher::class)
                ->subscriptionsSummary(self::limit($limit));
            return ['state' => 'observed'] + $summary;
        } catch (Throwable) {
            return self::unavailableList();
        }
    }

    /** @return array<string,mixed> */
    public function broadcast(): array
    {
        if (!$this->app->container()->has(BroadcastManager::class)) {
            return ['state' => 'unavailable', 'enabled' => false, 'driver' => 'unknown',
                'patterns' => ['items' => [], 'truncated' => false]];
        }
        try {
            $summary = $this->app->container()->make(BroadcastManager::class)->inspection();
            return ['state' => 'configured', 'enabled' => $summary['enabled'],
                'driver' => $summary['driver'],
                'patterns' => ['items' => $summary['patterns'],
                    'truncated' => $summary['truncated']]];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'enabled' => false, 'driver' => 'unknown',
                'patterns' => ['items' => [], 'truncated' => false]];
        }
    }

    /** Static driver selections only; no cache keys, storage paths, or Redis endpoints. */
    public function infrastructure(): array
    {
        $config = $this->app->config();
        $connections = $config->get('database.connections', []);
        $database = [];
        if (is_array($connections)) {
            foreach (array_slice($connections, 0, 20, true) as $name => $settings) {
                if (!is_string($name) || !is_array($settings)) continue;
                $database[] = ['name' => self::text($name, 64, 'unknown'),
                    'driver' => self::driver($settings['driver'] ?? null)];
            }
        }
        $drives = $config->get('storage.drives', []);
        $storage = [];
        if (is_array($drives)) {
            foreach (array_slice($drives, 0, 20, true) as $name => $settings) {
                if (!is_string($name) || !is_array($settings)) continue;
                $driver = self::driver($settings['driver'] ?? null);
                $storage[] = ['name' => self::text($name, 64, 'unknown'),
                    'driver' => $driver,
                    'dependency_available' => $driver !== 's3'
                        || (class_exists('Aws\\S3\\S3Client')
                            && class_exists('Aws\\S3\\ObjectUploader')
                            && class_exists('Aws\\S3\\ObjectCopier'))];
            }
        }
        $mailName = self::text($config->get('mail.default', 'unknown'), 64, 'unknown');
        $transports = $config->get('mail.transports', []);
        $transport = is_array($transports) ? ($transports[$mailName] ?? null) : null;
        $mailDriver = is_array($transport) ? ($transport['driver'] ?? null) : null;
        $localeList = $config->get('translation.supported', ['en']);
        $locales = [];
        if (is_array($localeList)) {
            foreach (array_slice($localeList, 0, 32) as $locale) {
                if (is_string($locale) && preg_match('/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{2,8}){0,3}\z/D', $locale) === 1) {
                    $locales[] = $locale;
                }
            }
        }
        return ['state' => 'configured', 'drivers' => $this->driverLabels(),
            'database' => ['connections' => $database,
                'truncated' => is_array($connections) && count($connections) > 20],
            'storage' => ['default' => self::text($config->get('storage.default', 'local'),
                64, 'unknown'), 'drives' => $storage,
                'truncated' => is_array($drives) && count($drives) > 20],
            'mail' => ['default' => $mailName, 'driver' => self::driver($mailDriver),
                'sender_configured' => is_string($config->get('mail.from.address'))
                    && $config->get('mail.from.address') !== '',
                'provider_token_configured' => in_array($mailDriver, ['resend', 'postmark'], true)
                    ? is_string($transport['api_key'] ?? null) && $transport['api_key'] !== '' : null,
                'live_delivery_checked' => false],
            'cache' => ['driver' => self::driver($config->get('cache.driver', 'file')),
                'memcached_extension_available' => extension_loaded('memcached'),
                'reachability_checked' => false],
            // Static capability only: inspection must not read translation
            // catalogs, invoke Package code, or probe an external provider.
            'translation' => ['default' => self::text($config->get('translation.default', 'en'),
                    32, 'unknown'),
                'fallback' => self::text($config->get('translation.fallback', 'en'),
                    32, 'unknown'),
                'supported' => $locales, 'intl_available' => extension_loaded('intl')],
            'redis' => $this->redisCapability()];
    }

    /**
     * Aggregate only fixed subsystem categories from recent privacy-filtered
     * profiles. Metric names, SQL, destinations, and payloads are not returned.
     *
     * @return array<string,mixed>
     */
    public function observations(): array
    {
        $categories = array_fill_keys(['database', 'cache', 'events', 'queue',
            'scheduler', 'http_client', 'mail', 'notifications', 'storage'],
            ['count' => 0, 'time_ms' => 0.0]);
        if (!$this->profilerEnabled()
            || !$this->app->container()->has(ProfilerManager::class)) {
            return ['state' => 'unavailable', 'sample_size' => 0,
                'categories' => $categories];
        }
        try {
            $records = $this->app->container()->make(ProfilerManager::class)->latest(20);
            foreach ($records as $record) {
                foreach ($record->metrics as $name => $metric) {
                    $category = match (strstr($name, '.', true)) {
                        'db' => 'database',
                        'cache' => 'cache',
                        'events' => 'events',
                        'queue' => 'queue',
                        'scheduler' => 'scheduler',
                        'http_client' => 'http_client',
                        'mail' => 'mail',
                        'notifications' => 'notifications',
                        'storage' => 'storage',
                        default => null,
                    };
                    if ($category === null) continue;
                    $categories[$category]['count'] += $metric['count'];
                    $categories[$category]['time_ms'] += $metric['time_ms'];
                }
            }
            return ['state' => 'observed', 'sample_size' => count($records),
                'categories' => $categories];
        } catch (Throwable) {
            return ['state' => 'unavailable', 'sample_size' => 0,
                'categories' => $categories];
        }
    }

    /** Count contracts from the same live route projection as the Routes page. */
    public function contract(int $limit = 100): array
    {
        $rows = $this->routeRows();
        if ($rows === null) return self::unavailableList();
        $limit = self::limit($limit);
        $items = [];
        $count = 0;
        foreach ($rows as $row) {
            if ($row['contract'] !== true) continue;
            ++$count;
            if (count($items) >= $limit) continue;
            $items[] = ['methods' => $row['methods'], 'path' => $row['path'],
                'route_name' => $row['name'], 'api_version' => $row['api_version']];
        }
        return ['state' => 'observed', 'items' => $items, 'truncated' => $count > $limit];
    }

    /** @return list<array<string,mixed>>|null */
    private function routeRows(): ?array
    {
        if ($this->routesLoaded) return $this->cachedRoutes;
        $this->routesLoaded = true;
        try {
            $registry = $this->app->container()->make(RouteRegistry::class);
            $squehubApp = $this->app;
            $level = ob_get_level();
            ob_start();
            try {
                require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
            } finally {
                while (ob_get_level() > $level) ob_end_clean();
            }
            return $this->cachedRoutes = $registry->inspection();
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{database:string,queue:string,cache:string,session:string} */
    private function driverLabels(): array
    {
        $config = $this->app->config();
        $queue = $this->queue();
        $databaseName = $config->get('database.default');
        $connections = $config->get('database.connections', []);
        $database = is_string($databaseName) && is_array($connections)
            && is_array($connections[$databaseName] ?? null)
            ? ($connections[$databaseName]['driver'] ?? null) : null;
        return ['database' => self::driver($database),
            'queue' => $queue['driver'], 'cache' => self::driver($config->get('cache.driver')),
            'session' => self::driver($config->get('session.driver'))];
    }

    private function profilerEnabled(): bool
    {
        return $this->app->environment() === 'development'
            && $this->app->config()->get('profiler.enabled', false) === true;
    }

    /** @return array{status:string,client:?string} */
    private function redisCapability(): array
    {
        if (!$this->app->container()->has(RedisManager::class)) {
            return ['status' => 'unavailable', 'client' => null];
        }
        try {
            // false is important: no network PING on a dashboard read.
            return $this->app->container()->make(RedisManager::class)->capability(null, false);
        } catch (Throwable) {
            return ['status' => 'unavailable', 'client' => null];
        }
    }

    private static function driver(mixed $value): string
    {
        return is_string($value) && preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $value) === 1
            ? $value : 'unknown';
    }

    private static function text(mixed $value, int $max, string $default): string
    {
        return is_string($value) && strlen($value) <= $max
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1 ? $value : $default;
    }

    private static function limit(int $limit): int
    {
        return max(1, min($limit, 200));
    }

    /** @return array{state:string,items:array,truncated:bool} */
    private static function unavailableList(): array
    {
        return ['state' => 'unavailable', 'items' => [], 'truncated' => false];
    }
}
