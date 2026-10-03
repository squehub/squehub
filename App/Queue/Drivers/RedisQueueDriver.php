<?php

declare(strict_types=1);

namespace App\Queue\Drivers;

use App\Queue\Composition\CompositionDriver;
use App\Queue\Composition\CompositionStatus;
use App\Queue\CorrelatedQueueDriver;
use App\Queue\FailedQueueDriver;
use App\Queue\QueueCodec;
use App\Queue\QueueException;
use App\Queue\QueueJob;
use App\Queue\QueueStatus;
use App\Queue\QueueStatusDriver;
use App\Queue\RedisRestartSignal;
use App\Queue\ReservedJob;
use App\Redis\RedisConnection;
use App\Redis\RedisException;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Persistent Queue state lives in Redis hashes and sorted sets. Every change
 * involving ownership or two structures occurs in one Lua operation, so
 * cooperating workers cannot claim or settle one attempt twice. Active jobs
 * have no TTL; reservation expiry changes ownership, not job lifetime.
 */
final class RedisQueueDriver implements CompositionDriver, FailedQueueDriver, QueueStatusDriver, CorrelatedQueueDriver
{
    private const STATUS = <<<'LUA'
-- squehub:queue:status
local time = redis.call('TIME')
local now = tonumber(time[1])
-- An expired lease is reclaimable by the next reservation. Counts are a
-- snapshot of records, never proof that a worker is currently running.
local ready = redis.call('ZCOUNT', KEYS[1], '-inf', now)
    + redis.call('ZCOUNT', KEYS[2], '-inf', now)
local delayed = redis.call('ZCOUNT', KEYS[1], '(' .. now, '+inf')
local reserved = redis.call('ZCOUNT', KEYS[2], '(' .. now, '+inf')
return {ready, delayed, reserved, redis.call('ZCARD', KEYS[3])}
LUA;

    private const DISPATCH = <<<'LUA'
-- squehub:queue:dispatch
redis.replicate_commands()
local id = redis.call('INCR', KEYS[1])
if id > 9007199254740991 then return redis.error_reply('QUEUE_ID_EXHAUSTED') end
local member = string.format('%020d', id)
local time = redis.call('TIME')
local now = tonumber(time[1])
local available = now + tonumber(ARGV[3])
local record = {queue = ARGV[1], payload = ARGV[2], attempts = 0,
    token = '', available_at = available, created_at = now}
redis.call('HSET', KEYS[2], member, cjson.encode(record))
redis.call('ZADD', KEYS[3], available, member)
return id
LUA;

    private const RESERVE = <<<'LUA'
-- squehub:queue:reserve
redis.replicate_commands()
local time = redis.call('TIME')
local now = tonumber(time[1])
-- Recover a bounded batch while holding Redis's single-script atomic boundary.
-- The record stays in the hash throughout recovery and cannot be lost.
local stale = redis.call('ZRANGEBYSCORE', KEYS[2], '-inf', now, 'LIMIT', 0, 32)
for _, member in ipairs(stale) do
    local raw = redis.call('HGET', KEYS[3], member)
    if not raw then return redis.error_reply('QUEUE_MISSING_RECORD') end
    local record = cjson.decode(raw)
    redis.call('ZREM', KEYS[2], member)
    record.token = ''
    record.available_at = now
    redis.call('HSET', KEYS[3], member, cjson.encode(record))
    redis.call('ZADD', KEYS[1], now, member)
end
local due = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', now, 'LIMIT', 0, 1)
if #due == 0 then return {} end
local member = due[1]
local raw = redis.call('HGET', KEYS[3], member)
if not raw then return redis.error_reply('QUEUE_MISSING_RECORD') end
local record = cjson.decode(raw)
if record.queue ~= ARGV[1] or type(record.payload) ~= 'string'
    or type(record.attempts) ~= 'number' or record.attempts >= 2147483647 then
    return redis.error_reply('QUEUE_INVALID_RECORD')
end
redis.call('ZREM', KEYS[1], member)
record.attempts = record.attempts + 1
record.token = ARGV[2]
redis.call('HSET', KEYS[3], member, cjson.encode(record))
redis.call('ZADD', KEYS[2], now + tonumber(ARGV[3]), member)
return {member, record.payload, record.attempts, record.token,
    record.composition_id or '', record.composition_position or -1}
LUA;

