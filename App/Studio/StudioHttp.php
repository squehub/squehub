<?php

declare(strict_types=1);

namespace App\Studio;

use App\Core\View;
use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Http\Response;
use App\Support\RuntimeContext;
use Throwable;

/**
 * Fixed, read-only HTTP surface for local Studio inspection. This router does
 * not enter the application route pipeline or serve arbitrary public files.
 * The separate server checks the peer before boot; the check is repeated here
 * so direct use of this boundary cannot bypass it.
 */
final class StudioHttp
{
    private const HEADERS = [
        'Cache-Control' => 'no-store, private',
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'Content-Security-Policy' => "default-src 'none'; style-src 'self'; img-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'",
    ];

    /** Only these framework-owned files are exposed by the separate Studio server. */
    private const ASSETS = [
        '/assets/studio.css' => ['studio.css', 'text/css; charset=UTF-8', 65536],
        '/assets/default/favicon/squehub-icon.png' =>
            ['default/favicon/squehub-icon.png', 'image/png', 65536],
    ];

    /** @var array<string, string> */
    private const PAGES = [
        '/studio' => 'Overview',
        '/studio/routes' => 'Routes',
        '/studio/profiles' => 'Profiles',
        '/studio/observations' => 'Observations',
        '/studio/packages' => 'Packages',
        '/studio/kits' => 'Kits',
        '/studio/health' => 'Health',
        '/studio/queue' => 'Queue',
        '/studio/scheduler' => 'Scheduler',
        '/studio/events' => 'Events',
        '/studio/broadcast' => 'Broadcast',
        '/studio/infrastructure' => 'Infrastructure',
        '/studio/contract' => 'Application Contract',
    ];

    public function __construct(private readonly Application $app)
    {
    }

    /** Reject DNS-rebound hosts even when a browser can reach loopback. */
    public static function peerAllowed(string $remoteAddress, string $host, int $serverPort): bool
    {
        return $remoteAddress === '127.0.0.1'
            && $serverPort >= 1024 && $serverPort <= 65535
            && $host === '127.0.0.1:' . $serverPort;
    }

    public function handle(string $method, string $requestPath, string $remoteAddress,
        string $host, int $serverPort): Response
    {
        if (!self::peerAllowed($remoteAddress, $host, $serverPort)) {
            return self::plain('Forbidden.', 403);
        }
        // APP_DEBUG is intentionally irrelevant. A production deployment
        // cannot enable Studio by turning on framework debugging alone.
        if ($this->app->environment() !== 'development'
            || $this->app->config()->get('studio.enabled', false) !== true) {
            return self::plain('Not found.', 404);
        }
        if ($method !== 'GET' && $method !== 'HEAD') {
            return self::plain('Method not allowed.', 405)->withHeader('Allow', 'GET, HEAD');
        }
        $mount = $this->app->container()->make(UrlBasePath::class);
        $path = $mount->strip($requestPath);
        if ($path === null || strlen($path) > 160) {
            return self::plain('Not found.', 404);
        }
        if (isset(self::ASSETS[$path])) {
            [$relativePath, $contentType, $maximumBytes] = self::ASSETS[$path];
            return $this->asset($relativePath, $contentType, $maximumBytes);
        }
        if ($path !== '/studio' && !isset(self::PAGES[$path])
            && preg_match('~\A/studio/profiles/[a-f0-9]{32}\z~D', $path) !== 1) {
            return self::plain('Not found.', 404);
        }
        try {
            $inspector = new StudioInspector($this->app);
            [$title, $state, $cards] = $this->page($inspector, $path);
            if ($title === '') {
                return self::plain('Not found.', 404);
            }
            // Select the current Application explicitly because another local
            // test/Application may have used the static View bridge last.
            RuntimeContext::select($this->app);
            $links = [];
            foreach (self::PAGES as $route => $label) {
                $links[] = ['label' => $label, 'url' => $mount->publicPath($route),
                    'current' => $route === $path];
            }
            $response = View::response('Studio.Dashboard', [
                'title' => $title, 'state' => $state, 'cards' => $cards,
                'navigation' => $links,
                'cssUrl' => $mount->assetUrl('/assets/studio.css'),
                'iconUrl' => $mount->assetUrl('/assets/default/favicon/squehub-icon.png'),
                'homeUrl' => $mount->publicPath('/studio'),
            ], headers: self::HEADERS);
            // The bounded inspector and this size ceiling keep a development
            // page from becoming an unbounded data or memory export.
            return strlen($response->content()) <= 262144
                ? $response : self::plain('Studio view is unavailable.', 503);
        } catch (Throwable) {
            // Studio is optional. Never publish a project exception, file path,
            // configuration value, or profile content in its HTTP error page.
            return self::plain('Studio view is unavailable.', 503);
        }
    }

