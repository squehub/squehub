<?php

declare(strict_types=1);

namespace App\Idempotency\Stores;

use App\Idempotency\ClaimResult;
use App\Idempotency\IdempotencyException;
use App\Idempotency\IdempotencyStore;
use App\Idempotency\Record;
use App\Idempotency\ResponseSnapshot;
use App\Redis\RedisConnection;
use JsonException;

/** Redis Lua owns each state transition and its key TTL in one server command. */
final class RedisIdempotencyStore implements IdempotencyStore
{
    private const CLAIM = <<<'LUA'
local old = redis.call('GET', KEYS[1])
if not old then
  redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
  return {'claimed'}
end
local record = cjson.decode(old)
local now = tonumber(ARGV[3])
if record.expires_at <= now or
  (record.state == 'processing' and record.lease_until <= now and record.fingerprint == ARGV[4]) then
  redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
  return {'claimed'}
end
return {'existing', old}
LUA;

    private const COMPLETE = <<<'LUA'
local old = redis.call('GET', KEYS[1])
if not old then return 0 end
local record = cjson.decode(old)
local now = tonumber(ARGV[2])
if record.state ~= 'processing' or record.owner ~= ARGV[1] or
  record.lease_until <= now or record.expires_at <= now then return 0 end
local ttl = redis.call('TTL', KEYS[1])
if ttl < 1 then return 0 end
record.state = 'complete'
record.owner = cjson.null
record.lease_until = 0
record.snapshot = cjson.decode(ARGV[3])
redis.call('SET', KEYS[1], cjson.encode(record), 'EX', ttl)
return 1
LUA;

    private const ABANDON = <<<'LUA'
local old = redis.call('GET', KEYS[1])
if not old then return 0 end
local record = cjson.decode(old)
if record.state ~= 'processing' or record.owner ~= ARGV[1] then return 0 end
return redis.call('DEL', KEYS[1])
LUA;

    public function __construct(private RedisConnection $connection, private string $namespace)
    {
    }

    public function claim(string $scope, string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): ClaimResult
    {
        Record::scope($scope);
        $fresh = Record::fresh($fingerprint, $owner, $now, $leaseSeconds, $retentionSeconds);
        $reply = $this->connection->script(self::CLAIM, [$this->key($scope)],
            [Record::encode($fresh), (string) $retentionSeconds, (string) $now, $fingerprint]);
        if (!is_array($reply) || !isset($reply[0]) || !is_string($reply[0])) {
            throw new IdempotencyException('Invalid idempotency Redis claim response.');
        }
        if ($reply[0] === 'claimed' && count($reply) === 1) return new ClaimResult('claimed');
        if ($reply[0] === 'existing' && count($reply) === 2 && is_string($reply[1])) {
            return ClaimResult::fromRecord(Record::decode($reply[1]), $fingerprint);
        }
        throw new IdempotencyException('Invalid idempotency Redis claim response.');
    }

    public function complete(string $scope, string $owner, int $now, ?ResponseSnapshot $snapshot): bool
    {
        Record::scope($scope);
        Record::identity(str_repeat('0', 64), $owner);
        try {
            $json = json_encode($snapshot?->toArray(), JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new IdempotencyException('Idempotency snapshot cannot be encoded.', 0, $failure);
        }
        $reply = $this->connection->script(self::COMPLETE, [$this->key($scope)],
            [$owner, (string) $now, $json]);
        return self::changed($reply);
    }

    public function abandon(string $scope, string $owner): bool
    {
        Record::scope($scope);
        Record::identity(str_repeat('0', 64), $owner);
        return self::changed($this->connection->script(self::ABANDON, [$this->key($scope)], [$owner]));
    }

    public function prune(int $now, int $limit = 100): int
    {
        // Every Redis record carries a server-enforced expiry.
        return 0;
    }

    private function key(string $scope): string
    {
        return 'idempotency:' . hash('sha256', $this->namespace) . ':' . $scope;
    }

    private static function changed(mixed $reply): bool
    {
        if ($reply === 1 || $reply === '1') return true;
        if ($reply === 0 || $reply === '0') return false;
        throw new IdempotencyException('Invalid idempotency Redis transition response.');
    }
}
