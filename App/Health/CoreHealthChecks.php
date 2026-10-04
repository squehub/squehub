<?php

declare(strict_types=1);

namespace App\Health;

use App\Activation\ActivationRegistry;
use App\Cache\CacheStore;
use App\Cryptography\CryptManager;
use App\Database\DatabaseManager;
use App\Foundation\Application;
use App\Foundation\EnvironmentSetup;
use App\Frontend\Build\FrontendBuild;
use App\Queue\QueueManager;
use App\RateLimit\RateLimiter;
use App\Redis\RedisManager;
use App\Session\SessionManager;
use App\Storage\StorageManager;
use Throwable;

/** Read-only local probes. Network I/O is limited to selected DB connections and Redis PING. */
final class CoreHealthChecks
{
    public function __construct(private Application $app)
    {
    }

    public function php(): HealthResult
    {
        $manifest = @file_get_contents($this->app->basePath('composer.json'));
        $requirement = is_string($manifest) ? json_decode($manifest, true)['require']['php'] ?? null : null;
        if (!is_string($requirement) || preg_match('/\A\^(\d+)\.(\d+)\z/D', $requirement, $match) !== 1) {
            return HealthResult::fail('php', 'runtime', 'PHP requirement is unavailable.', 'php_requirement_unknown');
        }
        return version_compare(PHP_VERSION, $match[1] . '.' . $match[2], '>=')
            && version_compare(PHP_VERSION, ((int) $match[1] + 1) . '.0', '<')
            ? HealthResult::pass('php', 'runtime', 'PHP version satisfies composer.json.')
            : HealthResult::fail('php', 'runtime', 'PHP version does not satisfy composer.json.', 'php_unsupported');
    }

    public function extensions(): HealthResult
    {
        foreach (['PDO', 'json', 'filter'] as $extension) {
            if (!extension_loaded($extension)) {
                return HealthResult::fail('extensions', 'runtime', 'A required PHP extension is missing.', 'extension_missing');
            }
        }
        return HealthResult::pass('extensions', 'runtime', 'Core PHP extensions are available.');
    }

    /** The shared registry derives Package health without executing entries. */
    public function packages(): HealthResult
    {
        try {
            $registry = $this->app->container()->make(ActivationRegistry::class);
            $descriptors = $registry->packages();
        } catch (Throwable) {
            return HealthResult::fail('packages', 'package',
                'Activation registry or Package metadata is invalid.', 'activation_invalid');
        }
        $counts = ['enabled' => 0, 'disabled' => 0, 'broken' => 0];
        foreach ($descriptors as $descriptor) {
            $status = $descriptor->status();
            $counts[array_key_exists($status, $counts) ? $status : 'broken']++;
        }
        $summary = sprintf('%d installed: %d enabled, %d disabled, %d broken.',
            count($descriptors), $counts['enabled'], $counts['disabled'], $counts['broken']);
        if ($counts['broken'] > 0) {
            $summary = self::withDependencyIssue($summary, $descriptors, 'Package');
            return HealthResult::fail('packages', 'package', $summary, 'packages_broken');
        }
        try {
            $health = $registry->storageHealth();
            if (($health['exists'] ?? false) && !($health['writable'] ?? false)) {
                return HealthResult::warning('packages', 'package',
                    $summary . ' Activation registry is not writable for lifecycle changes.',
                    'activation_unwritable');
            }
            if ($registry->legacyWarnings() !== []) {
                return HealthResult::warning('packages', 'package',
                    $summary . ' Legacy activation metadata needs review.', 'activation_legacy_stale');
            }
        } catch (Throwable) {
            return HealthResult::fail('packages', 'package',
                'Activation registry could not be inspected.', 'activation_invalid');
        }
        return HealthResult::pass('packages', 'package', $summary);
    }

    /** Kit composition health uses the same state without loading hook PHP. */
    public function kits(): HealthResult
    {
        try {
            $descriptors = $this->app->container()->make(ActivationRegistry::class)->kits();
        } catch (Throwable) {
            return HealthResult::fail('kits', 'kit',
                'Activation registry or Kit metadata is invalid.', 'activation_invalid');
        }
        $counts = ['enabled' => 0, 'disabled' => 0, 'broken' => 0];
        foreach ($descriptors as $descriptor) {
            $status = $descriptor->status();
            $counts[array_key_exists($status, $counts) ? $status : 'broken']++;
        }
        $summary = sprintf('%d installed: %d enabled, %d disabled, %d broken.',
            count($descriptors), $counts['enabled'], $counts['disabled'], $counts['broken']);
        if ($counts['broken'] > 0) {
            $summary = self::withDependencyIssue($summary, $descriptors, 'Kit');
        }
        return $counts['broken'] > 0
            ? HealthResult::fail('kits', 'kit', $summary, 'kits_broken')
            : HealthResult::pass('kits', 'kit', $summary);
    }