    /**
     * Serve fixed Studio assets from public/. Each directory is checked before
     * reading so a linked ancestor cannot escape the application public root.
     * The PHP built-in server never receives a static-file fallback.
     */
    private function asset(string $relativePath, string $contentType, int $maximumBytes): Response
    {
        $root = $this->app->publicPath();
        $canonicalRoot = realpath($root);
        if (is_link($root) || $canonicalRoot === false || !is_dir($root)) {
            return self::plain('Not found.', 404);
        }

        $path = $root;
        foreach (explode('/', 'assets/' . $relativePath) as $part) {
            $path .= '/' . $part;
            $canonicalPart = realpath($path);
            if (is_link($path) || $canonicalPart === false
                || !self::within($canonicalPart, $canonicalRoot)) {
                return self::plain('Not found.', 404);
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            return self::plain('Not found.', 404);
        }
        $size = filesize($path);
        if (!is_int($size) || $size < 1 || $size > $maximumBytes) {
            return self::plain('Not found.', 404);
        }
        $body = file_get_contents($path);
        if ($body === false || strlen($body) !== $size) {
            return self::plain('Studio asset is unavailable.', 503);
        }
        return new Response($body, 200,
            ['Content-Type' => $contentType, ...self::HEADERS]);
    }

    /** @return array{string,string,list<array{heading:string,fields:list<array{label:string,value:string}>,url:?string}>} */
    private function page(StudioInspector $inspector, string $path): array
    {
        if (preg_match('~\A/studio/profiles/([a-f0-9]{32})\z~D', $path, $match) === 1) {
            $profile = $inspector->profile($match[1]);
            if ($profile === null) return ['', '', []];
            $cards = [self::card('Request', $profile, [
                'operation', 'recorded_at', 'duration_ms', 'correlation_id',
                'trace_id', 'dropped_events', 'dropped_metrics',
            ])];
            foreach (array_slice(is_array($profile['events'] ?? null) ? $profile['events'] : [], 0, 100) as $event) {
                if (!is_array($event)) continue;
                $attributes = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
                $cards[] = self::card('Timeline span', [
                    ...$event,
                    'method' => $attributes['method'] ?? null,
                    'route_pattern' => $attributes['route_pattern'] ?? null,
                    'status' => $attributes['status'] ?? null,
                    'driver' => $attributes['driver'] ?? null,
                    'host' => $attributes['host'] ?? null,
                    'result' => $attributes['result'] ?? null,
                    'attempt' => $attributes['attempt'] ?? null,
                    'connection' => $attributes['connection'] ?? null,
                    'transport' => $attributes['transport'] ?? null,
                ], ['operation', 'offset_ms', 'duration_ms', 'failed',
                    'method', 'route_pattern', 'status', 'driver', 'host',
                    'result', 'attempt', 'connection', 'transport']);
            }
            foreach (array_slice(is_array($profile['metrics'] ?? null) ? $profile['metrics'] : [],
                0, 50, true) as $name => $metric) {
                if (is_string($name) && is_array($metric)) {
                    $cards[] = self::card('Metric', ['name' => $name, ...$metric],
                        ['name', 'count', 'time_ms']);
                }
            }
            return ['Request profile', 'observed', $cards];
        }
        return match ($path) {
            '/studio' => $this->overview($inspector),
            '/studio/routes' => self::routesPage($inspector->routes()),
            '/studio/profiles' => $this->profiles($inspector),
            '/studio/observations' => self::observationsPage($inspector->observations()),
            '/studio/packages' => self::activationPage('Packages', $inspector->activation(), 'packages'),
            '/studio/kits' => self::activationPage('Kits', $inspector->activation(), 'kits'),
            '/studio/health' => self::healthPage($inspector->health()),
            '/studio/queue' => self::simplePage('Queue', $inspector->queueStatus(),
                ['connection', 'driver', 'counts']),
            '/studio/scheduler' => self::listPage('Scheduler', $inspector->registeredSchedules(),
                ['name', 'schedule', 'mode', 'queue', 'timezone', 'overlap']),
            '/studio/events' => self::listPage('Events', $inspector->events(),
                ['event', 'listener', 'priority', 'queued']),
            '/studio/broadcast' => self::broadcastPage($inspector->broadcast()),
            '/studio/infrastructure' => self::infrastructurePage($inspector->infrastructure()),
            '/studio/contract' => self::listPage('Application Contract', $inspector->contract(),
                ['methods', 'path', 'route_name', 'api_version']),
            default => ['', '', []],
        };
    }

    private function overview(StudioInspector $inspector): array
    {
        $data = $inspector->overview();
        $cards = [];
        foreach ([
            ['Application', 'application', ['name', 'environment', 'php', 'framework', 'base_path', 'debug']],
            ['Profiler', 'profiler', ['enabled']],
            ['Route cache', 'route_cache', ['state', 'files', 'routes']],
            ['Config cache', 'config_cache', ['status', 'active', 'created_at', 'files']],
            ['Configured drivers', 'drivers', ['database', 'queue', 'cache', 'session']],
        ] as [$heading, $key, $fields]) {
            $values = $data[$key] ?? [];
            if (is_array($values)) $cards[] = self::card($heading, $values, $fields);
        }
        $frontend = $data['frontend'] ?? null;
        if (is_array($frontend) && ($frontend['adapter'] ?? 'none') !== 'none') {
            $cards[] = self::card('Frontend', $frontend,
                ['adapter', 'development', 'manifest']);
        }
        $health = $inspector->health();
        $live = is_array($health['live'] ?? null) ? $health['live'] : [];
        $cards[] = self::card('Health', ['status' => $live['status'] ?? 'unavailable',
            'dependencies' => $health['dependencies'] ?? 'not_probed'],
            ['status', 'dependencies']);
        $activation = $inspector->activation();
        $packages = is_array($activation['packages']['items'] ?? null)
            ? $activation['packages']['items'] : [];
        $kits = is_array($activation['kits']['items'] ?? null)
            ? $activation['kits']['items'] : [];
        $cards[] = self::card('Activation', [
            'packages' => count($packages), 'kits' => count($kits),
            'state' => self::state($activation),
        ], ['packages', 'kits', 'state']);
        $infrastructure = $inspector->infrastructure();
        $redis = is_array($infrastructure['redis'] ?? null) ? $infrastructure['redis'] : [];
        $cards[] = self::card('Redis', $redis, ['status', 'client']);
        return ['Overview', self::state($data), $cards];
    }

    private static function routesPage(array $data): array
    {
        $cards = [];
        foreach (array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 200) as $route) {
            if (!is_array($route)) continue;
            $owner = is_array($route['owner'] ?? null) ? $route['owner'] : [];
            $bindings = [];
            foreach (array_slice(is_array($route['model_bindings'] ?? null)
                ? $route['model_bindings'] : [], 0, 10) as $binding) {
                if (!is_array($binding)) continue;
                $bindings[] = self::display($binding['parameter'] ?? null) . ' → '
                    . self::display($binding['model'] ?? null)
                    . (is_string($binding['key'] ?? null) ? ' (' . self::display($binding['key']) . ')' : '');
            }
            $constraints = [];
            foreach (array_slice(is_array($route['constraints'] ?? null)
                ? $route['constraints'] : [], 0, 10, true) as $name => $pattern) {
                if (is_string($name) && is_string($pattern)) {
                    $constraints[] = self::display($name) . ': ' . self::display($pattern);
                }
            }
            $cards[] = self::card('Route', [
                'methods' => $route['methods'] ?? [], 'path' => $route['path'] ?? null,
                'name' => $route['name'] ?? null, 'host' => $route['host'] ?? null,
                'handler' => $route['handler'] ?? null,
                'handler_type' => $route['handler_type'] ?? null,
                'middleware' => $route['middleware'] ?? [],
                'constraints' => $constraints,
                'optional_parameters' => $route['optional_parameters'] ?? [],
                'model_bindings' => $bindings,
                'owner' => ($owner['type'] ?? null) !== null
                    ? self::display($owner['type']) . ': ' . self::display($owner['name'] ?? null)
                    : null,
                'source' => $route['source'] ?? null,
                'api_version' => $route['api_version'] ?? null,
                'contract' => $route['contract'] ?? false,
                'fallback' => $route['fallback'] ?? false,
            ], ['methods', 'path', 'name', 'host', 'handler', 'handler_type',
                'middleware', 'constraints', 'optional_parameters', 'model_bindings',
                'owner', 'source', 'api_version', 'contract', 'fallback']);
        }
        if ($data['truncated'] ?? false) {
            $cards[] = self::card('More routes', ['notice' => 'The list is bounded.'], ['notice']);
        }
        return ['Routes', self::state($data), $cards];
    }

