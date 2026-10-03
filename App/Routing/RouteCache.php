<?php

declare(strict_types=1);

namespace App\Routing;

use App\Activation\ActivationRegistry;
use App\Api\Contract\OperationContract;
use App\Contributions\ContributionOwner;
use App\Contributions\ContributionRegistry;
use App\Foundation\Application;
use App\Kits\KitException;
use App\Kits\KitManager;
use App\Packages\PackageManager;
use App\Support\RuntimeContext;
use FilesystemIterator;
use JsonException;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Router;
use Throwable;

/**
 * Derived, application-owned route declarations under private framework Storage.
 * Source files are executed normally unless a complete, current, strictly
 * declarative cache exists. Package provider hooks still run during bootstrap.
 */
final class RouteCache
{
    private const VERSION = 2;
    private const MAX_BYTES = 4194304;
    private const MAX_ROUTES = 4096;
    private const MAX_SOURCE_FILES = 1024;
    private const MAX_SOURCE_BYTES = 8388608;

    public function __construct(private Application $app)
    {
    }

    /**
     * Load a valid cache or execute the normal route files. Corrupt active
     * artifacts fail visibly instead of silently hiding a deployment problem.
     */
    public function loadOrRequire(?Router $router = null): void
    {
        $registry = $this->registry();
        if ($registry->sourceFilesLoaded()) return;
        $sources = $this->sources();
        if ($this->load($sources, $registry)) {
            $registry->markSourceFilesLoaded();
            return;
        }
        $this->requireSources($sources, $router ?? $this->router());
        $registry->markSourceFilesLoaded();
    }

    /**
     * Build from one fresh, booted Application before its route files load.
     * Preflight every source before executing any: uncacheable PHP never
     * produces an artifact, and ordinary uncached loading remains available.
     *
     * @return array{files:int,routes:int,reused:bool}
     */
    public function build(): array
    {
        if (!$this->app->isBooted()) {
            throw new RouteCacheException('Application must boot before route cache generation.');
        }
        $sources = $this->sources();
        if (count($sources) > self::MAX_SOURCE_FILES) {
            throw new RouteCacheException('Route source count exceeds the cacheable limit.');
        }
        $vettedBytes = [];
        $vettedHashes = [];
        $sourceBytes = 0;
        foreach ($sources as $source) {
            $bytes = @file_get_contents($source['path']);
            if ($bytes === false) {
                throw new RouteCacheException("Route file '{$source['source']}' cannot be read.");
            }
            $sourceBytes += strlen($bytes);
            if ($sourceBytes > self::MAX_SOURCE_BYTES) {
                throw new RouteCacheException('Route source bytes exceed the cacheable limit.');
            }
            RouteCacheSource::assertCacheable($bytes, $source['source']);
            $vettedBytes[$source['path']] = $bytes;
            $vettedHashes[$source['path']] = hash('sha256', $bytes);
        }
        require_once dirname(__DIR__) . '/Core/Helper.php';
        $this->assertVettedSources($vettedHashes);
        $fingerprint = $this->fingerprint($sources);
        $registry = $this->registry();
        $before = count($registry->all());
        // Execute the bytes that passed the token proof, even if an editor or
        // deployment swaps the original path between preflight and require.
        // The restricted grammar cannot observe __FILE__/__DIR__ or include
        // another file, so a private same-process snapshot is equivalent.
        $snapshots = $this->snapshotSources($vettedBytes);
        try {
            $captured = $this->requireSources($sources, $this->router(), true, $snapshots);
            $registry->markSourceFilesLoaded();
        } finally {
            foreach ($snapshots as $snapshot) {
                @unlink($snapshot);
            }
        }
        $this->assertVettedSources($vettedHashes);
        if (count($registry->all()) !== $before + count($captured)) {
            throw new RouteCacheException('Route source changed existing registrations during cache generation.');
        }
        if (count($captured) > self::MAX_ROUTES) {
            throw new RouteCacheException('Route cache contains too many definitions.');
        }
        // Recheck source and activation after trusted PHP ran. A build racing
        // source deployment must never publish a cache for the earlier bytes.
        if (!hash_equals($fingerprint, $this->fingerprint($this->sources()))) {
            throw new RouteCacheException('Route source changed during cache generation.');
        }
        $payload = ['version' => self::VERSION, 'fingerprint' => $fingerprint,
            'routes' => $captured];
        // Detect accidental or partial edits to the derived JSON itself. This
        // is not an authentication MAC; private filesystem access remains the
        // trust boundary for an operator who can edit cache artifacts.
        $payload['checksum'] = hash('sha256', $this->encode($payload));
        $encoded = $this->encode($payload);
        $reused = $this->write($encoded, $fingerprint);
        return ['files' => count($sources), 'routes' => count($captured), 'reused' => $reused];
    }

