<?php

declare(strict_types=1);

namespace App\Redis;

use App\Diagnostics\Diagnostics;
use Closure;
use Throwable;

/**
 * A reusable logical connection. Commands prefix one validated logical key and
 * enter a single safe error/diagnostic boundary. No socket exists until use.
 */
final class RedisConnection
{
    private ?RedisClient $client = null;
    private ?int $processId = null;

    /**
     * @param array<string,mixed> $settings Normalized configuration, including secrets.
     * @param Closure():RedisClient $factory
     */
    public function __construct(#[\SensitiveParameter] private array $settings,
        private Closure $factory, private ?Diagnostics $diagnostics = null)
    {
    }

    public function get(string $key): ?string
    {
        $reply = $this->run('read', ['GET', $this->key($key)]);
        if ($reply === null || $reply === false) return null;
        if (!is_string($reply)) throw new RedisException('Invalid Redis GET response.');
        return $reply;
    }

    /** SET NX and EX are sent in one atomic Redis command when requested. */
    public function set(string $key, #[\SensitiveParameter] string $value,
        ?int $ttl = null, bool $onlyIfMissing = false): bool
    {
        if ($ttl !== null && $ttl < 1) throw new RedisConfigurationException('Redis TTL must be positive.');
        $arguments = ['SET', $this->key($key), $value];
        if ($ttl !== null) array_push($arguments, 'EX', (string) $ttl);
        if ($onlyIfMissing) $arguments[] = 'NX';
        $reply = $this->run('write', $arguments);
        if ($onlyIfMissing && ($reply === null || $reply === false)) return false;
        if ($reply !== 'OK' && $reply !== true) throw new RedisException('Invalid Redis SET response.');
        return true;
    }

    public function delete(string $key): bool
    {
        return $this->integerReply('write', ['DEL', $this->key($key)]) > 0;
    }

    public function exists(string $key): bool
    {
        return $this->integerReply('read', ['EXISTS', $this->key($key)]) > 0;
    }

    public function expire(string $key, int $seconds): bool
    {
        if ($seconds < 1) throw new RedisConfigurationException('Redis expiry must be positive.');
        return $this->integerReply('write', ['EXPIRE', $this->key($key), (string) $seconds]) > 0;
    }

    /** Redis returns -2 for a missing key and -1 for a key with no expiry. */
    public function ttl(string $key): int
    {
        return $this->integerReply('read', ['TTL', $this->key($key)]);
    }

    /** The server, not PHP, performs the atomic integer update. */
    public function increment(string $key): int
    {
        return $this->integerReply('write', ['INCR', $this->key($key)]);
    }

    /** The server, not PHP, performs the atomic integer update. */
    public function decrement(string $key): int
    {
        return $this->integerReply('write', ['DECR', $this->key($key)]);
    }

