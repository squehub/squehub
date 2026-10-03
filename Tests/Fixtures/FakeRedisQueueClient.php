<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Redis\RedisClient;

/**
 * Deterministic Redis command double for Queue wiring tests. It models script
 * effects, while opt-in live tests execute the actual Lua against Redis.
 */
final class FakeRedisQueueClient implements RedisClient
{
    public int $now = 1000;
    public bool $available = true;
    /** @var array<string,string> */
    public array $strings = [];
    /** @var array<string,array<string,string>> */
    public array $hashes = [];
    /** @var array<string,array<string,int>> */
    public array $sorted = [];
    /** @var array<string,int> */
    private array $sequences = [];
    /** @var array<string,int> */
    private array $expiresAt = [];

    public function execute(array $arguments): mixed
    {
        if (!$this->available) throw new \RuntimeException('fake endpoint with secret');
        if ($arguments[0] === 'PING') return 'PONG';
        if ($arguments[0] === 'GET') {
            $this->expire($arguments[1]);
            return $this->strings[$arguments[1]] ?? null;
        }
        if ($arguments[0] === 'SET') {
            $this->strings[$arguments[1]] = $arguments[2];
            return 'OK';
        }
        if ($arguments[0] !== 'EVAL') throw new \RuntimeException('Unsupported fake command.');
        $script = $arguments[1];
        $keyCount = (int) $arguments[2];
        $keys = array_slice($arguments, 3, $keyCount);
        $args = array_slice($arguments, 3 + $keyCount);
        preg_match('/squehub:queue:([a-z-]+)/', $script, $matches);
        return match ($matches[1] ?? '') {
            'status' => $this->status($keys),
            'dispatch' => $this->dispatch($keys, $args),
            'reserve' => $this->reserve($keys, $args),
            'acknowledge' => $this->acknowledge($keys, $args),
            'release' => $this->release($keys, $args),
            'fail' => $this->fail($keys, $args),
            'failed' => $this->failed($keys, $args),
            'failed-record' => $this->hashes[$keys[0]][$args[0]] ?? null,
            'retry' => $this->retry($keys, $args),
            'forget' => $this->forget($keys, $args),
            'prune' => $this->prune($keys, $args),
            'composition-create' => $this->compositionCreate($keys, $args),
            'composition-should-run' => $this->compositionShouldRun($keys, $args),
            'composition-cancel' => $this->compositionCancel($keys),
            'composition-skip' => $this->compositionSkip($keys, $args),
            'composition-prune' => $this->compositionPrune($keys, $args),
            default => throw new \RuntimeException('Unknown fake Queue script.'),
        };
    }

    public function close(): void {}

    private function next(string $key): int
    {
        return $this->sequences[$key] = ($this->sequences[$key] ?? 0) + 1;
    }

    private function member(int $id): string { return sprintf('%020d', $id); }

    private function record(string $key, string $member): array
    {
        return json_decode($this->hashes[$key][$member], true, 32, JSON_THROW_ON_ERROR);
    }

    private function store(string $key, string $member, array $record): void
    {
        $this->hashes[$key][$member] = json_encode($record, JSON_THROW_ON_ERROR);
    }

    private function expire(string $key): void
    {
        if (isset($this->expiresAt[$key]) && $this->now >= $this->expiresAt[$key]) {
            unset($this->expiresAt[$key], $this->strings[$key]);
        }
    }

    private function composition(string $key): ?array
    {
        $this->expire($key);
        return isset($this->strings[$key])
            ? json_decode($this->strings[$key], true, 32, JSON_THROW_ON_ERROR) : null;
    }

    private function saveComposition(string $key, array $meta): void
    {
        $this->strings[$key] = json_encode($meta, JSON_THROW_ON_ERROR);
        if (in_array($meta['state'], ['completed', 'completed_with_failures',
            'failed', 'cancelled'], true)) {
            $this->expiresAt[$key] = $this->now + $meta['retention_seconds'];
        }
    }

    private function firstDue(string $key): ?string
    {
        $due = array_filter($this->sorted[$key] ?? [], fn (int $score): bool => $score <= $this->now);
        if ($due === []) return null;
        uksort($due, static fn (string $left, string $right): int =>
            ($due[$left] <=> $due[$right]) ?: strcmp($left, $right));
        return array_key_first($due);
    }

    private function dispatch(array $keys, array $args): int
    {
        $id = $this->next($keys[0]);
        $member = $this->member($id);
        $available = $this->now + (int) $args[2];
        $this->store($keys[1], $member, ['queue' => $args[0], 'payload' => $args[1],
            'attempts' => 0, 'token' => '', 'available_at' => $available, 'created_at' => $this->now]);
        $this->sorted[$keys[2]][$member] = $available;
        return $id;
    }