    /** Remove only the route artifact; config, Views, and data Cache remain. */
    public function clear(): bool
    {
        $root = $this->storageRoot(false);
        if ($root === null) return false;
        $lock = $this->lock($root, LOCK_EX);
        try {
            $path = $root . '/Routes.json';
            $this->assertPhysicalIfPresent($path);
            if (!is_file($path)) return false;
            if (!@unlink($path)) {
                throw new RouteCacheException('Route cache could not be cleared.');
            }
            return true;
        } finally {
            $this->unlock($lock);
        }
    }

    /** @return array{state:string,files:int,routes:int,fingerprint:?string} */
    public function status(): array
    {
        $sources = $this->sources();
        try {
            $path = $this->storageRoot(false);
            if ($path === null) {
                return ['state' => 'absent', 'files' => count($sources), 'routes' => 0,
                    'fingerprint' => null];
            }
            $this->assertPhysicalIfPresent($path . '/Routes.json');
            $payload = $this->read();
            if ($payload === null) {
                return ['state' => 'absent', 'files' => count($sources), 'routes' => 0,
                    'fingerprint' => null];
            }
            $current = $this->fingerprint($sources);
            return ['state' => hash_equals($current, $payload['fingerprint']) ? 'active' : 'stale',
                'files' => count($sources), 'routes' => count($payload['routes']),
                'fingerprint' => $payload['fingerprint']];
        } catch (RouteCacheException) {
            return ['state' => 'corrupt', 'files' => count($sources), 'routes' => 0,
                'fingerprint' => null];
        }
    }

    /**
     * Return declarations only when the cache matches current source and
     * activation state. Inspection never executes route PHP or hydrates
     * controllers. A caller must still escape displayable metadata.
     *
     * @return list<array<string,mixed>>|null Null means absent or stale.
     */
    public function inspectCachedRoutes(): ?array
    {
        $sources = $this->sources();
        $payload = $this->read();
        if ($payload === null || !hash_equals($this->fingerprint($sources), $payload['fingerprint'])) {
            return null;
        }
        return $payload['routes'];
    }

    /** @param list<array<string,mixed>> $sources */
    private function load(array $sources, RouteRegistry $registry): bool
    {
        $payload = $this->read();
        if ($payload === null) return false;
        if (!hash_equals($this->fingerprint($sources), $payload['fingerprint'])) {
            return false;
        }
        RuntimeContext::select($this->app);
        $sourceMap = [];
        foreach ($sources as $source) $sourceMap[$source['source']] = $source;
        try {
            foreach ($payload['routes'] as $row) {
                $source = $sourceMap[$row['source']] ?? null;
                if ($source === null) {
                    throw new RouteCacheException('Route cache references an unavailable source.');
                }
                $this->withSource($source, function () use ($registry, $row): void {
                    $definition = $row['fallback']
                        ? $registry->addFallback($row['uri'], $row['action'], $row['host'])
                        : $registry->add($row['methods'], $row['uri'], $row['action'], $row['host']);
                    if ($row['api_version'] !== null) $definition->apiVersion($row['api_version']);
                    if ($row['middleware'] !== []) $definition->through($row['middleware']);
                    foreach ($row['constraints'] as $name => $constraint) {
                        $definition->where($name, $constraint);
                    }
                    foreach ($row['bindings'] as $name => $binding) {
                        $definition->bind($name, $binding['model'], $binding['key']);
                    }
                    if ($row['contract'] !== null) {
                        $definition->contract(OperationContract::fromCacheArray($row['contract']));
                    }
                    if ($row['name'] !== null) $definition->named($row['name']);
                });
            }
        } catch (RouteCacheException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new RouteCacheException('Route cache declaration could not be restored.', 0, $exception);
        }
        return true;
    }

