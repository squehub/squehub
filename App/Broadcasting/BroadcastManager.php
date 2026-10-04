<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Auth\AuthManager;
use App\Auth\Contracts\Authenticatable;
use App\Broadcasting\Adapters\ArrayBroadcastAdapter;
use App\Broadcasting\Adapters\NullBroadcastAdapter;
use App\Broadcasting\Queue\DeliverBroadcast;
use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Queue\QueueCodec;
use App\Queue\QueueManager;
use Closure;
use Throwable;

/**
 * Owns one Application's broadcast adapters and private-channel rules.
 * Publication never grants a browser permission to subscribe; a provider
 * adapter must enforce authorizePrivate() at its subscription handshake.
 */
final class BroadcastManager
{
    /** @var array<string,Closure|BroadcastAdapter> */
    private array $definitions = [];
    /** @var array<string,BroadcastAdapter> */
    private array $resolved = [];
    /** @var array<string,array{regex:string,parameters:list<string>,authorizer:Closure|string}> */
    private array $privateRules = [];
    private bool $enabled;
    private string $driver;
    private int $maxPayloadBytes;

    /** @param array<string,mixed> $settings */
    public function __construct(private Container $container, array $settings,
        private ?Diagnostics $diagnostics = null)
    {
        if (array_diff(array_keys($settings), ['enabled', 'driver', 'max_payload_bytes']) !== []) {
            throw new BroadcastException('Broadcast configuration has an unknown setting.');
        }
        $settings += ['enabled' => false, 'driver' => 'null', 'max_payload_bytes' => 32768];
        if (!is_bool($settings['enabled']) || !is_string($settings['driver'])
            || !self::validIdentifier($settings['driver'])
            || !is_int($settings['max_payload_bytes'])
            || $settings['max_payload_bytes'] < 1024 || $settings['max_payload_bytes'] > 32768) {
            throw new BroadcastException('Broadcast configuration is invalid.');
        }
        $this->enabled = $settings['enabled'];
        $this->driver = $settings['driver'];
        $this->maxPayloadBytes = $settings['max_payload_bytes'];
        $this->definitions['array'] = static fn (): BroadcastAdapter => new ArrayBroadcastAdapter();
        $this->definitions['null'] = static fn (): BroadcastAdapter => new NullBroadcastAdapter();
    }

    public function enabled(): bool { return $this->enabled; }