    /** Add one validated dependency hint while keeping Doctor's output bounded and secret-safe. */
    private static function withDependencyIssue(string $summary, array $descriptors, string $kind): string
    {
        foreach ($descriptors as $descriptor) {
            foreach ($descriptor->errors() as $error) {
                if (preg_match('/\ARequired Package ([A-Z][A-Za-z0-9_]*) (?:is not enabled|is missing)\.\z/D',
                    $error, $match) !== 1) {
                    continue;
                }
                $candidate = sprintf('%s %s requires enabled Package %s.',
                    $kind, $descriptor->name(), $match[1]);
                if (strlen($summary . ' ' . $candidate) <= 160) {
                    return $summary . ' ' . $candidate;
                }
            }
        }
        return $summary;
    }

    public function application(): HealthResult
    {
        $environment = $this->app->environment();
        if ($environment === '' || preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', $environment) !== 1) {
            return HealthResult::fail('environment', 'application', 'Application environment is invalid.', 'environment_invalid');
        }
        // Readiness must agree with the web setup boundary. A missing or
        // unchanged private environment cannot be considered deployable.
        $setup = EnvironmentSetup::status($this->app->basePath());
        if ($setup === EnvironmentSetup::MISSING) {
            return HealthResult::fail('environment', 'application',
                'Application .env is missing. Copy .example.env to .env and configure it.', 'env_missing');
        }
        if ($setup === EnvironmentSetup::UNCHANGED) {
            return HealthResult::fail('environment', 'application',
                'Application .env still matches .example.env; configure it.', 'env_unchanged');
        }
        return HealthResult::pass('environment', 'application', 'Application environment is configured.');
    }

    public function debug(): HealthResult
    {
        if ($this->app->environment() === 'production' && $this->app->isDebug()) {
            return HealthResult::fail('debug', 'application', 'Debug mode is enabled in production.', 'debug_enabled_in_production');
        }
        return HealthResult::pass('debug', 'application', 'Debug policy is acceptable.');
    }

    /**
     * Optional Node status is inspected only after an adapter is selected.
     * Doctor reads the local executable and build files; it never starts Vite,
     * probes an HTTP port, or makes Node a PHP runtime requirement.
     */
    public function frontend(): HealthResult
    {
        try {
            $status = (new FrontendBuild($this->app))->status();
            if (!$status['configured']) {
                return HealthResult::skipped('frontend', 'frontend',
                    'PHP-only frontend; no build adapter is selected.', 'not_selected');
            }
            // Production serves verified files; Node is a build-time tool and
            // must not become a runtime health requirement after deployment.
            if ($status['build_available']) {
                return HealthResult::pass('frontend', 'frontend',
                    'Selected Vite frontend has a verified production build.');
            }
            if ($this->app->environment() === 'production') {
                return HealthResult::fail('frontend', 'frontend',
                    'Selected Vite frontend has no verified production build.', 'build_missing');
            }
            if (!$status['node_installed'] || !$status['vite_installed']) {
                return HealthResult::warning('frontend', 'frontend',
                    'Selected Vite frontend needs local Node packages for development/build.',
                    'tooling_unavailable');
            }
            return HealthResult::warning('frontend', 'frontend',
                'Selected Vite frontend has no verified production build.', 'build_missing');
        } catch (Throwable) {
            return HealthResult::fail('frontend', 'frontend',
                'Selected frontend configuration is invalid.', 'frontend_invalid');
        }
    }

    public function database(): HealthResult
    {
        try {
            $manager = $this->app->container()->make(DatabaseManager::class);
            $connection = $manager->connection();
            $extension = 'pdo_' . $connection->driver();
            if (!extension_loaded($extension)) {
                return HealthResult::fail('database', 'database', 'Selected PDO driver is unavailable.', 'pdo_driver_missing');
            }
            // SELECT 1 does not inspect or mutate application records.
            $value = $connection->raw('SELECT 1')->fetchColumn();
            return (int) $value === 1
                ? HealthResult::pass('database', 'database', 'Default database is reachable.')
                : HealthResult::fail('database', 'database', 'Default database probe failed.', 'database_unavailable');
        } catch (Throwable) {
            // PDO exceptions can contain DSNs and credentials.
            return HealthResult::fail('database', 'database', 'Default database is unavailable.', 'database_unavailable');
        }
    }