    /** Mirror Redis ZCOUNT/ZCARD semantics for deterministic visibility tests. */
    private function status(array $keys): array
    {
        $due = $this->sorted[$keys[0]] ?? [];
        $leases = $this->sorted[$keys[1]] ?? [];
        return [
            count(array_filter($due, fn (int $score): bool => $score <= $this->now))
                + count(array_filter($leases, fn (int $score): bool => $score <= $this->now)),
            count(array_filter($due, fn (int $score): bool => $score > $this->now)),
            count(array_filter($leases, fn (int $score): bool => $score > $this->now)),
            count($this->sorted[$keys[2]] ?? []),
        ];
    }

    private function reserve(array $keys, array $args): array
    {
        foreach (($this->sorted[$keys[1]] ?? []) as $member => $expiry) {
            if ($expiry > $this->now) continue;
            unset($this->sorted[$keys[1]][$member]);
            $record = $this->record($keys[2], $member);
            $record['token'] = '';
            $record['available_at'] = $this->now;
            $this->store($keys[2], $member, $record);
            $this->sorted[$keys[0]][$member] = $this->now;
        }
        $member = $this->firstDue($keys[0]);
        if ($member === null) return [];
        unset($this->sorted[$keys[0]][$member]);
        $record = $this->record($keys[2], $member);
        $record['attempts']++;
        $record['token'] = $args[1];
        $this->store($keys[2], $member, $record);
        $this->sorted[$keys[1]][$member] = $this->now + (int) $args[2];
        return [$member, $record['payload'], $record['attempts'], $record['token'],
            $record['composition_id'] ?? '', $record['composition_position'] ?? -1];
    }

    private function owned(array $keys, array $args): bool
    {
        if (!isset($this->hashes[$keys[1]][$args[0]], $this->sorted[$keys[0]][$args[0]])) return false;
        return $this->record($keys[1], $args[0])['token'] === $args[1];
    }

    private function acknowledge(array $keys, array $args): int
    {
        if (!$this->owned($keys, $args)) return 0;
        $record = $this->record($keys[1], $args[0]);
        if (isset($record['composition_id'])) {
            $meta = $this->composition($keys[2]);
            $position = (int) $args[3];
            if ($meta === null || $record['composition_id'] !== $args[2]
                || $record['composition_position'] !== $position
                || ($meta['items'][$position]['member'] ?? null) !== $args[0]) {
                throw new \RuntimeException('Composition is invalid.');
            }
            $meta['items'][$position]['state'] = 'succeeded';
            unset($meta['items'][$position]['payload']);
            ++$meta['succeeded'];
            if ($meta['kind'] === 'chain' && $meta['state'] === 'active'
                && $position + 1 < $meta['total']) {
                $nextPosition = $position + 1;
                $member = $this->member($this->next($keys[4]));
                $meta['items'][$nextPosition]['state'] = 'queued';
                $meta['items'][$nextPosition]['member'] = $member;
                $this->store($keys[1], $member, ['queue' => $meta['queue'],
                    'payload' => $meta['items'][$nextPosition]['payload'],
                    'attempts' => 0, 'token' => '', 'available_at' => $this->now,
                    'created_at' => $this->now, 'composition_id' => $meta['id'],
                    'composition_position' => $nextPosition]);
                $this->sorted[$keys[5]][$member] = $this->now;
                unset($meta['items'][$nextPosition]['payload']);
            }
            if ($meta['succeeded'] + $meta['failed'] + $meta['cancelled'] === $meta['total']) {
                $meta['state'] = $meta['state'] === 'cancelling' ? 'cancelled'
                    : ($meta['failed'] > 0 ? 'completed_with_failures' : 'completed');
                $this->sorted[$keys[3]][$meta['id']] = $this->now;
            }
            $this->saveComposition($keys[2], $meta);
        }
        $this->removeOwned($keys, $args);
        return 1;
    }

    private function removeOwned(array $keys, array $args): void
    {
        unset($this->sorted[$keys[0]][$args[0]], $this->hashes[$keys[1]][$args[0]]);
    }