    /**
     * Static broadcast policy metadata for local inspection. No adapter factory
     * or private-channel authorizer is resolved or called.
     *
     * @return array{enabled:bool,driver:string,patterns:list<string>,truncated:bool}
     */
    public function inspection(int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));
        $patterns = array_keys($this->privateRules);
        return ['enabled' => $this->enabled, 'driver' => $this->driver,
            'patterns' => array_slice($patterns, 0, $limit),
            'truncated' => count($patterns) > $limit];
    }

    /** Register a provider without resolving or connecting to it at boot. */
    public function registerAdapter(string $name, Closure|BroadcastAdapter $adapter): void
    {
        if (!self::validIdentifier($name) || isset($this->definitions[$name])) {
            throw new BroadcastException('Broadcast adapter name is invalid or already registered.');
        }
        $this->definitions[$name] = $adapter;
    }

    public function adapter(): BroadcastAdapter
    {
        $this->requireEnabled();
        if (isset($this->resolved[$this->driver])) return $this->resolved[$this->driver];
        $definition = $this->definitions[$this->driver] ?? null;
        if ($definition === null) throw new BroadcastException('Configured broadcast adapter is unavailable.');
        try {
            $adapter = $definition instanceof Closure ? $definition() : $definition;
        } catch (Throwable) {
            throw new BroadcastException('Broadcast adapter could not be resolved.');
        }
        if (!$adapter instanceof BroadcastAdapter) {
            throw new BroadcastException('Broadcast adapter factory returned an invalid adapter.');
        }
        return $this->resolved[$this->driver] = $adapter;
    }

    /** One private rule may be a trusted callback or a lazy Container class. */
    public function privateChannel(string $pattern, callable|string $authorizer): void
    {
        [$regex, $parameters] = self::compilePattern($pattern);
        if (isset($this->privateRules[$pattern])) {
            throw new BroadcastException('Private-channel rule is already registered.');
        }
        if (is_string($authorizer)) {
            if (!class_exists($authorizer) || !is_subclass_of($authorizer, ChannelAuthorizer::class)) {
                throw new BroadcastException('Private-channel authorizer class is invalid.');
            }
            $rule = $authorizer;
        } else {
            $rule = Closure::fromCallable($authorizer);
        }
        $this->privateRules[$pattern] = ['regex' => $regex, 'parameters' => $parameters,
            'authorizer' => $rule];
    }

    /** A missing identity, including a pending MFA challenge, is always denied. */
    public function authorizePrivate(string $channel): bool
    {
        if (!$this->enabled || !Channel::validName($channel)) return false;
        $matched = $this->matchingRule($channel);
        if ($matched === null || !$this->container->has(AuthManager::class)) return false;
        $auth = $this->container->make(AuthManager::class);
        if (!$auth->hasDefaultGuard()) return false;
        $identity = $auth->user();
        if (!$identity instanceof Authenticatable) return false;
        try {
            $rule = $matched['authorizer'];
            if (is_string($rule)) {
                $rule = $this->container->make($rule);
            }
            $result = $rule instanceof ChannelAuthorizer
                ? $rule->authorize($identity, $matched['values'])
                : $rule($identity, $matched['values']);
            if (!is_bool($result)) throw new BroadcastException('Private-channel rule result is invalid.');
            return $result;
        } catch (Throwable) {
            // Application rules may throw with identity or permission details.
            throw new BroadcastException('Private-channel authorization failed.');
        }
    }

    public function send(BroadcastEvent $event): void
    {
        $this->requireEnabled();
        $this->deliver(BroadcastMessage::capture($event, $this->maxPayloadBytes), 'sync');
    }

    public function queue(BroadcastEvent $event, string $queue = 'default', int $delay = 0,
        ?string $connection = null, bool $afterCommit = false,
        ?string $transactionConnection = null): void
    {
        $started = hrtime(true);
        $failed = false;
        $category = 'unknown';
        try {
            $this->requireEnabled();
            $message = BroadcastMessage::capture($event, $this->maxPayloadBytes);
            $category = self::channelCategory($message);
            $this->requirePrivateRules($message);
            $job = DeliverBroadcast::capture($message, $this);
            // Sync Queue skips its codec; validate the same durable envelope now.
            QueueCodec::encode($job);
            if (!$this->container->has(QueueManager::class)) {
                throw new BroadcastException('Queue service is unavailable for broadcasting.');
            }
            $manager = $this->container->make(QueueManager::class);
            if ($afterCommit) {
                $manager->afterCommit($job, $queue, $delay, $connection, $transactionConnection);
            } else {
                $manager->dispatch($job, $queue, $delay, $connection);
            }
        } catch (Throwable $exception) {
            $failed = true;
            throw $exception;
        } finally {
            $this->diagnostics?->broadcastTime('enqueue',
                (hrtime(true) - $started) / 1_000_000, $failed,
                $this->driver, $category, 'queued');
        }
    }

    /** @internal Worker delivery is revalidated against its own Application. */
    public function deliver(BroadcastMessage $message, string $mode = 'queued'): void
    {
        $started = hrtime(true);
        $failed = false;
        try {
            $this->requireEnabled();
            BroadcastMessage::fromArray($message->toArray(), $this->maxPayloadBytes);
            $this->requirePrivateRules($message);
            $adapter = $this->adapter();
            try {
                $adapter->publish($message);
            } catch (Throwable) {
                // A custom adapter exception may contain a provider credential.
                throw new BroadcastException('Broadcast delivery failed.');
            }
        } catch (Throwable $exception) {
            $failed = true;
            throw $exception;
        } finally {
            $this->diagnostics?->broadcastTime('publish',
                (hrtime(true) - $started) / 1_000_000, $failed,
                $this->driver, self::channelCategory($message), $mode);
        }
    }

    /** Channel names are unbounded application data; only the type reaches telemetry. */
    private static function channelCategory(BroadcastMessage $message): string
    {
        $types = array_unique(array_map(static fn (Channel $channel): string => $channel->type(),
            $message->channels()));
        return count($types) === 1 ? $types[0] : 'mixed';
    }

    private function requirePrivateRules(BroadcastMessage $message): void
    {
        foreach ($message->channels() as $channel) {
            if ($channel->type() === 'private' && $this->matchingRule($channel->name()) === null) {
                throw new BroadcastException('Private broadcast channel has no authorizer.');
            }
        }
    }

    /** @return ?array{authorizer:Closure|string,values:array<string,string>} */
    private function matchingRule(string $channel): ?array
    {
        $match = null;
        foreach ($this->privateRules as $definition) {
            if (preg_match($definition['regex'], $channel, $parts) !== 1) continue;
            // Ambiguous rules must never become an order-dependent allow.
            if ($match !== null) throw new BroadcastException('Private-channel rules are ambiguous.');
            $values = [];
            foreach ($definition['parameters'] as $index => $parameter) {
                $values[$parameter] = $parts[$index + 1];
            }
            $match = ['authorizer' => $definition['authorizer'], 'values' => $values];
        }
        return $match;
    }

    /** @return array{string,list<string>} */
    private static function compilePattern(string $pattern): array
    {
        if (strlen($pattern) > 128 || $pattern === '') {
            throw new BroadcastException('Private-channel pattern is invalid.');
        }
        $segments = explode('.', $pattern);
        $regex = [];
        $parameters = [];
        foreach ($segments as $index => $segment) {
            if (preg_match('/\A\{([A-Za-z_][A-Za-z0-9_]*)\}\z/D', $segment, $found) === 1) {
                if ($index === 0 || in_array($found[1], $parameters, true)) {
                    throw new BroadcastException('Private-channel pattern is invalid.');
                }
                $parameters[] = $found[1];
                $regex[] = '([A-Za-z0-9_-]+)';
            } elseif (preg_match('/\A[A-Za-z0-9_-]+\z/D', $segment) === 1) {
                $regex[] = preg_quote($segment, '/');
            } else {
                throw new BroadcastException('Private-channel pattern is invalid.');
            }
        }
        return ['/\A' . implode('\\.', $regex) . '\z/D', $parameters];
    }

    private static function validIdentifier(string $name): bool
    {
        return strlen($name) <= 128
            && preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $name) === 1;
    }

    private function requireEnabled(): void
    {
        if (!$this->enabled) throw new BroadcastException('Broadcasting is disabled for this Application.');
    }
}