    public function redis(): HealthResult
    {
        if (!$this->app->container()->has(RedisManager::class)) {
            return HealthResult::skipped('redis', 'redis', 'Redis provider is absent.', 'not_configured');
        }
        $status = $this->app->container()->make(RedisManager::class)->capability(probe: true)['status'];
        return match ($status) {
            'available' => HealthResult::pass('redis', 'redis', 'Redis is reachable.'),
            'not_configured' => HealthResult::skipped('redis', 'redis', 'Redis is optional and unconfigured.', 'not_configured'),
            default => HealthResult::warning('redis', 'redis', 'Optional Redis is unavailable.', 'redis_unavailable'),
        };
    }

    public function cache(): HealthResult
    {
        // Inspect the optional runtime before resolving CacheStore; selected
        // Memcached must remain diagnosable when its extension is absent.
        if ($this->app->config()->get('cache.driver') === 'memcached'
            && (!extension_loaded('memcached') || !defined('Memcached::GET_EXTENDED'))) {
            return HealthResult::fail('cache', 'cache',
                'Selected Memcached Cache requires the optional extension.', 'extension_missing');
        }
        $service = $this->app->container()->make(CacheStore::class);
        $selected = $service->infrastructure()?->selected();
        if ($selected === 'memcached') {
            return HealthResult::warning('cache', 'cache',
                'Memcached Cache is configured; reachability was not checked.', 'not_probed');
        }
        return $this->driver('cache', 'cache', $selected, $this->app->config()->get('cache.redis_connection'));
    }

    public function session(): HealthResult
    {
        $service = $this->app->container()->make(SessionManager::class);
        // Constructing the store validates configuration without session_start().
        $service->store();
        return $this->driver('session', 'session', $service->infrastructure()?->selected(),
            $this->app->config()->get('session.redis_connection'));
    }

    public function rateLimit(): HealthResult
    {
        $service = $this->app->container()->make(RateLimiter::class);
        return $this->driver('rate_limit', 'rate_limit', $service->infrastructure()?->selected(),
            $this->app->config()->get('rateLimit.redis_connection'));
    }

    public function queue(): HealthResult
    {
        $service = $this->app->container()->make(QueueManager::class);
        $name = $service->defaultName();
        $service->driver($name); // Resolves auto without dispatching work.
        $selected = $service->infrastructure($name)?->selected();
        $config = $this->queueConfig($name);
        if (($config['driver'] ?? null) === 'auto' && is_string($selected)) {
            $config = $this->queueConfig($selected);
        }
        if ($selected === 'redis') {
            return $this->driver('queue', 'queue', 'redis', $config['redis_connection'] ?? null);
        }
        if ($selected === 'database') {
            return $this->tables('queue', 'queue', $config['database_connection'] ?? null,
                [$config['table'] ?? 'queue_jobs', $config['failed_table'] ?? 'queue_failed_jobs'], true);
        }
        return $this->driver('queue', 'queue', $selected);
    }

    public function scheduler(): HealthResult
    {
        $store = $this->app->config()->get('scheduler.store', 'database');
        if ($store === 'array') return HealthResult::pass('scheduler', 'scheduler', 'In-memory scheduler store selected.');
        if ($store !== 'database') {
            return HealthResult::fail('scheduler', 'scheduler', 'Scheduler store is unsupported.', 'scheduler_invalid');
        }
        try {
            $result = $this->tables('scheduler', 'scheduler', $this->app->config()->get('scheduler.database_connection'),
                [$this->app->config()->get('scheduler.runs_table', 'schedule_runs'),
                    $this->app->config()->get('scheduler.locks_table', 'schedule_locks')]);
        } catch (Throwable) {
            $result = HealthResult::fail('scheduler', 'scheduler',
                'Scheduler persistence could not be inspected.', 'scheduler_unavailable');
        }
        // A web application may have no scheduled work. Doctor still reports
        // its migration gap, while readiness can opt in to requiring it.
        return $result->status() === 'fail' && $this->app->config()->get('health.require_scheduler', false) !== true
            ? HealthResult::warning('scheduler', 'scheduler', 'Scheduler persistence is not ready.', $result->code())
            : $result;
    }