    private function release(array $keys, array $args): int
    {
        if (!$this->owned([$keys[1], $keys[2]], $args)) return 0;
        $record = $this->record($keys[2], $args[0]);
        if (isset($record['composition_id'])) {
            $meta = $this->composition($keys[3]);
            if ($meta === null || $record['composition_id'] !== $args[3]
                || $record['composition_position'] !== (int) $args[4]) {
                throw new \RuntimeException('Composition is invalid.');
            }
            if (!in_array($meta['state'], ['active', 'cancelling'], true)) {
                throw new \RuntimeException('Composition is invalid.');
            }
        }
        $record['token'] = '';
        $record['available_at'] = $this->now + (int) $args[2];
        unset($this->sorted[$keys[1]][$args[0]]);
        $this->store($keys[2], $args[0], $record);
        $this->sorted[$keys[0]][$args[0]] = $record['available_at'];
        return 1;
    }

    private function fail(array $keys, array $args): int
    {
        if (!$this->owned($keys, $args)) return 0;
        $record = $this->record($keys[1], $args[0]);
        if (isset($record['composition_id'])) {
            $meta = $this->composition($keys[5]);
            $position = (int) $args[6];
            if ($meta === null || $record['composition_id'] !== $args[5]
                || $record['composition_position'] !== $position
                || ($meta['items'][$position]['member'] ?? null) !== $args[0]) {
                throw new \RuntimeException('Composition is invalid.');
            }
            $meta['items'][$position]['state'] = 'failed';
            unset($meta['items'][$position]['payload']);
            ++$meta['failed'];
            if ($meta['kind'] === 'chain' && $meta['state'] === 'active') {
                for ($future = $position + 1; $future < $meta['total']; ++$future) {
                    $meta['items'][$future]['state'] = 'cancelled';
                    unset($meta['items'][$future]['payload']);
                    ++$meta['cancelled'];
                }
                $meta['state'] = 'failed';
            } elseif ($meta['succeeded'] + $meta['failed'] + $meta['cancelled'] === $meta['total']) {
                $meta['state'] = $meta['state'] === 'cancelling'
                    ? 'cancelled' : 'completed_with_failures';
            }
            if (in_array($meta['state'], ['failed', 'cancelled', 'completed_with_failures'], true)) {
                $this->sorted[$keys[6]][$meta['id']] = $this->now;
            }
            $this->saveComposition($keys[5], $meta);
        }
        $id = $this->member($this->next($keys[2]));
        $this->store($keys[3], $id, ['queue' => $record['queue'],
            'payload' => $record['payload'], 'job_class' => $args[2],
            'error_type' => $args[3], 'error_message' => $args[4],
            'attempts' => $record['attempts'], 'failed_at' => $this->now]);
        $this->sorted[$keys[4]][$id] = $this->now;
        $this->removeOwned([$keys[0], $keys[1]], $args);
        return 1;
    }

    private function failed(array $keys, array $args): string
    {
        $members = array_keys($this->sorted[$keys[0]] ?? []);
        usort($members, fn (string $a, string $b): int =>
            (($this->sorted[$keys[0]][$b] ?? 0) <=> ($this->sorted[$keys[0]][$a] ?? 0)) ?: strcmp($b, $a));
        $rows = [];
        foreach (array_slice($members, 0, (int) $args[0]) as $member) {
            $row = $this->record($keys[1], $member);
            $rows[] = ['id' => (int) $member, 'queue' => $row['queue'],
                'job_class' => $row['job_class'], 'error_type' => $row['error_type'],
                'attempts' => $row['attempts'], 'failed_at' => $row['failed_at']];
        }
        return json_encode($rows, JSON_THROW_ON_ERROR);
    }

    private function retry(array $keys, array $args): int
    {
        if (!isset($this->hashes[$keys[0]][$args[0]])) return 0;
        $row = $this->record($keys[0], $args[0]);
        if ($row['queue'] !== $args[1]) throw new \RuntimeException('Failed Queue record changed.');
        $member = $this->member($this->next($keys[2]));
        $this->store($keys[3], $member, ['queue' => $row['queue'],
            'payload' => $row['payload'], 'attempts' => 0, 'token' => '',
            'available_at' => $this->now, 'created_at' => $this->now]);
        $this->sorted[$keys[4]][$member] = $this->now;
        $this->forget([$keys[0], $keys[1]], $args);
        return 1;
    }

    private function forget(array $keys, array $args): int
    {
        $existed = isset($this->hashes[$keys[0]][$args[0]]);
        unset($this->hashes[$keys[0]][$args[0]], $this->sorted[$keys[1]][$args[0]]);
        return $existed ? 1 : 0;
    }

    private function prune(array $keys, array $args): int
    {
        $count = 0;
        foreach ($this->sorted[$keys[0]] ?? [] as $member => $failedAt) {
            if ($failedAt >= $this->now - (int) $args[0] * 3600) continue;
            $count += $this->forget([$keys[1], $keys[0]], [$member]);
        }
        return $count;
    }

