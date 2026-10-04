<?php

declare(strict_types=1);

namespace App\Testing;

use App\Auth\AuthServiceProvider;
use App\Authorization\AuthorizationServiceProvider;
use App\Authorization\Rbac\RbacServiceProvider;
use App\Cache\CacheServiceProvider;
use App\Cryptography\CryptServiceProvider;
use App\Database\DatabaseManager;
use App\Database\DatabaseServiceProvider;
use App\Diagnostics\DiagnosticsServiceProvider;
use App\Foundation\Application;
use App\Http\BrowserFormsServiceProvider;
use App\Http\HttpServiceProvider;
use App\Idempotency\IdempotencyServiceProvider;
use App\Logging\LoggingServiceProvider;
use App\Locks\LockServiceProvider;
use App\RateLimit\RateLimitServiceProvider;
use App\Reliability\CircuitServiceProvider;
use App\Routing\RoutingServiceProvider;
use App\Security\SignedUrl\SignedUrlServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\SessionServiceProvider;
use App\Storage\StorageServiceProvider;
use App\Support\PhysicalPath;
use App\Translation\TranslationServiceProvider;
use App\Validation\ValidationServiceProvider;
use DirectoryIterator;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Owns a disposable SqueHub project with real providers and inert test drivers.
 *
 * Configuration is written before bootstrap. Every database connection is
 * constrained to this root or SQLite memory so a test cannot inherit live
 * application credentials through a developer's .env file.
 */
final class TestApplication
{
    private ?Application $application = null;
    private bool $routesAttempted = false;
    private bool $routesLoaded = false;
    private bool $cleaned = false;

    /** @var array<string, array<string, mixed>> */
    private array $config = [
        'app' => ['name' => 'SqueHub Test', 'env' => 'testing', 'debug' => false],
        'database' => ['default' => 'testing', 'connections' => [
            'testing' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ]],
        'session' => ['driver' => 'array'],
        'cache' => ['driver' => 'array'],
        'locks' => ['driver' => 'array'],
        'idempotency' => ['driver' => 'array'],
        'storage' => ['default' => 'memory', 'drives' => [
            'memory' => ['driver' => 'array'],
        ]],
        'logging' => ['driver' => 'array', 'level' => 'debug'],
        'csrf' => ['enabled' => true, 'field' => '_csrf', 'header' => 'X-CSRF-Token', 'except' => []],
    ];

    private function __construct(private string $root)
    {
    }