    private const ACKNOWLEDGE = <<<'LUA'
-- squehub:queue:acknowledge
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[2], ARGV[1])
if not raw then return 0 end
local record = cjson.decode(raw)
if record.token ~= ARGV[2] or not redis.call('ZSCORE', KEYS[1], ARGV[1]) then return 0 end
if record.composition_id then
    if record.composition_id ~= ARGV[3]
        or record.composition_position ~= tonumber(ARGV[4]) then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    local encoded = redis.call('GET', KEYS[3])
    if not encoded then return redis.error_reply('QUEUE_MISSING_COMPOSITION') end
    local meta = cjson.decode(encoded)
    local position = tonumber(ARGV[4]) + 1
    local item = meta.items[position]
    if meta.id ~= record.composition_id or meta.queue ~= record.queue
        or not item or item.member ~= ARGV[1]
        or (item.state ~= 'queued' and item.state ~= 'running')
        or (meta.state ~= 'active' and meta.state ~= 'cancelling') then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    local nextItem = nil
    local nextId = nil
    if meta.state == 'active' and meta.kind == 'chain' and position < meta.total then
        nextItem = meta.items[position + 1]
        if not nextItem or nextItem.state ~= 'pending'
            or type(nextItem.payload) ~= 'string' then
            return redis.error_reply('QUEUE_INVALID_COMPOSITION')
        end
        local current = tonumber(redis.call('GET', KEYS[5]) or '0')
        if not current or current >= 9007199254740991 then
            return redis.error_reply('QUEUE_ID_EXHAUSTED')
        end
        nextId = current + 1
    end
    item.state = 'succeeded'
    item.payload = nil
    meta.succeeded = meta.succeeded + 1
    if nextItem then
        local time = redis.call('TIME')
        local now = tonumber(time[1])
        local member = string.format('%020d', nextId)
        local nextRecord = {queue = meta.queue, payload = nextItem.payload,
            attempts = 0, token = '', available_at = now, created_at = now,
            composition_id = meta.id, composition_position = position}
        nextItem.state = 'queued'
        nextItem.member = member
        nextItem.payload = nil
        redis.call('SET', KEYS[5], nextId)
        redis.call('HSET', KEYS[2], member, cjson.encode(nextRecord))
        redis.call('ZADD', KEYS[6], now, member)
    end
    if meta.succeeded + meta.failed + meta.cancelled == meta.total then
        if meta.state == 'cancelling' then
            meta.state = 'cancelled'
        elseif meta.failed > 0 then
            meta.state = 'completed_with_failures'
        else
            meta.state = 'completed'
        end
        local time = redis.call('TIME')
        redis.call('ZADD', KEYS[4], tonumber(time[1]), meta.id)
    end
    redis.call('SET', KEYS[3], cjson.encode(meta))
    if meta.state ~= 'active' and meta.state ~= 'cancelling' then
        redis.call('EXPIRE', KEYS[3], meta.retention_seconds)
    end
end
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
return 1
LUA;

    private const COMPOSITION_SKIP = <<<'LUA'
-- squehub:queue:composition-skip
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[2], ARGV[1])
if not raw then return 0 end
local record = cjson.decode(raw)
if record.token ~= ARGV[2] or not redis.call('ZSCORE', KEYS[1], ARGV[1]) then return 0 end
if record.composition_id ~= ARGV[3]
    or record.composition_position ~= tonumber(ARGV[4]) then
    return redis.error_reply('QUEUE_INVALID_COMPOSITION')
end
local encoded = redis.call('GET', KEYS[3])
if not encoded then return redis.error_reply('QUEUE_MISSING_COMPOSITION') end
local meta = cjson.decode(encoded)
local item = meta.items[tonumber(ARGV[4]) + 1]
if meta.id ~= record.composition_id or meta.queue ~= record.queue
    or meta.state ~= 'cancelling' or not item or item.member ~= ARGV[1]
    or (item.state ~= 'queued' and item.state ~= 'running') then
    return redis.error_reply('QUEUE_INVALID_COMPOSITION')
end
item.state = 'cancelled'
item.payload = nil
meta.cancelled = meta.cancelled + 1
if meta.succeeded + meta.failed + meta.cancelled == meta.total then
    meta.state = 'cancelled'
    local time = redis.call('TIME')
    redis.call('ZADD', KEYS[4], tonumber(time[1]), meta.id)
end
redis.call('SET', KEYS[3], cjson.encode(meta))
if meta.state == 'cancelled' then redis.call('EXPIRE', KEYS[3], meta.retention_seconds) end
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
return 1
LUA;

    private const RELEASE = <<<'LUA'