    private static function observationsPage(array $data): array
    {
        $cards = [self::card('Recent profiles', $data, ['sample_size'])];
        $categories = is_array($data['categories'] ?? null) ? $data['categories'] : [];
        foreach (['database', 'cache', 'events', 'queue', 'scheduler', 'http_client',
            'mail', 'notifications', 'storage'] as $category) {
            $values = $categories[$category] ?? null;
            if (is_array($values)) {
                $cards[] = self::card(ucwords(str_replace('_', ' ', $category)),
                    $values, ['count', 'time_ms']);
            }
        }
        return ['Observations', self::state($data), $cards];
    }

    private function profiles(StudioInspector $inspector): array
    {
        $data = $inspector->profiles();
        $mount = $this->app->container()->make(UrlBasePath::class);
        $cards = [];
        foreach (array_slice($data['items'], 0, 20) as $row) {
            if (!is_array($row)) continue;
            $card = self::card('Request', $row,
                ['operation', 'recorded_at', 'duration_ms', 'correlation_id', 'event_count']);
            if (is_string($row['id'] ?? null)
                && preg_match('/\A[a-f0-9]{32}\z/D', $row['id']) === 1) {
                $card['url'] = $mount->publicPath('/studio/profiles/' . $row['id']);
            }
            $cards[] = $card;
        }
        return ['Profiles', self::state($data), $cards];
    }