    /** @param array<string, array<string, mixed>> $config */
    public static function temporary(array $config = []): self
    {
        $temporary = realpath(sys_get_temp_dir());
        if ($temporary === false || !is_dir($temporary)) {
            throw new RuntimeException('A temporary test directory is unavailable.');
        }
        $root = str_replace('\\', '/', $temporary) . '/squehub-test-' . bin2hex(random_bytes(12));
        if (!@mkdir($root, 0700)) {
            throw new RuntimeException('Unable to create the temporary test application.');
        }
        $canonical = realpath($root);
        if ($canonical === false) {
            throw new RuntimeException('Unable to resolve the temporary test application.');
        }
        $owned = new self(str_replace('\\', '/', $canonical));
        try {
            foreach (['Config', 'Project/Routes', 'Project/Packages', 'Project/Views', 'Storage'] as $directory) {
                if (!@mkdir($owned->path($directory), 0700, true)) {
                    throw new RuntimeException('Unable to prepare the temporary test application.');
                }
            }
            $owned->configure($config);
            return $owned;
        } catch (Throwable $failure) {
            try {
                $owned->cleanup();
            } catch (Throwable $cleanupFailure) {
                throw new RuntimeException('Temporary test application setup and cleanup failed.', 0, $cleanupFailure);
            }
            throw $failure;
        }
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a canonical relative path without following a linked ancestor.
     * The returned path may not exist yet; write() creates its parent safely.
     */
    public function path(string $relative = ''): string
    {
        $this->assertActive();
        if ($relative === '') {
            return $this->root;
        }
        if ($relative[0] === '/' || str_contains($relative, '\\')
            || str_contains($relative, ':') || preg_match('/[\x00-\x1F\x7F]/', $relative)) {
            throw new InvalidArgumentException('Test application path must be relative and canonical.');
        }
        $parts = explode('/', $relative);
        $cursor = $this->root;
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..' || $part === '.env'
                || strlen($part) > 255) {
                throw new InvalidArgumentException('Test application path is unsafe.');
            }
            $cursor .= '/' . $part;
            if (@lstat($cursor) !== false) {
                if (!PhysicalPath::unlinked($cursor)) {
                    throw new InvalidArgumentException('Test application path contains a link.');
                }
                if (!PhysicalPath::within($cursor, $this->root)) {
                    throw new InvalidArgumentException('Test application path leaves its root.');
                }
            }
        }
        return $cursor;
    }

    /** Write only within this owned root; .env is intentionally unavailable. */
    public function write(string $relative, string $contents): void
    {
        if (preg_match('~\Aconfig(?:/|$)~i', $relative) === 1) {
            throw new InvalidArgumentException('Use configure() for test application configuration.');
        }
        $this->writeFile($relative, $contents);
    }

    private function writeFile(string $relative, string $contents): void
    {
        if ($relative === '') {
            throw new InvalidArgumentException('A test application file path is required.');
        }
        $target = $this->path($relative);
        $parent = dirname($target);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create a test application directory.');
        }
        // Recheck after mkdir: an external link must never redirect a write.
        $this->path($relative);
        if (is_dir($target) || file_put_contents($target, $contents, LOCK_EX) !== strlen($contents)) {
            throw new RuntimeException('Unable to write a test application file.');
        }
    }

    /**
     * Merge scalar/array config data before providers snapshot their settings.
     * Runtime or external database and Storage backends are not test defaults.
     *
     * @param array<string, array<string, mixed>> $config
     */
    public function configure(array $config): void
    {
        $this->assertActive();
        if ($this->application !== null) {
            throw new LogicException('Test application configuration must be set before bootstrap.');
        }
        $safe = [];
        foreach ($config as $root => $values) {
            if (!is_string($root) || preg_match('/\A[a-z][A-Za-z0-9]*\z/D', $root) !== 1
                || !is_array($values)) {
                throw new InvalidArgumentException('Test configuration must map canonical roots to arrays.');
            }
            $safe[$root] = self::configData($values);
        }
        $candidate = array_replace_recursive($this->config, $safe);
        $this->validateLocalConfig($candidate);
        foreach ($candidate as $root => $values) {
            $name = $root === 'oauth' ? 'OAuth' : ucfirst($root);
            $this->writeFile('Config/' . $name . '.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn "
                . var_export($values, true) . ";\n");
        }
        $this->config = $candidate;
    }

    public function isBooted(): bool
    {
        return $this->application?->isBooted() ?? false;
    }

    /** Bootstrap the real HTTP pipeline against the disposable project. */
    public function application(): Application
    {
        $this->assertActive();
        if ($this->application !== null) {
            return $this->application;
        }
        $app = $this->application = new Application($this->root);
        foreach ([
            DiagnosticsServiceProvider::class,
            TranslationServiceProvider::class,
            LoggingServiceProvider::class,
            CacheServiceProvider::class,
            StorageServiceProvider::class,
            DatabaseServiceProvider::class,
            LockServiceProvider::class,
            ValidationServiceProvider::class,
            SessionServiceProvider::class,
            CsrfServiceProvider::class,
            HttpServiceProvider::class,
            BrowserFormsServiceProvider::class,
            RoutingServiceProvider::class,
            AuthServiceProvider::class,
            \App\Mfa\MfaServiceProvider::class,
            RateLimitServiceProvider::class,
            IdempotencyServiceProvider::class,
            CircuitServiceProvider::class,
            CryptServiceProvider::class,
            SignedUrlServiceProvider::class,
            RbacServiceProvider::class,
            AuthorizationServiceProvider::class,
        ] as $provider) {
            $app->register($provider);
        }
        // Application registers and boots the Package provider from this root.
        $app->bootstrap();
        return $app;
    }

    /** Load normal application and enabled Package route files once. */
    public function loadRoutes(): void
    {
        $this->assertActive();
        if ($this->routesLoaded) {
            return;
        }
        if ($this->routesAttempted) {
            throw new LogicException('Test application route loading already failed.');
        }
        $squehubApp = $this->application();
        $this->routesAttempted = true;
        require dirname(__DIR__, 2) . '/Bootstrap/Routes.php';
        $this->routesLoaded = true;
    }

    /**
     * Remove only the private temporary root created by temporary().
     * Failures remain visible so tests cannot silently leave fixture state.
     */
    public function cleanup(): void
    {
        if ($this->cleaned) {
            return;
        }
        if (!PhysicalPath::unlinked($this->root)) {
            throw new RuntimeException('Temporary test application root changed before cleanup.');
        }
        // Windows cannot remove an open SQLite file. Release this fixture's
        // known connections before removing its filesystem; never close a
        // connection belonging to another Application.
        if ($this->application?->isBooted()
            && $this->application->container()->has(DatabaseManager::class)) {
            /** @var DatabaseManager $databases */
            $databases = $this->application->container()->make(DatabaseManager::class);
            foreach (array_keys($this->config['database']['connections']) as $name) {
                $databases->disconnect((string) $name);
            }
        }
        $this->removeTree($this->root);
        $this->application = null;
        $this->cleaned = true;
    }

    private function removeTree(string $directory): void
    {
        $items = new DirectoryIterator($directory);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }
            $path = str_replace('\\', '/', $item->getPathname());
            if (!$this->contains($path)) {
                throw new RuntimeException('Temporary test application cleanup left its root.');
            }
            $entry = @lstat($path);
            $kind = $entry === false ? null : ($entry['mode'] & 0170000);
            // PHP reports Windows junctions as neither links nor directories.
            // Remove their directory entry with rmdir without visiting its target.
            if (PHP_OS_FAMILY === 'Windows' && $kind === 0) {
                if (!@rmdir($path)) {
                    throw new RuntimeException('Unable to remove a temporary test application link.');
                }
                continue;
            }
            if ($item->isLink() || !$item->isDir()) {
                if (!@unlink($path) && !(PHP_OS_FAMILY === 'Windows' && @rmdir($path))) {
                    throw new RuntimeException('Unable to remove a temporary test application file.');
                }
                continue;
            }
            if (!PhysicalPath::unlinked($path)
                || !PhysicalPath::within($path, $this->root)) {
                throw new RuntimeException('Temporary test application directory left its root.');
            }
            $this->removeTree($path);
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException('Unable to remove a temporary test application directory.');
        }
    }

    private function contains(string $path): bool
    {
        $root = $this->root;
        if (PHP_OS_FAMILY === 'Windows') {
            $root = strtolower($root);
            $path = strtolower($path);
        }
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function assertActive(): void
    {
        if ($this->cleaned) {
            throw new LogicException('Temporary test application was already cleaned up.');
        }
    }

    private static function configData(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw new InvalidArgumentException('Test configuration nesting is too deep.');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::configData($item, $depth + 1);
            }
            return $copy;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new InvalidArgumentException('Test configuration contains a non-exportable value.');
    }

    /** @param array<string, array<string, mixed>> $config */
    private function validateLocalConfig(array $config): void
    {
        $environment = $config['app']['env'] ?? null;
        if (!is_string($environment)
            || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $environment) !== 1
            || !is_bool($config['app']['debug'] ?? null)) {
            throw new InvalidArgumentException('Test applications require a bounded environment name and boolean debug policy.');
        }
        $database = $config['database'];
        $default = $database['default'] ?? null;
        $connections = $database['connections'] ?? null;
        if (!is_string($default) || !is_array($connections) || !isset($connections[$default])) {
            throw new InvalidArgumentException('Test application database needs a configured SQLite default.');
        }
        foreach ($connections as $connection) {
            if (!is_array($connection) || ($connection['driver'] ?? null) !== 'sqlite'
                || !is_string($connection['database'] ?? null)
                || !$this->safeDatabasePath($connection['database'])) {
                throw new InvalidArgumentException('Test application databases must use disposable SQLite.');
            }
        }
        if (($config['session']['driver'] ?? null) !== 'array'
            || ($config['logging']['driver'] ?? null) !== 'array'
            || !in_array($config['cache']['driver'] ?? null, ['array', 'file'], true)) {
            throw new InvalidArgumentException('Test application state drivers must be local to the fixture.');
        }
        if ($config['cache']['driver'] === 'file'
            && isset($config['cache']['path'])
            && (!$this->safeRootPath($config['cache']['path']))) {
            throw new InvalidArgumentException('Test application Cache path must remain in its root.');
        }
        if (!in_array($config['locks']['driver'] ?? null, ['array', 'file', 'database'], true)
            || ($config['locks']['require_distributed'] ?? false) !== false
            || ($config['locks']['driver'] === 'file'
                && isset($config['locks']['path']) && !$this->safeRootPath($config['locks']['path']))) {
            throw new InvalidArgumentException('Test application Locks must use a local backend.');
        }
        if (!in_array($config['idempotency']['driver'] ?? null, ['array', 'file', 'database'], true)
            || ($config['idempotency']['require_shared'] ?? false) !== false
            || ($config['idempotency']['driver'] === 'file'
                && isset($config['idempotency']['path'])
                && !$this->safeRootPath($config['idempotency']['path']))) {
            throw new InvalidArgumentException('Test application Idempotency must use a local backend.');
        }
        $drives = $config['storage']['drives'] ?? null;
        $storageDefault = $config['storage']['default'] ?? null;
        if (!is_array($drives) || !is_string($storageDefault) || !isset($drives[$storageDefault])) {
            throw new InvalidArgumentException('Test application Storage needs a configured drive.');
        }
        foreach ($drives as $drive) {
            if (!is_array($drive) || !in_array($drive['driver'] ?? null, ['array', 'local'], true)
                || ($drive['driver'] === 'local' && isset($drive['root'])
                    && !$this->safeRootPath($drive['root']))) {
                throw new InvalidArgumentException('Test application Storage must remain in its root.');
            }
        }
    }

    private function safeDatabasePath(string $path): bool
    {
        return $path === ':memory:' || $this->safeRootPath($path, absoluteOnly: true);
    }

    private function safeRootPath(mixed $path, bool $absoluteOnly = false): bool
    {
        if (!is_string($path) || $path === '') {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);
        if ($this->contains($normalized) && $normalized !== $this->root) {
            try {
                $this->path(substr($normalized, strlen($this->root) + 1));
                return true;
            } catch (InvalidArgumentException) {
                return false;
            }
        }
        if ($absoluteOnly) {
            return false;
        }
        try {
            $this->path($path);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }
}