-- squehub:queue:release
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[3], ARGV[1])
if not raw then return 0 end
local record = cjson.decode(raw)
if record.token ~= ARGV[2] or not redis.call('ZSCORE', KEYS[2], ARGV[1]) then return 0 end
if record.composition_id then
    if record.composition_id ~= ARGV[4]
        or record.composition_position ~= tonumber(ARGV[5]) then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    local encoded = redis.call('GET', KEYS[4])
    if not encoded then return redis.error_reply('QUEUE_MISSING_COMPOSITION') end
    local meta = cjson.decode(encoded)
    local item = meta.items[tonumber(ARGV[5]) + 1]
    if meta.id ~= record.composition_id or meta.queue ~= record.queue
        or not item or item.member ~= ARGV[1]
        or (item.state ~= 'queued' and item.state ~= 'running') then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    -- Requeue even after cancellation. The next worker fences the reservation
    -- through shouldRunComposition() and skipComposition(), so release retains
    -- its ordinary retry meaning and cancellation remains cooperative.
    if meta.state ~= 'active' and meta.state ~= 'cancelling' then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
end
local time = redis.call('TIME')
local available = tonumber(time[1]) + tonumber(ARGV[3])
record.token = ''
record.available_at = available
redis.call('ZREM', KEYS[2], ARGV[1])
redis.call('HSET', KEYS[3], ARGV[1], cjson.encode(record))
redis.call('ZADD', KEYS[1], available, ARGV[1])
return 1
LUA;

    private const FAIL = <<<'LUA'
-- squehub:queue:fail
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[2], ARGV[1])
if not raw then return 0 end
local record = cjson.decode(raw)
if record.token ~= ARGV[2] or not redis.call('ZSCORE', KEYS[1], ARGV[1]) then return 0 end
local current = tonumber(redis.call('GET', KEYS[3]) or '0')
if not current or current >= 9007199254740991 then
    return redis.error_reply('QUEUE_ID_EXHAUSTED')
end
local id = current + 1
local meta = nil
if record.composition_id then
    if record.composition_id ~= ARGV[6]
        or record.composition_position ~= tonumber(ARGV[7]) then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    local encoded = redis.call('GET', KEYS[6])
    if not encoded then return redis.error_reply('QUEUE_MISSING_COMPOSITION') end
    meta = cjson.decode(encoded)
    local item = meta.items[tonumber(ARGV[7]) + 1]
    if meta.id ~= record.composition_id or meta.queue ~= record.queue
        or not item or item.member ~= ARGV[1]
        or (item.state ~= 'queued' and item.state ~= 'running')
        or (meta.state ~= 'active' and meta.state ~= 'cancelling') then
        return redis.error_reply('QUEUE_INVALID_COMPOSITION')
    end
    item.state = 'failed'
    item.payload = nil
    meta.failed = meta.failed + 1
    if meta.kind == 'chain' and meta.state == 'active' then
        for nextPosition = tonumber(ARGV[7]) + 2, meta.total do
            local future = meta.items[nextPosition]
            if future.state ~= 'pending' then
                return redis.error_reply('QUEUE_INVALID_COMPOSITION')
            end
            future.state = 'cancelled'
            future.payload = nil
            meta.cancelled = meta.cancelled + 1
        end
        meta.state = 'failed'
    elseif meta.succeeded + meta.failed + meta.cancelled == meta.total then
        meta.state = meta.state == 'cancelling' and 'cancelled' or 'completed_with_failures'
    end
end
local member = string.format('%020d', id)
local time = redis.call('TIME')
local now = tonumber(time[1])
local failure = {queue = record.queue, payload = record.payload,
    job_class = ARGV[3], error_type = ARGV[4], error_message = ARGV[5],
    attempts = record.attempts, failed_at = now}
redis.call('HSET', KEYS[4], member, cjson.encode(failure))
redis.call('ZADD', KEYS[5], now, member)
redis.call('SET', KEYS[3], id)
if meta then
    redis.call('SET', KEYS[6], cjson.encode(meta))
    if meta.state == 'failed' or meta.state == 'completed_with_failures'
        or meta.state == 'cancelled' then
        redis.call('ZADD', KEYS[7], now, meta.id)
        redis.call('EXPIRE', KEYS[6], meta.retention_seconds)
    end
end
redis.call('ZREM', KEYS[1], ARGV[1])
redis.call('HDEL', KEYS[2], ARGV[1])
return 1
LUA;

    private const FAILED = <<<'LUA'
