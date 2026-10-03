<?php

declare(strict_types=1);

namespace App\Health;

use App\Cache\CacheStore;
use App\Container\Container;
use App\Foundation\Application;
use App\Queue\QueueManager;
use App\RateLimit\RateLimiter;
use App\Session\SessionManager;
use App\Support\SecretRedactor;
use Throwable;

/** Coordinates fresh sequential checks; each Application owns its own registry. */
final class HealthManager
{
    /** @var array<string,array{resolver:mixed,ready:bool}> */
    private array $checks = [];
    private CoreHealthChecks $core;
    private SecretRedactor $redactor;

    public function __construct(private Application $app, private Container $container)
    {
        $this->core = new CoreHealthChecks($app);
        $this->redactor = new SecretRedactor($app->config());
    }

    /**
     * Register a package check without instantiating it. Class checks receive
     * normal container injection; closures are trusted application code.
     * The ready flag opts a check into every readiness request.
     */
    public function register(string $name, mixed $check, bool $ready = false): void
    {
        if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $name) !== 1
            || isset($this->checks[$name]) || in_array($name, self::coreNames(), true)
            || $this->redactor->redact($name) !== $name) {
            throw new HealthException('Health check name is invalid or already registered.');
        }
        if (is_string($check) && !is_subclass_of($check, HealthCheck::class)) {
            throw new HealthException('Health check class must implement HealthCheck.');
        }
        if (!is_string($check) && !$check instanceof HealthCheck && !is_callable($check)) {
            throw new HealthException('Health check must be a class, object, or callable.');
        }
        $this->checks[$name] = ['resolver' => $check, 'ready' => $ready];
    }

    /** Liveness intentionally performs no infrastructure, filesystem, or network I/O. */
    public function live(): HealthReport
    {
        return new HealthReport('live', [HealthResult::pass('application', 'application', 'Application is alive.')]);
    }

    /** Only selected or explicitly required dependencies participate in readiness. */
    public function ready(): HealthReport
    {
        $results = [];
        $config = $this->app->config();
        $tasks = [
            ['environment', 'application', fn () => $this->core->application()],
            ['debug', 'application', fn () => $this->core->debug()],
        ];
        if ($config->get('health.require_database', true) === true) {
            $tasks[] = ['database', 'database', fn () => $this->core->database()];
        }
        foreach (['cache', 'session', 'rate_limit'] as $name) {
            $tasks[] = [$name, $name, fn () => $this->coreFor($name)];
        }
        if ($config->get('health.require_queue', false) === true) {
            $tasks[] = ['queue', 'queue', fn () => $this->core->queue()];
        } else {
            // An explicitly persistent queue is an application dependency;
            // the shipped sync queue needs no external readiness probe.
            $queue = $config->get('queue.default', 'sync');
            if ($queue !== 'sync') $tasks[] = ['queue', 'queue', fn () => $this->core->queue()];
        }
        if ($config->get('health.require_scheduler', false) === true) {
            $tasks[] = ['scheduler', 'scheduler', fn () => $this->core->scheduler()];
        }
        if ($config->get('health.require_storage', true) === true) {
            $tasks[] = ['storage', 'storage', fn () => $this->core->storage()];
        }
        if ($config->get('health.require_crypt', false) === true) {
            $tasks[] = ['crypt', 'crypt', fn () => $this->core->crypt()];
        }
        if ($config->get('health.require_http_client', false) === true) {
            $tasks[] = ['http_client', 'http_client', fn () => $this->core->httpClient()];
        }
        foreach ($tasks as [$name, $category, $run]) $results[] = $this->evaluate($name, $category, $run);
        foreach ($this->checks as $name => $entry) {
            if ($entry['ready']) $results[] = $this->custom($name, $entry['resolver']);
        }
        return new HealthReport('ready', $results);
    }

    /** Broad deployment inspection; no mail, queue, cache, or schedule writes. */
    public function doctor(): HealthReport
    {
        $tasks = [
            ['php', 'runtime', fn () => $this->core->php()],
            ['extensions', 'runtime', fn () => $this->core->extensions()],
            ['environment', 'application', fn () => $this->core->application()],
            ['debug', 'application', fn () => $this->core->debug()],
            ['database', 'database', fn () => $this->core->database()],
            ['frontend', 'frontend', fn () => $this->core->frontend()],
            ['redis', 'redis', fn () => $this->core->redis()],
            ['cache', 'cache', fn () => $this->core->cache()],
            ['session', 'session', fn () => $this->core->session()],
            ['rate_limit', 'rate_limit', fn () => $this->core->rateLimit()],
            ['queue', 'queue', fn () => $this->core->queue()],
            ['scheduler', 'scheduler', fn () => $this->core->scheduler()],
            ['storage', 'storage', fn () => $this->core->storage()],
            ['mail', 'mail', fn () => $this->core->mail()],
            ['http_client', 'http_client', fn () => $this->core->httpClient()],
            ['crypt', 'crypt', fn () => $this->core->crypt()],
            ['packages', 'package', fn () => $this->core->packages()],
            ['kits', 'kit', fn () => $this->core->kits()],
        ];
        $results = [];
        foreach ($tasks as [$name, $category, $run]) $results[] = $this->evaluate($name, $category, $run);
        foreach ($this->checks as $name => $entry) $results[] = $this->custom($name, $entry['resolver']);
        return new HealthReport('doctor', $results);
    }

    /**
     * Report the subsystem's own fixed InfrastructureSelection. First access to
     * unresolved auto services may perform their normal one-time Redis probe.
     * Errors are categorical and never contain underlying connection details.
     *
     * @return array<string,array{configured:string,selected:?string,reason:?string}>
     */
    public function infrastructure(): array
    {
        $config = $this->app->config();
        $rows = [];
        $queueName = $config->get('queue.default', 'sync');
        $queueConnections = $config->get('queue.connections', []);
        $queueConfigured = is_string($queueName) && is_array($queueConnections)
            && is_array($queueConnections[$queueName] ?? null)
            ? ($queueConnections[$queueName]['driver'] ?? null) : null;
        foreach (['cache', 'session', 'rate_limit', 'queue'] as $name) {
            $configured = match ($name) {
                'cache' => $config->get('cache.driver', 'file'),
                'session' => $config->get('session.driver', 'native'),
                'rate_limit' => $config->get('rateLimit.driver', $config->get('rateLimit.store', 'file')),
                'queue' => $queueConfigured,
            };
            try {
                $selection = match ($name) {
                    'cache' => $this->container->make(CacheStore::class)->infrastructure(),
                    'session' => $this->sessionSelection(),
                    'rate_limit' => $this->container->make(RateLimiter::class)->infrastructure(),
                    'queue' => $this->queueSelection(),
                };
                $rows[$name] = ['configured' => self::safeDriver($name, $configured),
                    'selected' => self::safeDriver($name, $selection?->selected(), true),
                    'reason' => self::safeReason($selection?->reason())];
            } catch (Throwable) {
                $rows[$name] = ['configured' => self::safeDriver($name, $configured),
                    'selected' => null, 'reason' => 'selection_failed'];
            }
        }
        $storageName = $config->get('storage.default', 'local');
        $storage = is_string($storageName) ? $config->get('storage.drives.' . $storageName . '.driver') : null;
        foreach (['storage' => $storage, 'scheduler' => $config->get('scheduler.store', 'database')] as $name => $configured) {
            $driver = self::safeDriver($name, $configured);
            $rows[$name] = ['configured' => $driver,
                'selected' => $driver === 'invalid' ? null : $driver, 'reason' => 'explicit'];
        }
        return $rows;
    }

    private function coreFor(string $name): HealthResult
    {
        return match ($name) {
            'cache' => $this->core->cache(), 'session' => $this->core->session(),
            'rate_limit' => $this->core->rateLimit(),
            default => throw new HealthException('Unknown core health check.'),
        };
    }

    private function sessionSelection(): ?\App\Redis\InfrastructureSelection
    {
        $manager = $this->container->make(SessionManager::class);
        $manager->store(); // Validates and selects, without starting a browser session.
        return $manager->infrastructure();
    }

    private function queueSelection(): ?\App\Redis\InfrastructureSelection
    {
        $manager = $this->container->make(QueueManager::class);
        $name = $manager->defaultName();
        $manager->driver($name); // Constructs but never dispatches or reserves a job.
        return $manager->infrastructure($name);
    }

    private function custom(string $name, mixed $resolver): HealthResult
    {
        return $this->evaluate($name, 'package', function () use ($resolver): HealthResult {
            $check = is_string($resolver) ? $this->container->make($resolver) : $resolver;
            return $check instanceof HealthCheck ? $check->check() : $check();
        });
    }

    /** Exceptions are isolated; raw exception messages never enter a report. */
    private function evaluate(string $name, string $category, callable $run): HealthResult
    {
        $started = hrtime(true);
        try {
            $result = $run();
            if (!$result instanceof HealthResult || $result->name() !== $name
                || ($category === 'package' && $result->category() !== 'package')) {
                throw new HealthException('Health check returned an invalid result.');
            }
            if ($category === 'package' && $this->redactor->redact($result->code()) !== $result->code()) {
                throw new HealthException('Health check returned an unsafe reason code.');
            }
            // Core summaries are fixed literals. Package text is trusted code,
            // but still passes through the existing configured-secret redactor.
            $summary = $category === 'package' ? $this->redactor->redact($result->summary())
                : $result->summary();
            return $result->measured(max(0, hrtime(true) - $started) / 1_000_000, $summary);
        } catch (Throwable) {
            return HealthResult::fail($name, $category, 'Check could not complete.', 'check_failed')
                ->measured(max(0, hrtime(true) - $started) / 1_000_000, 'Check could not complete.');
        }
    }

    /** @return list<string> */
    private static function coreNames(): array
    {
        return ['application', 'php', 'extensions', 'environment', 'debug', 'database', 'redis',
            'cache', 'session', 'rate_limit', 'queue', 'scheduler', 'storage', 'mail', 'http_client', 'crypt',
            'packages', 'kits', 'frontend'];
    }

    private static function safeDriver(string $name, mixed $value, bool $nullable = false): ?string
    {
        if ($value === null && $nullable) return null;
        $allowed = match ($name) {
            'cache' => ['file', 'array', 'redis', 'auto', 'memcached'],
            'rate_limit' => ['file', 'array', 'redis', 'auto'],
            'session' => ['native', 'array', 'redis', 'auto'],
            'queue' => ['sync', 'database', 'redis', 'auto'],
            'storage' => ['local', 'array', 's3'],
            'scheduler' => ['database', 'array'],
            default => [],
        };
        return is_string($value) && in_array($value, $allowed, true) ? $value : 'invalid';
    }

    private static function safeReason(?string $reason): ?string
    {
        return in_array($reason, ['explicit', 'available', 'not_configured', 'client_unavailable',
            'not_probed', 'unreachable', 'redis_provider_absent'], true) ? $reason : null;
    }
}
