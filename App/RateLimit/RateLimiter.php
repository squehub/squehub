<?php

declare(strict_types=1);

namespace App\RateLimit;

use App\Container\Container;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Redis\InfrastructureSelection;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use ReflectionMethod;
use Throwable;

/**
 * Application-owned policy registry and fingerprint boundary. Auth and account
 * security choose their own limits by composition; neither owns this service.
 */
final class RateLimiter
{
    /** @var array<string, callable|string> */
    private array $definitions = [];
    private Closure $clock;

    /** @param Closure():DateTimeImmutable|null $clock */
    public function __construct(
        private RateLimitStore $store,
        private string $prefix,
        private ?Container $container = null,
        private ?Diagnostics $diagnostics = null,
        ?Closure $clock = null,
        private ?InfrastructureSelection $selection = null
    ) {
        RateLimitKey::prefix($prefix);
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    /** Backend selection is fixed for this limiter's Application lifetime. */
    public function infrastructure(): ?InfrastructureSelection
    {
        return $this->selection;
    }

    public function consume(
        string $bucket,
        #[\SensitiveParameter] string $key,
        int $maxAttempts,
        int $windowSeconds
    ): RateLimitResult {
        try {
            $rule = RateLimitRule::fixed($key, $maxAttempts, $windowSeconds);
        } catch (RateLimitException $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure;
        }
        return $this->consumeRule($bucket, $rule);
    }

    public function clear(string $bucket, #[\SensitiveParameter] string $key): bool
    {
        try {
            $fingerprint = $this->fingerprint($bucket, RateLimitKey::digest($key));
        } catch (Throwable $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure;
        }
        $start = hrtime(true);
        try {
            $removed = $this->store->clear($fingerprint);
            if ($removed) $this->diagnostics?->rateLimit('clears');
            return $removed;
        } catch (Throwable $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure instanceof RateLimitException ? $failure
                : new RateLimitException('Rate-limit backend clear failed.', 0, $failure);
        } finally {
            $this->diagnostics?->rateLimitTime((hrtime(true) - $start) / 1_000_000);
        }
    }

    /** Definitions are trusted configuration and are never resolved at registration. */
    public function define(string $name, callable|string $resolver): void
    {
        try {
            RateLimitKey::bucket($name);
        } catch (RateLimitException $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure;
        }
        if (isset($this->definitions[$name])) {
            $this->diagnostics?->rateLimit('errors');
            throw new RateLimitException('Rate-limit definition already exists.');
        }
        $this->definitions[$name] = $resolver;
    }

    /** @internal Route middleware resolves policy just before execution. */
    public function consumeNamed(string $name, Request $request): RateLimitResult
    {
        try {
            RateLimitKey::bucket($name);
            $resolver = $this->definitions[$name] ?? null;
            if ($resolver === null) throw new RateLimitException('Named rate limiter is not defined.');
            if (is_string($resolver)) {
                if ($this->container === null) throw new RateLimitException('Named limiter container is unavailable.');
                try {
                    $instance = $this->container->make($resolver);
                } catch (Throwable $failure) {
                    throw new RateLimitException('Named limiter class cannot be resolved.', 0, $failure);
                }
                if (!method_exists($instance, 'resolve')) throw new RateLimitException('Named limiter needs resolve().');
                $method = new ReflectionMethod($instance, 'resolve');
                if (!$method->isPublic() || $method->isStatic()) {
                    throw new RateLimitException('Named limiter resolve() must be public and non-static.');
                }
                $rule = $instance->resolve($request);
            } else {
                $rule = $resolver($request);
            }
            if (!$rule instanceof RateLimitRule) throw new RateLimitException('Named limiter must return RateLimitRule.');
        } catch (RateLimitException $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure;
        }
        return $this->consumeRule($name, $rule);
    }

    private function consumeRule(string $bucket, RateLimitRule $rule): RateLimitResult
    {
        try {
            $fingerprint = $this->fingerprint($bucket, $rule->keyDigest());
        } catch (RateLimitException $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure;
        }
        $now = ($this->clock)();
        $start = hrtime(true);
        $this->diagnostics?->rateLimit('checks');
        try {
            $result = $this->store->consume($fingerprint, $rule->maxAttempts(), $rule->windowSeconds(), $now);
            $this->diagnostics?->rateLimit($result->allowed() ? 'allowed' : 'denied');
            return $result;
        } catch (Throwable $failure) {
            $this->diagnostics?->rateLimit('errors');
            throw $failure instanceof RateLimitException ? $failure
                : new RateLimitException('Rate-limit backend consume failed.', 0, $failure);
        } finally {
            $this->diagnostics?->rateLimitTime((hrtime(true) - $start) / 1_000_000);
        }
    }

    private function fingerprint(string $bucket, string $keyDigest): string
    {
        RateLimitKey::bucket($bucket);
        // Length framing prevents different prefix/bucket/key partitions from
        // sharing an effective fingerprint. Only this digest reaches the store.
        return hash('sha256', pack('N', strlen($this->prefix)) . $this->prefix
            . pack('N', strlen($bucket)) . $bucket . $keyDigest);
    }
}