    /** Publish metadata and the initially eligible records as one fake script. */
    private function compositionCreate(array $keys, array $args): int
    {
        if ($this->composition($keys[0]) !== null) throw new \RuntimeException('Composition exists.');
        $total = (int) $args[4];
        $scheduled = $args[1] === 'batch' ? $total : 1;
        $meta = ['version' => 1, 'id' => $args[0], 'kind' => $args[1],
            'queue' => $args[2], 'state' => 'active', 'total' => $total,
            'succeeded' => 0, 'failed' => 0, 'cancelled' => 0,
            'retention_seconds' => (int) $args[3], 'items' => []];
        for ($position = 0; $position < $total; ++$position) {
            $item = ['state' => 'pending', 'member' => ''];
            if ($position < $scheduled) {
                $member = $this->member($this->next($keys[1]));
                $item['state'] = 'queued';
                $item['member'] = $member;
                $this->store($keys[2], $member, ['queue' => $args[2],
                    'payload' => $args[5 + $position], 'attempts' => 0, 'token' => '',
                    'available_at' => $this->now, 'created_at' => $this->now,
                    'composition_id' => $args[0], 'composition_position' => $position]);
                $this->sorted[$keys[3]][$member] = $this->now;
            } else {
                $item['payload'] = $args[5 + $position];
            }
            $meta['items'][] = $item;
        }
        $this->saveComposition($keys[0], $meta);
        return 1;
    }

    private function compositionShouldRun(array $keys, array $args): int
    {
        if (!$this->owned([$keys[2], $keys[1]], $args)) {
            throw new \RuntimeException('Reservation is no longer owned.');
        }
        $record = $this->record($keys[1], $args[0]);
        $meta = $this->composition($keys[0]);
        if ($meta === null || $record['composition_id'] !== $args[3]
            || $record['composition_position'] !== (int) $args[2]
            || ($meta['items'][(int) $args[2]]['member'] ?? null) !== $args[0]) {
            throw new \RuntimeException('Composition is invalid.');
        }
        return $meta['state'] === 'active' ? 1 : 0;
    }

    private function compositionCancel(array $keys): int
    {
        $meta = $this->composition($keys[0]);
        if ($meta === null || $meta['state'] !== 'active') return 0;
        $meta['state'] = 'cancelling';
        foreach ($meta['items'] as &$item) {
            if ($item['state'] === 'pending') {
                $item['state'] = 'cancelled';
                unset($item['payload']);
                ++$meta['cancelled'];
            } elseif (in_array($item['state'], ['queued', 'running'], true)
                && isset($this->sorted[$keys[2]][$item['member']])) {
                unset($this->sorted[$keys[2]][$item['member']],
                    $this->hashes[$keys[1]][$item['member']]);
                $item['state'] = 'cancelled';
                unset($item['payload']);
                ++$meta['cancelled'];
            }
        }
        unset($item);
        $this->finishCancellation($meta, $keys[4]);
        $this->saveComposition($keys[0], $meta);
        return 1;
    }

    private function compositionSkip(array $keys, array $args): int
    {
        if (!$this->owned([$keys[0], $keys[1]], $args)) return 0;
        $meta = $this->composition($keys[2]);
        if ($meta === null || $meta['state'] !== 'cancelling'
            || ($meta['items'][(int) $args[3]]['member'] ?? null) !== $args[0]) {
            throw new \RuntimeException('Composition is invalid.');
        }
        $meta['items'][(int) $args[3]]['state'] = 'cancelled';
        unset($meta['items'][(int) $args[3]]['payload']);
        ++$meta['cancelled'];
        $this->finishCancellation($meta, $keys[3]);
        $this->saveComposition($keys[2], $meta);
        $this->removeOwned([$keys[0], $keys[1]], $args);
        return 1;
    }

    private function finishCancellation(array &$meta, string $terminalIndex): void
    {
        if ($meta['succeeded'] + $meta['failed'] + $meta['cancelled'] === $meta['total']) {
            $meta['state'] = 'cancelled';
            $this->sorted[$terminalIndex][$meta['id']] = $this->now;
        }
    }

    private function compositionPrune(array $keys, array $args): int
    {
        $count = 0;
        foreach ($this->sorted[$keys[0]] ?? [] as $id => $endedAt) {
            if ($endedAt >= $this->now - (int) $args[0] * 3600 || $count >= 1000) continue;
            $key = $keys[1] . $id;
            unset($this->strings[$key], $this->expiresAt[$key], $this->sorted[$keys[0]][$id]);
            ++$count;
        }
        return $count;
    }
}