    /**
     * @param list<array<string,mixed>> $sources
     * @return list<array<string,mixed>>
     */
    private function requireSources(array $sources, Router $router, bool $capture = false,
        array $snapshots = []): array
    {
        $registry = $this->registry();
        $squehubApp = $this->app;
        $routeRegistry = $registry;
        $routeRoot = $this->app->basePath();
        $contributions = $this->app->contributions();
        RuntimeContext::select($this->app);
        $records = [];
        foreach ($sources as $source) {
            $before = count($registry->all());
            // Preserve the route-file scope supplied by Bootstrap/Routes.php.
            // Dynamic files still execute with their established application
            // and router variables when no cache can be used.
            $this->withSource($source, fn () => $this->includeRouteFile(
                $source, $router, $squehubApp, $routeRegistry, $routeRoot, $contributions,
                $snapshots[$source['path']] ?? null));
            if (!$capture) continue;
            foreach (array_slice($registry->all(), $before) as $route) {
                $records[] = $this->capture($source['source'], $route);
            }
        }
        return $records;
    }

    /** Route files historically inherit these application and router locals. */
    private function includeRouteFile(array $source, Router $router, Application $squehubApp,
        RouteRegistry $routeRegistry, string $routeRoot, ContributionRegistry $contributions,
        ?string $snapshot): void
    {
        $path = $snapshot ?? $source['path'];
        require $path;
    }

    /**
     * @param array<string,string> $bytesByPath
     * @return array<string,string> Physical source path to private vetted snapshot.
     */
    private function snapshotSources(array $bytesByPath): array
    {
        if ($bytesByPath === []) return [];
        $root = $this->storageRoot(true);
        $snapshots = [];
        try {
            foreach ($bytesByPath as $source => $bytes) {
                $path = $root . '/.Routes-source-' . bin2hex(random_bytes(12)) . '.php';
                $handle = @fopen($path, 'x+b');
                if ($handle === false) {
                    throw new RouteCacheException('Private route source snapshot cannot be created.');
                }
                $snapshots[$source] = $path;
                try {
                    $length = strlen($bytes);
                    $written = 0;
                    while ($written < $length) {
                        $chunk = fwrite($handle, substr($bytes, $written));
                        if ($chunk === false || $chunk === 0) {
                            throw new RouteCacheException('Private route source snapshot could not be written.');
                        }
                        $written += $chunk;
                    }
                    if (!fflush($handle)) {
                        throw new RouteCacheException('Private route source snapshot could not be completed.');
                    }
                } finally {
                    fclose($handle);
                }
                @chmod($path, 0600);
            }
            return $snapshots;
        } catch (Throwable $exception) {
            foreach ($snapshots as $path) @unlink($path);
            throw $exception;
        }
    }

    /** @param array<string,string> $hashes Preflighted source bytes by physical path. */
    private function assertVettedSources(array $hashes): void
    {
        foreach ($hashes as $path => $expected) {
            $actual = @hash_file('sha256', $path);
            if ($actual === false || !hash_equals($expected, $actual)) {
                throw new RouteCacheException('Route source changed after cacheability validation.');
            }
        }
    }

    /** @param array<string,mixed> $source */
    private function withSource(array $source, callable $callback): void
    {
        $contributions = $this->app->contributions();
        $package = $source['package'];
        $container = $this->app->container();
        $middleware = $container->has(MiddlewareRegistry::class)
            ? $container->make(MiddlewareRegistry::class) : null;
        $registry = $this->registry();
        if ($package !== null) {
            $this->app->config()->beginPackageContext($package);
            $middleware?->beginPackageContext($package);
            $registry->beginPackageContext($package);
        }
        try {
            $contributions->beginOwner($source['owner'], $source['source']);
            try {
                $callback();
            } finally {
                $contributions->endOwner();
            }
        } finally {
            if ($package !== null) {
                $registry->endPackageContext();
                $middleware?->endPackageContext();
                $this->app->config()->endPackageContext();
            }
        }
    }

    /** @return array<string,mixed> */
    private function capture(string $source, RouteDefinition $route): array
    {
        $action = $route->action();
        if (!is_string($action)
            && !(is_array($action) && array_is_list($action) && count($action) === 2
                && is_string($action[0]) && is_string($action[1]))) {
            throw new RouteCacheException("Route file '{$source}' contains an unsupported action.");
        }
        foreach ($route->middlewares() as $middleware) {
            if (!is_string($middleware)) {
                throw new RouteCacheException("Route file '{$source}' contains object middleware.");
            }
        }
        return ['source' => $source, 'methods' => $route->methods(), 'uri' => $route->uri(),
            'action' => $action, 'middleware' => $route->middlewares(),
            'name' => $route->nameValue(), 'api_version' => $route->apiVersionValue(),
            'host' => $route->hostPattern(), 'fallback' => $route->isFallback(),
            'constraints' => $route->pattern()->rawConstraints(),
            'bindings' => $route->modelBindings(),
            'contract' => $route->contractValue()?->toCacheArray()];
    }