-- squehub:queue:failed
local members = redis.call('ZREVRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1)
if #members == 0 then return '[]' end
local rows = {}
for _, member in ipairs(members) do
    local raw = redis.call('HGET', KEYS[2], member)
    if not raw then return redis.error_reply('QUEUE_MISSING_FAILED_RECORD') end
    local row = cjson.decode(raw)
    rows[#rows + 1] = {id = tonumber(member), queue = row.queue,
        job_class = row.job_class, error_type = row.error_type,
        attempts = row.attempts, failed_at = row.failed_at}
end
return cjson.encode(rows)
LUA;

    private const FAILED_RECORD = <<<'LUA'
-- squehub:queue:failed-record
return redis.call('HGET', KEYS[1], ARGV[1])
LUA;

    private const RETRY = <<<'LUA'
-- squehub:queue:retry
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[1], ARGV[1])
if not raw then return 0 end
local row = cjson.decode(raw)
if type(row.payload) ~= 'string' or row.queue ~= ARGV[2] then
    return redis.error_reply('QUEUE_INVALID_FAILED_RECORD')
end
local id = redis.call('INCR', KEYS[3])
if id > 9007199254740991 then return redis.error_reply('QUEUE_ID_EXHAUSTED') end
local member = string.format('%020d', id)
local time = redis.call('TIME')
local now = tonumber(time[1])
local record = {queue = row.queue, payload = row.payload, attempts = 0,
    token = '', available_at = now, created_at = now}
redis.call('HSET', KEYS[4], member, cjson.encode(record))
redis.call('ZADD', KEYS[5], now, member)
redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
return 1
LUA;

    private const FORGET = <<<'LUA'
-- squehub:queue:forget
redis.replicate_commands()
local removed = redis.call('HDEL', KEYS[1], ARGV[1])
redis.call('ZREM', KEYS[2], ARGV[1])
return removed
LUA;

    private const PRUNE = <<<'LUA'
