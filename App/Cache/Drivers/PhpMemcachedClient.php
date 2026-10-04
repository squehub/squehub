<?php

declare(strict_types=1);

namespace App\Cache\Drivers;

use App\Cache\CacheException;
use Throwable;

/** Optional ext-memcached transport; construction configures but does not connect. */
final class PhpMemcachedClient implements MemcachedClient
{
    private object $client;

    public function __construct(string $host, int $port, int $timeoutMs)
    {
        if (!class_exists('Memcached') || !defined('Memcached::GET_EXTENDED')) {
            throw new CacheException('Memcached Cache requires ext-memcached 3.x.');
        }
        try {
            $class = 'Memcached';
            $client = new $class();
            if (!$client->setOption($class::OPT_CONNECT_TIMEOUT, $timeoutMs)
                || !$client->setOption($class::OPT_POLL_TIMEOUT, $timeoutMs)
                || !$client->setOption($class::OPT_SEND_TIMEOUT, $timeoutMs * 1000)
                || !$client->setOption($class::OPT_RECV_TIMEOUT, $timeoutMs * 1000)
                || !$client->addServer($host, $port)) {
                throw new CacheException('Memcached Cache configuration failed.');
            }
            $this->client = $client;
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function read(string $key): ?MemcachedRecord
    {
        try {
            $class = 'Memcached';
            $result = $this->client->get($key, null, $class::GET_EXTENDED);
            if ($result === false && $this->client->getResultCode() === $class::RES_NOTFOUND) return null;
            if (!is_array($result) || !array_key_exists('value', $result)
                || !array_key_exists('cas', $result)
                || !is_string($result['value']) && !is_int($result['value'])
                || !is_string($result['cas']) && !is_int($result['cas']) && !is_float($result['cas'])) {
                throw new CacheException('Memcached Cache read failed.');
            }
            return new MemcachedRecord($result['value'], $result['cas']);
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function set(string $key, string|int $value, int $expiration): void
    {
        try {
            if (!$this->client->set($key, $value, $expiration)) {
                throw new CacheException('Memcached Cache write failed.');
            }
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function add(string $key, string|int $value, int $expiration): bool
    {
        try {
            if ($this->client->add($key, $value, $expiration)) return true;
            $class = 'Memcached';
            if (in_array($this->client->getResultCode(), [$class::RES_NOTSTORED, $class::RES_DATA_EXISTS], true)) {
                return false;
            }
            throw new CacheException('Memcached Cache write failed.');
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function cas(string|int|float $token, string $key, string|int $value, int $expiration): bool
    {
        try {
            if ($this->client->cas($token, $key, $value, $expiration)) return true;
            $class = 'Memcached';
            if (in_array($this->client->getResultCode(), [$class::RES_DATA_EXISTS, $class::RES_NOTFOUND], true)) {
                return false;
            }
            throw new CacheException('Memcached Cache compare-and-swap failed.');
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    public function increment(string $key): ?int
    {
        try {
            $value = $this->client->increment($key, 1);
            if ($value !== false && is_int($value)) return $value;
            $class = 'Memcached';
            if ($value === false && $this->client->getResultCode() === $class::RES_NOTFOUND) return null;
            throw new CacheException('Memcached Cache namespace reset failed.');
        } catch (Throwable $failure) {
            throw $this->failure($failure);
        }
    }

    private function failure(Throwable $failure): CacheException
    {
        return $failure instanceof CacheException ? $failure
            : new CacheException('Memcached Cache operation failed.', 0, $failure);
    }
}