    private static function activationPage(string $title, array $data, string $kind): array
    {
        $list = is_array($data[$kind] ?? null) ? $data[$kind] : [];
        return self::listPage($title, ['state' => self::state($data),
            'items' => $list['items'] ?? [], 'truncated' => $list['truncated'] ?? false],
            ['name', 'status', 'installed', 'enabled', 'registered', 'source_kind', 'dependencies']);
    }

    private static function healthPage(array $data): array
    {
        $live = is_array($data['live'] ?? null) ? $data['live'] : [];
        $cards = [self::card('Liveness', $live, ['type', 'status', 'counts'])];
        foreach (array_slice(is_array($live['results'] ?? null) ? $live['results'] : [], 0, 20) as $row) {
            if (is_array($row)) $cards[] = self::card('Check', $row,
                ['name', 'category', 'status', 'summary', 'code', 'duration_ms']);
        }
        $cards[] = self::card('Dependencies', $data, ['dependencies']);
        return ['Health', self::state($data), $cards];
    }

    private static function broadcastPage(array $data): array
    {
        $cards = [self::card('Broadcast', $data, ['enabled', 'driver'])];
        $patterns = is_array($data['patterns'] ?? null) ? $data['patterns'] : [];
        foreach (array_slice(is_array($patterns['items'] ?? null) ? $patterns['items'] : [], 0, 100) as $pattern) {
            $cards[] = self::card('Private channel pattern', ['pattern' => $pattern], ['pattern']);
        }
        return ['Broadcast', self::state($data), $cards];
    }