    /**
     * Discover exactly the directories used by the uncached loader, retaining
     * byte-exact path casing for Linux and Kit/Package provenance.
     *
     * @return list<array{path:string,source:string,owner:ContributionOwner,package:?string}>
     */
    private function sources(): array
    {
        $root = $this->app->basePath();
        $seen = [];
        $sources = [];
        foreach (['Project/Routes', 'Project/routes', 'project/Routes', 'project/routes'] as $relative) {
            $routePath = $root . '/' . $relative;
            if (is_link($routePath) || is_link(dirname($routePath))) {
                throw new RouteCacheException('Application route directory is linked.');
            }
            $directory = realpath($root . '/' . $relative);
            if ($directory === false || isset($seen[$directory])) continue;
            if (!self::samePath($routePath, $directory)
                || !self::containsPath($root, $directory)) {
                throw new RouteCacheException('Application route directory is outside its root.');
            }
            $seen[$directory] = true;
            foreach ($this->phpFiles($directory, $root) as $path) {
                $normalized = str_replace('\\', '/', $path);
                $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
                $source = str_starts_with($normalized, $prefix)
                    ? substr($normalized, strlen($prefix)) : $relative . '/' . basename($path);
                $owner = new ContributionOwner('application', 'Project');
                if (str_starts_with($source, 'Project/Routes/')) {
                    try {
                        $owner = $this->app->container()->make(KitManager::class)
                            ->ownerForFile($source) ?? $owner;
                    } catch (KitException) {
                        // Broken optional Kit attribution does not block routes.
                    }
                }
                $sources[] = ['path' => $path, 'source' => $source,
                    'owner' => $owner, 'package' => null];
            }
        }
        foreach ($this->app->container()->make(PackageManager::class)->active() as $package) {
            $packageRoot = realpath($package->path());
            if ($packageRoot === false || !is_dir($packageRoot) || is_link($package->path())) {
                throw new LogicException('Enabled Package directory is unavailable or linked.');
            }
            foreach (['Routes', 'routes'] as $routesName) {
                $routeDirectory = $packageRoot . '/' . $routesName;
                if (!is_dir($routeDirectory)) continue;
                $directory = realpath($routeDirectory);
                if ($directory === false || is_link($routeDirectory)
                    || !self::samePath(dirname($directory), $packageRoot)) {
                    throw new LogicException('Enabled Package route directory is unsafe.');
                }
                if (isset($seen[$directory])) break;
                $seen[$directory] = true;
                foreach ($this->phpFiles($directory, $packageRoot) as $path) {
                    $normalized = str_replace('\\', '/', $path);
                    $prefix = rtrim(str_replace('\\', '/', $packageRoot), '/') . '/';
                    $source = 'Project/Packages/' . $package->name() . '/'
                        . substr($normalized, strlen($prefix));
                    $sources[] = ['path' => $path, 'source' => $source,
                        'owner' => new ContributionOwner('package', $package->name()),
                        'package' => $package->name()];
                }
                break;
            }
        }
        return $sources;
    }

