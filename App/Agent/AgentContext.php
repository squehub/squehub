<?php

declare(strict_types=1);

namespace App\Agent;

use App\Foundation\Application;
use App\Routing\RouteCache;
use App\Routing\RouteRegistry;
use App\Studio\StudioInspector;
use Throwable;

/**
 * Version-grounded, detached framework context. It names only APIs present in
 * this release and uses Studio's established safe metadata projections.
 */
final class AgentContext
{
    public const VERSION = '2.0.0-dev';
    public const STDIO_PROTOCOL = '2025-11-25';

    /** @param list<array{name:string,description:string}>|null $cliInventory */
    public function __construct(private Application $app, private CapabilitySet $capabilities,
        private ?array $cliInventory = null)
    {
    }

    /** @return array<string,mixed> */
    public function framework(): array
    {
        return [
            'framework' => ['name' => 'SqueHub', 'version' => self::VERSION,
                'release' => 'unreleased'],
            'protocol' => ['transport' => 'stdio', 'supported_stdio' => self::STDIO_PROTOCOL],
            'application' => ['fingerprint' => $this->capabilities->applicationFingerprint()],
            'routing' => ['style' => 'path-first',
                'example' => "Route::path('/users/{id}')->get([UserController::class, 'show'])->named('users.show')->through('auth');"],
            'public_api' => $this->plugins(),
            'cli' => $this->cli(),
        ];
    }

    /** @return array<string,mixed> */
    public function applicationContract(): array
    {
        // Route-derived operations remain behind the route capability even
        // when a caller may read the wider application contract summary.
        $routes = $this->capabilities->allows('read_routes')
            ? $this->routes()
            : ['state' => 'denied', 'items' => [], 'truncated' => false];
        $operations = [];
        foreach ($routes['items'] as $route) {
            if ($route['contract'] !== true) continue;
            $operations[] = ['methods' => $route['methods'], 'path' => $route['path'],
                'route_name' => $route['name'], 'api_version' => $route['api_version']];
        }
        $strategy = $this->app->config()->get('api.versioning.strategy', 'uri');
        return [
            'state' => $routes['state'],
            'framework_version' => self::VERSION,
            'api_version_strategy' => in_array($strategy, ['uri', 'header'], true)
                ? $strategy : 'unavailable',
            'operations' => $operations,
            'truncated' => $routes['truncated'],
            'route_count' => $routes['state'] === 'observed' ? count($routes['items']) : null,
            'routes_truncated' => $routes['truncated'],
            'frontend' => $this->frontend(),
            'deployment' => ['state' => 'not_probed'],
        ];
    }

    /** Only selected configuration labels are reported; no build or cache is opened. */
    private function frontend(): array
    {
        $adapter = $this->app->config()->get('frontend.adapter', 'none');
        if ($adapter === 'none') {
            return ['adapter' => 'none', 'development' => 'not_required',
                'manifest' => 'not_inspected'];
        }
        if ($adapter !== 'vite') {
            return ['adapter' => 'unavailable', 'development' => 'unavailable',
                'manifest' => 'not_inspected'];
        }
        $enabled = $this->app->config()->get('frontend.development.enabled', false);
        return ['adapter' => 'vite',
            'development' => is_bool($enabled) ? ($enabled ? 'configured' : 'disabled') : 'unavailable',
            'manifest' => 'not_inspected'];
    }

    /** @return array<string,mixed> */
    public function routes(): array
    {
        $registry = $this->app->container()->make(RouteRegistry::class);
        $rows = $registry->inspection();
        if ($registry->sourceFilesLoaded()) {
            return ['state' => 'observed', 'source' => 'registered',
                'items' => array_slice($rows, 0, 100), 'truncated' => count($rows) > 100,
                'route_sources_loaded' => true];
        }
        // The validated RouteCache is a data artifact. Its reader normally
        // creates Routes.lock if absent; skip that path to keep Agent reads
        // physically read-only. Never fall back to executing route PHP.
        $lock = $this->app->basePath('Storage/Cache/Framework/Routes.lock');
        if (is_file($lock) && !is_link($lock)) {
            try {
                $cached = (new RouteCache($this->app))->inspectCachedRoutes();
                if ($cached !== null) {
                    foreach ($cached as $entry) {
                        $action = $entry['action'];
                        $handler = is_array($action) ? $action[0] . '@' . $action[1] : $action;
                        $rows[] = ['methods' => $entry['methods'], 'path' => $entry['uri'],
                            'name' => $entry['name'], 'handler' => $handler,
                            'handler_type' => is_array($action) ? 'Controller' : 'Invokable',
                            'middleware' => $entry['middleware'], 'host' => $entry['host'],
                            'fallback' => $entry['fallback'], 'constraints' => $entry['constraints'],
                            'model_bindings' => $entry['bindings'],
                            'api_version' => $entry['api_version'],
                            'contract' => $entry['contract'] !== null,
                            'source' => $entry['source']];
                    }
                    return ['state' => 'observed', 'source' => 'validated_cache',
                        'items' => array_slice($rows, 0, 100), 'truncated' => count($rows) > 100,
                        'route_sources_loaded' => false];
                }
            } catch (Throwable) {
                // A stale, unsafe, or corrupt cache is unavailable to Agent.
            }
        }
        return ['state' => 'partial', 'source' => 'already_registered',
            'reason' => 'Project route declarations are not executed by Agent inspection.',
            'items' => array_slice($rows, 0, 100), 'truncated' => count($rows) > 100,
            'route_sources_loaded' => false];
    }