    public function storage(): HealthResult
    {
        $config = $this->app->config();
        $name = $config->get('storage.default', 'local');
        if (!is_string($name) || $name === '') {
            return HealthResult::fail('storage', 'storage', 'Default Storage drive is invalid.', 'storage_invalid');
        }
        $drive = $config->get('storage.drives.' . $name);
        if (!is_array($drive)) return HealthResult::fail('storage', 'storage', 'Storage drive is missing.', 'storage_invalid');
        if (($drive['driver'] ?? null) === 's3'
            && (!class_exists('Aws\\S3\\S3Client')
                || !class_exists('Aws\\S3\\ObjectUploader')
                || !class_exists('Aws\\S3\\ObjectCopier'))) {
            return HealthResult::fail('storage', 'storage',
                'Selected S3 drive requires optional AWS SDK.', 'storage_dependency_missing');
        }
        try {
            $this->app->container()->make(StorageManager::class)->drive($name);
        } catch (Throwable) {
            return HealthResult::fail('storage', 'storage', 'Storage drive is invalid.', 'storage_invalid');
        }
        if (($drive['driver'] ?? null) === 's3') {
            return HealthResult::warning('storage', 'storage',
                'S3 Storage is configured; remote reachability was not checked.', 'not_probed');
        }
        if (($drive['driver'] ?? null) === 'array') {
            return HealthResult::pass('storage', 'storage', 'In-memory Storage drive selected.');
        }
        if (($drive['driver'] ?? null) !== 'local') {
            return HealthResult::fail('storage', 'storage', 'Storage drive is unsupported.', 'storage_invalid');
        }
        $root = $drive['root'] ?? null;
        $root = is_string($root) && $root !== '' ? $root : $this->app->basePath('Storage/Files');
        if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|/|\\\\\\\\)~', $root)) $root = $this->app->basePath($root);
        $cursor = $root;
        while (!file_exists($cursor) && dirname($cursor) !== $cursor) $cursor = dirname($cursor);
        return is_dir($cursor) && is_writable($cursor)
            ? HealthResult::pass('storage', 'storage', 'Local Storage root is writable or creatable.')
            : HealthResult::fail('storage', 'storage', 'Local Storage root is not writable.', 'storage_not_writable');
    }

    public function mail(): HealthResult
    {
        $mail = $this->app->config()->get('mail', []);
        $default = is_array($mail) ? ($mail['default'] ?? null) : null;
        $transport = is_string($default) ? ($mail['transports'][$default] ?? null) : null;
        if (!is_array($transport)) return HealthResult::fail('mail', 'mail', 'Mail transport is invalid.', 'mail_invalid');
        if (!in_array($transport['driver'] ?? null, ['smtp', 'array', 'resend', 'postmark'], true)) {
            return HealthResult::fail('mail', 'mail', 'Mail transport is unsupported.', 'mail_invalid');
        }
        if (in_array($transport['driver'], ['resend', 'postmark'], true)) {
            if (!is_string($transport['api_key'] ?? null) || $transport['api_key'] === '') {
                return HealthResult::warning('mail', 'mail',
                    'Selected HTTP Mail provider token is not configured.', 'mail_unconfigured');
            }
            if (!is_int($transport['timeout'] ?? null) || $transport['timeout'] < 1
                || $transport['timeout'] > 120) {
                return HealthResult::fail('mail', 'mail',
                    'HTTP Mail provider timeout is invalid.', 'mail_invalid');
            }
        }
        if ($transport['driver'] === 'smtp' && (!is_string($transport['host'] ?? null)
            || $transport['host'] === '')) {
            return HealthResult::warning('mail', 'mail', 'SMTP is not configured.', 'mail_unconfigured');
        }
        if ($transport['driver'] === 'smtp'
            && (!is_int($transport['port'] ?? null) || $transport['port'] < 1 || $transport['port'] > 65535
                || !is_int($transport['timeout'] ?? null) || $transport['timeout'] < 1 || $transport['timeout'] > 120
                || !in_array($transport['encryption'] ?? null, ['tls', 'ssl', 'none'], true)
                || (($transport['username'] ?? null) === null) !== (($transport['password'] ?? null) === null))) {
            return HealthResult::fail('mail', 'mail', 'SMTP settings are invalid.', 'mail_invalid');
        }
        if ($transport['driver'] === 'smtp' && ($transport['verify_peer'] ?? true) !== true
            && $this->app->environment() === 'production') {
            return HealthResult::fail('mail', 'mail', 'SMTP TLS peer verification is disabled.', 'tls_verification_disabled');
        }
        $sender = $mail['from']['address'] ?? null;
        if (!is_string($sender) || filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            return HealthResult::warning('mail', 'mail', 'Mail sender is not configured.', 'mail_sender_missing');
        }
        return in_array($transport['driver'], ['resend', 'postmark'], true)
            ? HealthResult::warning('mail', 'mail',
                'HTTP Mail provider is configured; live delivery was not checked.', 'not_probed')
            : HealthResult::pass('mail', 'mail', 'Mail settings are structurally ready; no message was sent.');
    }

    public function httpClient(): HealthResult
    {
        if ($this->app->environment() === 'production'
            && $this->app->config()->get('httpClient.verify_peer', true) !== true) {
            return HealthResult::fail('http_client', 'http_client', 'Outbound TLS verification is disabled.', 'tls_verification_disabled');
        }
        if (extension_loaded('curl')) {
            return HealthResult::pass('http_client', 'http_client', 'cURL is available; no external request was made.');
        }
        return $this->app->config()->get('health.require_http_client', false) === true
            ? HealthResult::fail('http_client', 'http_client', 'Required cURL extension is unavailable.', 'curl_unavailable')
            : HealthResult::warning('http_client', 'http_client', 'Optional cURL extension is unavailable.', 'curl_unavailable');
    }

    public function crypt(): HealthResult
    {
        $required = $this->app->config()->get('health.require_crypt', false) === true;
        try {
            $crypt = $this->app->container()->make(CryptManager::class);
            // A fixed in-memory MAC asks Crypt to validate the configured key.
            // Neither the key nor MAC is persisted or placed in the report.
            $signature = $crypt->sign('squehub-health', 'doctor');
            if (!$crypt->verify('squehub-health', $signature, 'doctor')) throw new HealthException('Crypt self-check failed.');
            return HealthResult::pass('crypt', 'crypt', 'Crypt key and backend are ready.');
        } catch (Throwable) {
            return $required
                ? HealthResult::fail('crypt', 'crypt', 'Required Crypt configuration is unavailable.', 'crypt_unavailable')
                : HealthResult::warning('crypt', 'crypt', 'Optional Crypt configuration is unavailable.', 'crypt_unavailable');
        }
    }

    /** Explicit Redis selection never falls back after an outage. */
    private function driver(string $name, string $category, ?string $selected, mixed $redisName = null): HealthResult
    {
        if ($selected === null) return HealthResult::fail($name, $category, 'Driver selection is unavailable.', 'driver_unavailable');
        if ($selected !== 'redis') {
            if ($selected === 'file' && in_array($name, ['cache', 'rate_limit'], true)) {
                $path = $name === 'cache' ? $this->app->config()->get('cache.path')
                    : $this->app->config()->get('rateLimit.path');
                $default = $name === 'cache' ? 'Storage/Cache' : 'Storage/RateLimits';
                $root = is_string($path) && $path !== '' ? $path : $this->app->basePath($default);
                if (!$this->writableOrCreatable($root)) {
                    return HealthResult::fail($name, $category, 'Selected file backend is not writable.', 'backend_not_writable');
                }
            }
            return HealthResult::pass($name, $category, 'Selected backend: ' . $selected . '.');
        }
        $redis = $this->app->container()->make(RedisManager::class);
        $status = $redis->capability(is_string($redisName) ? $redisName : null, true)['status'];
        return $status === 'available'
            ? HealthResult::pass($name, $category, 'Selected Redis backend is reachable.')
            : HealthResult::fail($name, $category, 'Selected Redis backend is unavailable.', 'redis_unavailable');
    }

    /** Database schema inspection is read-only and uses the selected connection. */
    private function tables(string $name, string $category, mixed $connection, array $tables,
        bool $failedPayload = false): HealthResult
    {
        $schema = $this->app->container()->make(DatabaseManager::class)
            ->schema(is_string($connection) && $connection !== '' ? $connection : null);
        foreach ($tables as $table) {
            if (!is_string($table) || !$schema->hasTable($table)) {
                return HealthResult::fail($name, $category, 'Required persistence table is missing.', 'missing_table');
            }
        }
        if ($failedPayload && !$schema->hasColumn($tables[1], 'payload')) {
            return HealthResult::warning($name, $category, 'Failed-job payload migration is pending.', 'failed_payload_missing');
        }
        return HealthResult::pass($name, $category, 'Selected persistence tables are ready.');
    }

    /** @return array<string,mixed> */
    private function queueConfig(string $name): array
    {
        $connections = $this->app->config()->get('queue.connections', []);
        $config = is_array($connections) ? ($connections[$name] ?? null) : null;
        return is_array($config) ? $config : [];
    }

    /** Writability is inferred without creating runtime files or directories. */
    private function writableOrCreatable(string $root): bool
    {
        $cursor = $root;
        while (!file_exists($cursor) && dirname($cursor) !== $cursor) $cursor = dirname($cursor);
        return is_dir($cursor) && is_writable($cursor);
    }
}