-- squehub:queue:prune
redis.replicate_commands()
local time = redis.call('TIME')
local cutoff = tonumber(time[1]) - tonumber(ARGV[1]) * 3600
local members = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', '(' .. cutoff, 'LIMIT', 0, 1000)
for _, member in ipairs(members) do
    redis.call('HDEL', KEYS[2], member)
    redis.call('ZREM', KEYS[1], member)
end
return #members
LUA;

    private const COMPOSITION_CREATE = <<<'LUA'
-- squehub:queue:composition-create
redis.replicate_commands()
if redis.call('EXISTS', KEYS[1]) ~= 0 then return redis.error_reply('QUEUE_COMPOSITION_EXISTS') end
local total = tonumber(ARGV[5])
local scheduled = ARGV[2] == 'batch' and total or 1
local current = tonumber(redis.call('GET', KEYS[2]) or '0')
if not current or current + scheduled > 9007199254740991 then
    return redis.error_reply('QUEUE_ID_EXHAUSTED')
end
local time = redis.call('TIME')
local now = tonumber(time[1])
local meta = {version = 1, id = ARGV[1], kind = ARGV[2], queue = ARGV[3],
    state = 'active', total = total, succeeded = 0, failed = 0, cancelled = 0,
    retention_seconds = tonumber(ARGV[4]), items = {}}
for position = 1, total do
    -- Metadata retains only dormant chain payloads. Runnable payloads already
    -- live in the normal Queue record and need no second persisted copy.
    local item = {state = 'pending', member = ''}
    if position <= scheduled then
        local member = string.format('%020d', current + position)
        item.state = 'queued'
        item.member = member
        local record = {queue = ARGV[3], payload = ARGV[5 + position], attempts = 0,
            token = '', available_at = now, created_at = now,
            composition_id = ARGV[1], composition_position = position - 1}
        redis.call('HSET', KEYS[3], member, cjson.encode(record))
        redis.call('ZADD', KEYS[4], now, member)
    else
        item.payload = ARGV[5 + position]
    end
    meta.items[position] = item
end
redis.call('SET', KEYS[2], current + scheduled)
redis.call('SET', KEYS[1], cjson.encode(meta))
return 1
LUA;

    private const COMPOSITION_SHOULD_RUN = <<<'LUA'
-- squehub:queue:composition-should-run
redis.replicate_commands()
local raw = redis.call('HGET', KEYS[2], ARGV[1])
if not raw then return redis.error_reply('QUEUE_MISSING_RECORD') end
local record = cjson.decode(raw)
if record.token ~= ARGV[2] or not redis.call('ZSCORE', KEYS[3], ARGV[1])
    or record.composition_id ~= ARGV[4]
    or record.composition_position ~= tonumber(ARGV[3]) then
    return redis.error_reply('QUEUE_RESERVATION_LOST')
end
local encoded = redis.call('GET', KEYS[1])
if not encoded then return redis.error_reply('QUEUE_MISSING_COMPOSITION') end
local meta = cjson.decode(encoded)
local item = meta.items[tonumber(ARGV[3]) + 1]
if not item or item.member ~= ARGV[1] then
    return redis.error_reply('QUEUE_INVALID_COMPOSITION')
end
if meta.state ~= 'active' then return 0 end
if item.state ~= 'queued' and item.state ~= 'running' then
    return redis.error_reply('QUEUE_INVALID_COMPOSITION')
end
return 1
LUA;

    private const COMPOSITION_CANCEL = <<<'LUA'
-- squehub:queue:composition-cancel
redis.replicate_commands()
local encoded = redis.call('GET', KEYS[1])
if not encoded then return 0 end
local meta = cjson.decode(encoded)
if meta.state ~= 'active' then return 0 end
-- Validate membership before the first write. A missing live record is an
-- infrastructure error, not permission to invent a terminal outcome.
for _, item in ipairs(meta.items) do
    if item.state == 'queued' or item.state == 'running' then
        if not redis.call('HGET', KEYS[2], item.member)
            or (not redis.call('ZSCORE', KEYS[3], item.member)
                and not redis.call('ZSCORE', KEYS[4], item.member)) then
            return redis.error_reply('QUEUE_INVALID_COMPOSITION')
        end
    end
end
meta.state = 'cancelling'
for _, item in ipairs(meta.items) do
    if item.state == 'pending' then
        item.state = 'cancelled'
        item.payload = nil
        meta.cancelled = meta.cancelled + 1
    elseif item.state == 'queued' or item.state == 'running' then
        if redis.call('ZSCORE', KEYS[3], item.member) then
            redis.call('ZREM', KEYS[3], item.member)
            redis.call('HDEL', KEYS[2], item.member)
            item.state = 'cancelled'
            item.payload = nil
            meta.cancelled = meta.cancelled + 1
        end
    end
end
if meta.succeeded + meta.failed + meta.cancelled == meta.total then
    meta.state = 'cancelled'
    local time = redis.call('TIME')
    redis.call('ZADD', KEYS[5], tonumber(time[1]), meta.id)
end
redis.call('SET', KEYS[1], cjson.encode(meta))
if meta.state == 'cancelled' then redis.call('EXPIRE', KEYS[1], meta.retention_seconds) end
return 1
LUA;

    private const COMPOSITION_PRUNE = <<<'LUA'
-- squehub:queue:composition-prune
redis.replicate_commands()
local time = redis.call('TIME')
local cutoff = tonumber(time[1]) - tonumber(ARGV[1]) * 3600
local ids = redis.call('ZRANGEBYSCORE', KEYS[1], '-inf', '(' .. cutoff, 'LIMIT', 0, 1000)
for _, id in ipairs(ids) do
    redis.call('DEL', KEYS[2] .. id)
    redis.call('ZREM', KEYS[1], id)
end
return #ids
LUA;

    private string $prefix;

    public function __construct(private RedisConnection $redis, string $namespace,
        private int $retryAfter = 60)
    {
        if ($namespace === '' || strlen($namespace) > 128
            || preg_match('/[^A-Za-z0-9._-]/', $namespace)) {
            throw new QueueException('Redis Queue namespace is invalid.');
        }
        if ($retryAfter < 1) throw new QueueException('Queue reservation timeout must be positive.');
        // Hashing avoids publishing a checkout path used as the default namespace.
        $this->prefix = 'queue:' . substr(hash('sha256', $namespace), 0, 32) . ':';
    }

    public function restartSignal(): RedisRestartSignal
    {
        return new RedisRestartSignal($this->redis, $this->prefix . 'restart');
    }

    public function dispatch(QueueJob $job, string $queue, int $delay): void
    {
        $this->dispatchCorrelated($job, $queue, $delay, null);
    }

    public function dispatchCorrelated(QueueJob $job, string $queue, int $delay,
        ?string $correlationId): void
    {
        $queue = \App\Queue\QueueManager::name($queue);
        if ($delay < 0 || $delay > 31536000) throw new QueueException('Queue delay is invalid.');
        $payload = QueueCodec::encode($job, $correlationId);
        $this->execute(self::DISPATCH, [$this->key('next'), $this->key('jobs'), $this->queueKey($queue, 'due')],
            [$queue, $payload, (string) $delay]);
    }

    /**
     * The metadata and initial runnable jobs are published in one Redis script.
     * Active metadata has no TTL; only terminal compositions expire.
     *
     * @param array<int,string> $encodedJobs QueueCodec envelopes
     */
    public function createComposition(string $id, string $kind, array $encodedJobs,
        string $queue, int $retentionHours): void
    {
        $this->requireCompositionId($id);
        if (!in_array($kind, ['chain', 'batch'], true)
            || !array_is_list($encodedJobs) || count($encodedJobs) < 1
            || count($encodedJobs) > 100 || $retentionHours < 1 || $retentionHours > 87600) {
            throw new QueueException('Queue composition configuration is invalid.');
        }
        $bytes = 0;
        foreach ($encodedJobs as $encoded) {
            if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 60000) {
                throw new QueueException('Queue composition payload is invalid.');
            }
            $bytes += strlen($encoded);
        }
        if ($bytes > 1048576) throw new QueueException('Queue composition payload is too large.');
        $queue = \App\Queue\QueueManager::name($queue);
        $this->settle(self::COMPOSITION_CREATE, [$this->compositionKey($id),
            $this->key('next'), $this->key('jobs'), $this->queueKey($queue, 'due')],
            array_merge([$id, $kind, $queue, (string) ($retentionHours * 3600),
                (string) count($encodedJobs)], $encodedJobs));
    }

    /** Status reads return counts only; retained job payloads never escape. */
    public function compositionStatus(string $id): ?CompositionStatus
    {
        $this->requireCompositionId($id);
        try { $json = $this->redis->get($this->compositionKey($id)); }
        catch (RedisException $exception) {
            throw new QueueException('Redis Queue composition lookup failed.', 0, $exception);
        }
        if ($json === null) return null;
        try { $meta = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) {
            throw new QueueException('Redis Queue composition status is invalid.', 0, $exception);
        }
        if (!is_array($meta) || ($meta['version'] ?? null) !== 1
            || ($meta['id'] ?? null) !== $id || !is_string($meta['kind'] ?? null)
            || !is_string($meta['state'] ?? null) || !is_int($meta['total'] ?? null)
            || !is_int($meta['succeeded'] ?? null) || !is_int($meta['failed'] ?? null)
            || !is_int($meta['cancelled'] ?? null)) {
            throw new QueueException('Redis Queue composition status is invalid.');
        }
        return new CompositionStatus($id, $meta['kind'], $meta['state'],
            $meta['total'], $meta['succeeded'], $meta['failed'], $meta['cancelled']);
    }

    /** Cancellation removes unreserved work; leased jobs settle through Worker. */
    public function cancelComposition(string $id): bool
    {
        $status = $this->compositionStatus($id);
        if ($status === null || $status->finished()) return false;
        // The Queue name is held in private metadata, not in the public status.
        $queue = $this->compositionQueue($id);
        return $this->integer(self::COMPOSITION_CANCEL, [$this->compositionKey($id),
            $this->key('jobs'), $this->queueKey($queue, 'due'),
            $this->queueKey($queue, 'reserved'), $this->key('composition:terminal')], []) === 1;
    }

    /** Terminal metadata expires automatically; this permits earlier pruning. */
    public function pruneCompositions(int $hours): int
    {
        if ($hours < 1 || $hours > 87600) {
            throw new QueueException('Queue composition retention is invalid.');
        }
        $total = 0;
        do {
            $count = $this->integer(self::COMPOSITION_PRUNE,
                [$this->key('composition:terminal'), $this->key('composition:')],
                [(string) $hours]);
            $total += $count;
        } while ($count === 1000);
        return $total;
    }

    /** Reject stale or cancelled reservations before application code executes. */
    public function shouldRunComposition(ReservedJob $job): bool
    {
        if ($job->compositionId === null) return true;
        $this->requireCompositionId($job->compositionId);
        if ($job->compositionPosition === null || $job->compositionPosition < 0) {
            throw new QueueException('Queue composition reservation is invalid.');
        }
        return $this->integer(self::COMPOSITION_SHOULD_RUN,
            [$this->compositionKey($job->compositionId), $this->key('jobs'),
                $this->queueKey($job->queue, 'reserved')],
            [$this->member($job->id), $job->token,
                (string) $job->compositionPosition, $job->compositionId]) === 1;
    }

    public function status(string $queue): QueueStatus
    {
        $queue = \App\Queue\QueueManager::name($queue);
        $counts = $this->execute(self::STATUS, [$this->queueKey($queue, 'due'),
            $this->queueKey($queue, 'reserved'), $this->key('failed:index')], []);
        if (!is_array($counts) || count($counts) !== 4) {
            throw new QueueException('Redis Queue status response is invalid.');
        }
        foreach ($counts as $count) {
            if (!is_int($count) && !(is_string($count) && ctype_digit($count))) {
                throw new QueueException('Redis Queue status response is invalid.');
            }
        }
        return new QueueStatus((int) $counts[0], (int) $counts[1],
            (int) $counts[2], (int) $counts[3]);
    }

    public function reserve(string $queue): ?ReservedJob
    {
        $queue = \App\Queue\QueueManager::name($queue);
        $token = bin2hex(random_bytes(16));
        $result = $this->execute(self::RESERVE, [$this->queueKey($queue, 'due'),
            $this->queueKey($queue, 'reserved'), $this->key('jobs')],
            [$queue, $token, (string) $this->retryAfter]);
        if ($result === []) return null;
        if (!is_array($result) || count($result) !== 6 || !is_string($result[0])
            || !is_string($result[1]) || !is_numeric($result[2]) || $result[3] !== $token
            || !is_string($result[4]) || !is_numeric($result[5])
            || ($result[4] !== '' && (!self::validCompositionId($result[4])
                || (int) $result[5] < 0))) {
            throw new QueueException('Redis Queue reservation response is invalid.');
        }
        return new ReservedJob($this->id($result[0]), $queue, $result[1], (int) $result[2],
            $token, $result[4] === '' ? null : $result[4],
            $result[4] === '' ? null : (int) $result[5]);
    }

    public function acknowledge(ReservedJob $job): void
    {
        $composition = $this->reservationComposition($job);
        $this->settle(self::ACKNOWLEDGE, [$this->queueKey($job->queue, 'reserved'),
            $this->key('jobs'), $this->compositionKey($composition ?? str_repeat('0', 32)),
            $this->key('composition:terminal'), $this->key('next'),
            $this->queueKey($job->queue, 'due')],
            [$this->member($job->id), $job->token, $composition ?? '',
                (string) ($job->compositionPosition ?? -1)]);
    }

    /** A cancelled reservation is fenced and removed without running its job. */
    public function skipComposition(ReservedJob $job): void
    {
        $composition = $this->reservationComposition($job);
        if ($composition === null) throw new QueueException('Queue composition reservation is invalid.');
        $this->settle(self::COMPOSITION_SKIP,
            [$this->queueKey($job->queue, 'reserved'), $this->key('jobs'),
                $this->compositionKey($composition), $this->key('composition:terminal')],
            [$this->member($job->id), $job->token, $composition,
                (string) $job->compositionPosition]);
    }

    public function release(ReservedJob $job, int $backoff): void
    {
        if ($backoff < 0) throw new QueueException('Queue backoff cannot be negative.');
        $composition = $this->reservationComposition($job);
        $this->settle(self::RELEASE, [$this->queueKey($job->queue, 'due'),
            $this->queueKey($job->queue, 'reserved'), $this->key('jobs'),
            $this->compositionKey($composition ?? str_repeat('0', 32)),
            $this->key('composition:terminal')],
            [$this->member($job->id), $job->token, (string) $backoff,
                $composition ?? '', (string) ($job->compositionPosition ?? -1)]);
    }

    public function fail(ReservedJob $job, string $jobClass, string $type, string $reason): void
    {
        if ($jobClass !== 'unknown' && !QueueCodec::validClass($jobClass)) {
            throw new QueueException('Failed Queue job class is invalid.');
        }
        // Worker-supplied metadata is fixed/sanitized; never persist an arbitrary
        // thrown message that could contain Mail or Notification payload values.
        $composition = $this->reservationComposition($job);
        $this->settle(self::FAIL, [$this->queueKey($job->queue, 'reserved'), $this->key('jobs'),
            $this->key('failed:next'), $this->key('failed:records'), $this->key('failed:index'),
            $this->compositionKey($composition ?? str_repeat('0', 32)),
            $this->key('composition:terminal')],
            [$this->member($job->id), $job->token, $jobClass,
                substr($type, 0, 255), substr($reason, 0, 255),
                $composition ?? '', (string) ($job->compositionPosition ?? -1)]);
    }

    public function failed(int $limit = 100): array
    {
        if ($limit < 1 || $limit > 1000) throw new QueueException('Failed-job list limit is invalid.');
        $json = $this->execute(self::FAILED, [$this->key('failed:index'), $this->key('failed:records')],
            [(string) $limit]);
        if (!is_string($json)) throw new QueueException('Redis Queue failed-job response is invalid.');
        try { $rows = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new QueueException('Redis Queue failed-job response is invalid.'); }
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new QueueException('Redis Queue failed-job response is invalid.');
        }
        return array_map(static function (mixed $row): array {
            if (!is_array($row) || !is_int($row['id'] ?? null)
                || !is_string($row['queue'] ?? null)
                || !is_string($row['job_class'] ?? null)
                || !is_string($row['error_type'] ?? null)
                || !is_int($row['attempts'] ?? null)
                || !is_int($row['failed_at'] ?? null)) {
                throw new QueueException('Redis Queue failed-job record is invalid.');
            }
            return ['id' => $row['id'], 'queue' => $row['queue'],
                'job_class' => $row['job_class'], 'error_type' => $row['error_type'],
                'attempts' => $row['attempts'],
                'failed_at' => (new DateTimeImmutable('@' . $row['failed_at']))
                    ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
        }, $rows);
    }

    public function retry(int $id): bool
    {
        $member = $this->member($id);
        $json = $this->execute(self::FAILED_RECORD, [$this->key('failed:records')], [$member]);
        if ($json === false || $json === null) return false;
        if (!is_string($json)) throw new QueueException('Failed Queue record is invalid.');
        try { $row = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new QueueException('Failed Queue record is invalid.'); }
        if (!is_array($row) || !is_string($row['queue'] ?? null)
            || !is_string($row['payload'] ?? null) || $row['payload'] === '') {
            throw new QueueException('Failed Queue record is invalid.');
        }
        $due = $this->queueKey($row['queue'], 'due');
        QueueCodec::decode($row['payload']);
        // Enqueue and removal are one script. A failed enqueue leaves the
        // failed record available for another operational retry.
        return $this->integer(self::RETRY, [$this->key('failed:records'), $this->key('failed:index'),
            $this->key('next'), $this->key('jobs'), $due], [$member, $row['queue']]) === 1;
    }

    public function forget(int $id): bool
    {
        return $this->integer(self::FORGET, [$this->key('failed:records'),
            $this->key('failed:index')], [$this->member($id)]) === 1;
    }

    public function prune(int $hours = 168): int
    {
        if ($hours < 1 || $hours > 87600) throw new QueueException('Failed-job retention is invalid.');
        $total = 0;
        do {
            $count = $this->integer(self::PRUNE, [$this->key('failed:index'),
                $this->key('failed:records')], [(string) $hours]);
            $total += $count;
        } while ($count === 1000);
        return $total;
    }

    private function settle(string $script, array $keys, array $arguments): void
    {
        if ($this->integer($script, $keys, $arguments) !== 1) {
            throw new QueueException('Queue reservation is no longer owned.');
        }
    }

    private function integer(string $script, array $keys, array $arguments): int
    {
        $result = $this->execute($script, $keys, $arguments);
        if (!is_int($result) && !(is_string($result) && ctype_digit($result))) {
            throw new QueueException('Redis Queue operation response is invalid.');
        }
        return (int) $result;
    }

    private function execute(string $script, array $keys, array $arguments): mixed
    {
        try { return $this->redis->script($script, $keys, $arguments); }
        catch (RedisException $exception) {
            throw new QueueException('Redis Queue operation failed.', 0, $exception);
        }
    }

    private function key(string $suffix): string { return $this->prefix . $suffix; }

    private static function validCompositionId(string $id): bool
    {
        return preg_match('/\A[a-f0-9]{32}\z/D', $id) === 1;
    }

    private function requireCompositionId(string $id): void
    {
        if (!self::validCompositionId($id)) {
            throw new QueueException('Queue composition ID is invalid.');
        }
    }

    private function compositionKey(string $id): string
    {
        return $this->key('composition:' . $id);
    }

    private function reservationComposition(ReservedJob $job): ?string
    {
        if ($job->compositionId === null) {
            if ($job->compositionPosition !== null) {
                throw new QueueException('Queue composition reservation is invalid.');
            }
            return null;
        }
        $this->requireCompositionId($job->compositionId);
        if ($job->compositionPosition === null || $job->compositionPosition < 0
            || $job->compositionPosition > 99) {
            throw new QueueException('Queue composition reservation is invalid.');
        }
        return $job->compositionId;
    }

    private function compositionQueue(string $id): string
    {
        try { $json = $this->redis->get($this->compositionKey($id)); }
        catch (RedisException $exception) {
            throw new QueueException('Redis Queue composition lookup failed.', 0, $exception);
        }
        if ($json === null) throw new QueueException('Queue composition changed during cancellation.');
        try { $meta = json_decode($json, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) {
            throw new QueueException('Redis Queue composition status is invalid.', 0, $exception);
        }
        if (!is_array($meta) || !is_string($meta['queue'] ?? null)) {
            throw new QueueException('Redis Queue composition status is invalid.');
        }
        return \App\Queue\QueueManager::name($meta['queue']);
    }

    private function queueKey(string $queue, string $suffix): string
    {
        return $this->prefix . 'named:' . \App\Queue\QueueManager::name($queue) . ':' . $suffix;
    }

    private function member(int $id): string
    {
        if ($id < 1 || $id > 9007199254740991) throw new QueueException('Failed-job ID is invalid.');
        return sprintf('%020d', $id);
    }

    private function id(string $member): int
    {
        if (!preg_match('/^[0-9]{20}$/D', $member) || (int) $member < 1) {
            throw new QueueException('Redis Queue job ID is invalid.');
        }
        return (int) $member;
    }
}