    /** @return array<string,mixed> */
    public function packages(): array
    {
        return (new StudioInspector($this->app))->activation(100);
    }

    /** @return array<string,mixed> */
    public function health(): array
    {
        $inspector = new StudioInspector($this->app);
        $infrastructure = $inspector->infrastructure();
        $queue = $inspector->queue();
        $scheduler = $inspector->scheduler(100);
        // Health is a default read capability. Keep application-chosen
        // connection/drive/transport names behind narrower inspection grants.
        return ['health' => $inspector->health(),
            'infrastructure' => [
                'state' => $infrastructure['state'],
                'drivers' => $infrastructure['drivers'],
                'database' => ['listed_connection_count' => count($infrastructure['database']['connections']),
                    'truncated' => $infrastructure['database']['truncated']],
                'storage' => ['listed_drive_count' => count($infrastructure['storage']['drives']),
                    'truncated' => $infrastructure['storage']['truncated']],
                'mail' => ['driver' => $infrastructure['mail']['driver'],
                    'sender_configured' => $infrastructure['mail']['sender_configured'],
                    'provider_token_configured' => $infrastructure['mail']['provider_token_configured'],
                    'live_delivery_checked' => false],
                'cache' => $infrastructure['cache'],
                'redis' => $infrastructure['redis'],
            ],
            'queue' => ['state' => $queue['state'], 'driver' => $queue['driver'],
                'counts' => $queue['counts']],
            'scheduler' => ['state' => $scheduler['state'],
                'listed_task_count' => count($scheduler['items']),
                'truncated' => $scheduler['truncated']]];
    }

    /** @return array<string,mixed> */
    public function schema(): array
    {
        $connections = $this->app->config()->get('database.connections', []);
        $items = [];
        if (is_array($connections)) {
            foreach (array_slice($connections, 0, 32, true) as $name => $configuration) {
                if (!is_string($name) || !is_array($configuration)) continue;
                if (!$this->capabilities->allows('read_schema', $name)) continue;
                $driver = $configuration['driver'] ?? null;
                $items[] = ['connection' => $name,
                    'driver' => in_array($driver, ['sqlite', 'mysql'], true)
                        ? $driver : 'unavailable',
                    'inspection_granted' => true];
            }
        }
        return ['state' => 'configured', 'connections' => $items,
            'truncated' => is_array($connections) && count($connections) > 32,
            'records_exposed' => false];
    }

    /** @return array{state:string,items:list<array{name:string,description:string}>,truncated:bool} */
    public function cli(): array
    {
        if ($this->cliInventory === null) {
            return ['state' => 'unavailable', 'items' => [], 'truncated' => false];
        }
        return ['state' => 'registered', 'items' => $this->cliInventory,
            'truncated' => count($this->cliInventory) >= 256];
    }

    /** @return array{state:string,symbols:list<string>,truncated:bool} */
    private function plugins(): array
    {
        $directory = dirname(__DIR__) . '/Plugins';
        $files = glob($directory . '/*.php');
        if (!is_array($files)) return ['state' => 'unavailable', 'symbols' => [], 'truncated' => false];
        $symbols = [];
        foreach (array_slice($files, 0, 256) as $file) {
            $name = basename($file, '.php');
            if (preg_match('/\A[A-Z][A-Za-z0-9_]*\z/D', $name) === 1) {
                $symbols[] = 'App\\Plugins\\' . $name;
            }
        }
        sort($symbols, SORT_STRING);
        return ['state' => 'filesystem-declared', 'symbols' => $symbols,
            'truncated' => count($files) > 256];
    }
}