    private static function infrastructurePage(array $data): array
    {
        $cards = [];
        foreach ([['Drivers', 'drivers', ['database', 'queue', 'cache', 'session']],
            ['Database', 'database', ['default']],
            ['Mail', 'mail', ['default', 'driver', 'sender_configured',
                'provider_token_configured', 'live_delivery_checked']],
            ['Cache', 'cache', ['driver', 'memcached_extension_available', 'reachability_checked']],
            ['Storage', 'storage', ['default']],
            ['Translation', 'translation', ['default', 'fallback', 'supported', 'intl_available']],
            ['Redis', 'redis', ['status', 'client']]] as
            [$heading, $key, $fields]) {
            if (is_array($data[$key] ?? null)) $cards[] = self::card($heading, $data[$key], $fields);
        }
        $storage = is_array($data['storage'] ?? null) ? $data['storage'] : [];
        foreach (array_slice(is_array($storage['drives'] ?? null) ? $storage['drives'] : [], 0, 20) as $drive) {
            if (is_array($drive)) $cards[] = self::card('Storage drive', $drive,
                ['name', 'driver', 'dependency_available']);
        }
        $database = is_array($data['database'] ?? null) ? $data['database'] : [];
        foreach (array_slice(is_array($database['connections'] ?? null)
            ? $database['connections'] : [], 0, 20) as $connection) {
            if (is_array($connection)) $cards[] = self::card('Database connection',
                $connection, ['name', 'driver']);
        }
        return ['Infrastructure', self::state($data), $cards];
    }

    private static function simplePage(string $title, array $data, array $fields): array
    {
        return [$title, self::state($data), [self::card($title, $data, $fields)]];
    }

    private static function listPage(string $title, array $data, array $fields): array
    {
        $cards = [];
        foreach (array_slice(is_array($data['items'] ?? null) ? $data['items'] : [], 0, 200) as $row) {
            if (is_array($row)) $cards[] = self::card($title === 'Routes' ? 'Route' : 'Entry', $row, $fields);
        }
        if ($data['truncated'] ?? false) {
            $cards[] = self::card('More results', ['notice' => 'The list is bounded.'], ['notice']);
        }
        return [$title, self::state($data), $cards];
    }

    /** @return array{heading:string,fields:list<array{label:string,value:string}>,url:?string} */
    private static function card(string $heading, array $data, array $allowed): array
    {
        $fields = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $data)) continue;
            $fields[] = ['label' => ucwords(str_replace('_', ' ', $key)),
                'value' => self::display($data[$key])];
        }
        return ['heading' => $heading, 'fields' => $fields, 'url' => null];
    }

    /** Only allowlisted scalar metadata is projected; nested arrays become counts or short labels. */
    private static function display(mixed $value): string
    {
        if ($value === null) return '—';
        if (is_bool($value)) return $value ? 'Yes' : 'No';
        if (is_int($value) || is_float($value)) return (string) $value;
        if (is_string($value)) {
            return strlen($value) <= 220 && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
                ? $value : 'unavailable';
        }
        if (is_array($value)) {
            if ($value === []) return 'None';
            if (array_is_list($value)) {
                $labels = [];
                foreach (array_slice($value, 0, 8) as $item) {
                    if (is_string($item) && strlen($item) <= 60
                        && preg_match('/[\x00-\x1F\x7F]/', $item) !== 1) $labels[] = $item;
                }
                return $labels !== [] ? implode(', ', $labels) . (count($value) > 8 ? ', …' : '')
                    : count($value) . ' item(s)';
            }
            return count($value) . ' field(s)';
        }
        return 'unavailable';
    }

    private static function state(array $data): string
    {
        $state = $data['state'] ?? 'unavailable';
        return is_string($state) && in_array($state,
            ['known', 'configured', 'observed', 'unavailable'], true) ? $state : 'unavailable';
    }

    private static function within(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (PHP_OS_FAMILY === 'Windows') {
            $path = strtolower($path);
            $root = strtolower($root);
        }
        return str_starts_with($path, $root . '/');
    }

    private static function plain(string $message, int $status): Response
    {
        return new Response($message, $status,
            ['Content-Type' => 'text/plain; charset=UTF-8', ...self::HEADERS]);
    }
}