    /**
     * Execute one server-side operation over keys owned by this connection.
     * Scripts are framework-authored constants, never request-supplied code.
     * The server's single-command execution is the atomicity boundary for
     * distributed rate-limit decisions and cache take operations.
     *
     * @param list<string> $keys Logical keys, prefixed exactly once here.
     * @param list<string> $arguments
     */
    public function script(string $source, array $keys, #[\SensitiveParameter] array $arguments = []): mixed
    {
        $prefixed = array_map(fn (string $key): string => $this->key($key), $keys);
        return $this->run('write', array_merge(['EVAL', $source, (string) count($prefixed)],
            $prefixed, $arguments));
    }

    /**
     * Incrementally enumerate only a validated subsystem prefix. The caller
     * receives logical keys and may remove them through delete(); no global
     * flush or blocking KEYS command is involved.
     *
     * @return \Generator<int,string>
     */
    public function scanPrefix(string $logicalPrefix): \Generator
    {
        if ($logicalPrefix === '' || strlen($logicalPrefix) > 512
            || preg_match('/[^A-Za-z0-9:._-]/', $logicalPrefix)) {
            throw new RedisConfigurationException('Invalid Redis scan prefix.');
        }
        $pattern = $this->settings['prefix'] . $logicalPrefix . '*';
        $cursor = '0';
        do {
            $reply = $this->run('read', ['SCAN', $cursor, 'MATCH', $pattern, 'COUNT', '100']);
            if (!is_array($reply) || count($reply) !== 2 || !is_array($reply[1])
                || (!is_string($reply[0]) && !is_int($reply[0]))) {
                throw new RedisException('Invalid Redis SCAN response.');
            }
            $cursor = (string) $reply[0];
            foreach ($reply[1] as $key) {
                if (!is_string($key) || !str_starts_with($key, $this->settings['prefix'] . $logicalPrefix)) {
                    throw new RedisException('Redis SCAN returned a key outside the requested namespace.');
                }
                yield substr($key, strlen($this->settings['prefix']));
            }
        } while ($cursor !== '0');
    }

    /** Explicit health probe; obtaining this wrapper does not call PING. */
    public function ping(): bool
    {
        $reply = $this->run('read', ['PING']);
        if ($reply !== 'PONG' && $reply !== true) throw new RedisException('Invalid Redis PING response.');
        return true;
    }

    /** Never expose endpoint, prefix, or credentials through ordinary dumps. */
    public function __debugInfo(): array
    {
        return ['connected' => $this->client !== null];
    }

    public function __destruct()
    {
        try {
            $this->client?->close();
        } catch (Throwable) {
            // Shutdown must not replace an application's existing exception.
        }
    }

    private function key(string $key): string
    {
        if ($key === '' || strlen($key) > 1024 || preg_match('/[\x00-\x1f\x7f]/', $key)) {
            throw new RedisConfigurationException('Invalid Redis key.');
        }
        return $this->settings['prefix'] . $key;
    }

    /** @param non-empty-list<string> $arguments */
    private function integerReply(string $kind, array $arguments): int
    {
        $reply = $this->run($kind, $arguments);
        if (!is_int($reply) && !(is_string($reply) && preg_match('/^-?[0-9]+$/D', $reply))) {
            throw new RedisException('Invalid Redis integer response.');
        }
        return (int) $reply;
    }

    /**
     * No command text, arguments, key, value, endpoint, or vendor exception is
     * retained in diagnostics or public exceptions. A child process opens its
     * own socket instead of reusing a connection inherited across a fork.
     *
     * @param non-empty-list<string> $arguments
     */
    private function run(string $kind, #[\SensitiveParameter] array $arguments): mixed
    {
        $started = hrtime(true);
        $failed = false;
        try {
            $pid = (int) getmypid();
            if ($this->processId !== null && $pid !== $this->processId) {
                $this->client = null;
            }
            if ($this->client === null) {
                $this->client = ($this->factory)();
                $this->processId = $pid;
            }
            $reply = $this->client->execute($arguments);
            $this->validateReply($arguments, $reply);
            return $reply;
        } catch (Throwable $exception) {
            $failed = true;
            try { $this->client?->close(); } catch (Throwable) {}
            $this->client = null;
            if ($exception instanceof RedisException) throw $exception;
            throw new RedisException('Redis operation failed.');
        } finally {
            $this->diagnostics?->redis($kind, (hrtime(true) - $started) / 1_000_000, $failed);
        }
    }

    /** Reject unexpected vendor replies inside the measured failure boundary. */
    private function validateReply(array $arguments, mixed $reply): void
    {
        $command = $arguments[0];
        if ($command === 'GET' && ($reply === null || $reply === false || is_string($reply))) return;
        if ($command === 'SET' && ($reply === 'OK' || $reply === true
            || (in_array('NX', $arguments, true) && ($reply === null || $reply === false)))) return;
        if ($command === 'PING' && ($reply === 'PONG' || $reply === true)) return;
        if ($command === 'EVAL' || ($command === 'SCAN' && is_array($reply))) return;
        if (in_array($command, ['DEL', 'EXISTS', 'EXPIRE', 'TTL', 'INCR', 'DECR'], true)
            && (is_int($reply) || (is_string($reply) && preg_match('/^-?[0-9]+$/D', $reply)))) return;
        throw new RedisException('Invalid Redis command response.');
    }
}