    /** @return list<string> */
    private function phpFiles(string $directory, string $boundary): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
        $paths = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            $path = $file->getPathname();
            $resolved = realpath($path);
            if ($file->isLink() || $resolved === false || !self::samePath($path, $resolved)
                || !self::containsPath($boundary, $resolved)) {
                throw new RouteCacheException('Route file is linked or outside its root.');
            }
            $paths[] = $path;
        }
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @param list<array<string,mixed>> $sources */
    private function fingerprint(array $sources): string
    {
        $root = $this->app->basePath();
        $manifest = [];
        foreach ($sources as $source) {
            $hash = @hash_file('sha256', $source['path']);
            if ($hash === false) {
                throw new RouteCacheException("Route file '{$source['source']}' cannot be fingerprinted.");
            }
            $manifest[] = [$source['source'], $hash, $source['owner']->type,
                $source['owner']->name, $source['package']];
        }
        $core = [];
        foreach ([__FILE__, __DIR__ . '/RouteCacheSource.php', __DIR__ . '/RouteRegistry.php',
            __DIR__ . '/RouteDefinition.php', __DIR__ . '/RoutePattern.php',
            __DIR__ . '/Route.php', __DIR__ . '/RouteGroup.php',
            __DIR__ . '/RoutePath.php',
            dirname(__DIR__) . '/Api/Contract/OperationContract.php',
            dirname(__DIR__) . '/Api/Contract/Schema.php',
            dirname(__DIR__, 2) . '/Bootstrap/Routes.php',
            dirname(__DIR__, 2) . '/composer.lock'] as $file) {
            $core[] = @hash_file('sha256', $file) ?: '';
        }
        // Pure route declarations cannot read config, but deployment state
        // still belongs in the artifact identity. Only hashes and public
        // environment labels enter the private manifest, never .env values.
        $configuration = [];
        $configFiles = glob($root . '/Config/*.php');
        if ($configFiles === false) {
            throw new RouteCacheException('Application configuration cannot be fingerprinted.');
        }
        sort($configFiles, SORT_STRING);
        foreach ($configFiles as $file) {
            $hash = @hash_file('sha256', $file);
            if ($hash === false) {
                throw new RouteCacheException('Application configuration cannot be fingerprinted.');
            }
            $configuration[] = [basename($file), $hash];
        }
        $dotenv = $root . '/.env';
        $dotenvHash = is_file($dotenv) ? @hash_file('sha256', $dotenv) : null;
        if ($dotenvHash === false) {
            throw new RouteCacheException('Application environment cannot be fingerprinted.');
        }
        return hash('sha256', $this->encode(['version' => self::VERSION, 'sources' => $manifest,
            'activation' => $this->app->container()->make(ActivationRegistry::class)->fingerprint(),
            'environment' => [$this->app->environment(), $dotenvHash,
                $this->app->config()->get('http.base_path')],
            'configuration' => $configuration, 'core' => $core]));
    }

    /** @return array{version:int,fingerprint:string,routes:list<array<string,mixed>>,checksum:string}|null */
    private function read(): ?array
    {
        $root = $this->storageRoot(false);
        if ($root === null) return null;
        $path = $root . '/Routes.json';
        $this->assertPhysicalIfPresent($path);
        if (!is_file($path)) return null;
        $lock = $this->lock($root, LOCK_SH);
        try {
            $this->assertPhysicalIfPresent($path);
            if (!is_file($path)) return null;
            $size = @filesize($path);
            if ($size === false || $size > self::MAX_BYTES) {
                throw new RouteCacheException('Route cache is too large or unreadable.');
            }
            $json = @file_get_contents($path);
        } finally {
            $this->unlock($lock);
        }
        if ($json === false) throw new RouteCacheException('Route cache could not be read.');
        try {
            $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RouteCacheException('Route cache is corrupt JSON.', 0, $exception);
        }
        if (!is_array($value) || array_keys($value) !== ['version', 'fingerprint', 'routes', 'checksum']
            || $value['version'] !== self::VERSION
            || !is_string($value['fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['fingerprint']) !== 1
            || !is_array($value['routes']) || !array_is_list($value['routes'])
            || count($value['routes']) > self::MAX_ROUTES
            || !is_string($value['checksum'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['checksum']) !== 1) {
            throw new RouteCacheException('Route cache format is invalid.');
        }
        $content = ['version' => $value['version'], 'fingerprint' => $value['fingerprint'],
            'routes' => $value['routes']];
        if (!hash_equals(hash('sha256', $this->encode($content)), $value['checksum'])) {
            throw new RouteCacheException('Route cache checksum is invalid.');
        }
        foreach ($value['routes'] as $row) $this->validateRow($row);
        return $value;
    }

    private function validateRow(mixed $row): void
    {
        if (!is_array($row) || array_keys($row) !== ['source', 'methods', 'uri', 'action',
            'middleware', 'name', 'api_version', 'host', 'fallback', 'constraints', 'bindings', 'contract']
            || !is_string($row['source']) || !is_array($row['methods'])
            || !array_is_list($row['methods']) || !is_string($row['uri'])
            || (!is_string($row['action']) && !(is_array($row['action'])
                && array_is_list($row['action']) && count($row['action']) === 2
                && is_string($row['action'][0]) && is_string($row['action'][1])))
            || !is_array($row['middleware']) || !array_is_list($row['middleware'])
            || !in_array(get_debug_type($row['name']), ['string', 'null'], true)
            || !in_array(get_debug_type($row['api_version']), ['string', 'null'], true)
            || !in_array(get_debug_type($row['host']), ['string', 'null'], true)
            || !is_bool($row['fallback']) || !is_array($row['constraints'])
            || !is_array($row['bindings'])
            || ($row['contract'] !== null && !is_array($row['contract']))) {
            throw new RouteCacheException('Route cache declaration is invalid.');
        }
        foreach ($row['methods'] as $method) {
            if (!is_string($method)) throw new RouteCacheException('Route cache method is invalid.');
        }
        foreach ($row['middleware'] as $middleware) {
            if (!is_string($middleware)) throw new RouteCacheException('Route cache middleware is invalid.');
        }
        foreach ($row['constraints'] as $name => $pattern) {
            if (!is_string($name) || !is_string($pattern)) {
                throw new RouteCacheException('Route cache constraint is invalid.');
            }
        }
        foreach ($row['bindings'] as $name => $binding) {
            if (!is_string($name) || !is_array($binding)
                || array_keys($binding) !== ['model', 'key']
                || !is_string($binding['model'])
                || ($binding['key'] !== null && !is_string($binding['key']))) {
                throw new RouteCacheException('Route cache binding is invalid.');
            }
        }
    }

    private function write(string $encoded, string $fingerprint): bool
    {
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new RouteCacheException('Route cache exceeds the storage limit.');
        }
        $root = $this->storageRoot(true);
        $lock = $this->lock($root, LOCK_EX);
        $path = $root . '/Routes.json';
        $temporary = $root . '/.Routes.tmp.' . bin2hex(random_bytes(12));
        try {
            $this->assertPhysicalIfPresent($path);
            if (is_file($path)) {
                $existing = @file_get_contents($path);
                if ($existing === $encoded) return true;
            }
            $handle = @fopen($temporary, 'x+b');
            if ($handle === false) throw new RouteCacheException('Route cache temporary file cannot be created.');
            try {
                $written = fwrite($handle, $encoded);
                if ($written !== strlen($encoded) || !fflush($handle)) {
                    throw new RouteCacheException('Route cache temporary file could not be completed.');
                }
            } finally {
                fclose($handle);
            }
            // Readers hold the same lock. Windows may refuse rename-over-file,
            // so unlinking the old derived artifact under that lock is safe.
            if (is_file($path) && !@unlink($path)) {
                throw new RouteCacheException('Route cache could not replace the old artifact.');
            }
            if (!@rename($temporary, $path)) {
                throw new RouteCacheException('Route cache artifact could not be activated.');
            }
            return false;
        } finally {
            if (is_file($temporary)) @unlink($temporary);
            $this->unlock($lock);
        }
    }

    /** @return resource */
    private function lock(string $root, int $mode)
    {
        $path = $root . '/Routes.lock';
        $this->assertPhysicalIfPresent($path);
        $handle = @fopen($path, 'c+b');
        if ($handle === false || !@flock($handle, $mode)) {
            if (is_resource($handle)) fclose($handle);
            throw new RouteCacheException('Route cache lock is unavailable.');
        }
        return $handle;
    }

    /** @param resource $lock */
    private function unlock($lock): void
    {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function storageRoot(bool $create): ?string
    {
        $cursor = $this->app->basePath();
        foreach (['Storage', 'Cache', 'Framework'] as $part) {
            $cursor .= '/' . $part;
            $this->assertPhysicalIfPresent($cursor);
            if (!is_dir($cursor)) {
                if (!$create) return null;
                if (!@mkdir($cursor, 0770) && !is_dir($cursor)) {
                    throw new RouteCacheException('Private route cache directory cannot be created.');
                }
            }
            $this->assertPhysicalIfPresent($cursor);
        }
        return $cursor;
    }

    private function assertPhysicalIfPresent(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path)) throw new RouteCacheException('Route cache storage contains an unsafe link.');
        if (!file_exists($path)) return;
        $physical = realpath($path);
        if ($physical === false || !self::samePath($path, $physical)) {
            throw new RouteCacheException('Route cache storage contains an unsafe path.');
        }
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = rtrim(str_replace('\\', '/', $left), '/');
        $right = rtrim(str_replace('\\', '/', $right), '/');
        return DIRECTORY_SEPARATOR === '\\' ? strcasecmp($left, $right) === 0 : $left === $right;
    }

    private static function containsPath(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);
        if (DIRECTORY_SEPARATOR === '\\') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return str_starts_with($path, $root . '/');
    }

    private function router(): Router
    {
        require_once dirname(__DIR__, 2) . '/Router.php';
        return new Router($this->registry(), $this->app->container()->make(PackageManager::class));
    }

    private function registry(): RouteRegistry
    {
        return $this->app->container()->make(RouteRegistry::class);
    }

    private function encode(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RouteCacheException('Route cache contains non-JSON data.', 0, $exception);
        }
    }
}
