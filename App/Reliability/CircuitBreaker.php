<?php

declare(strict_types=1);

namespace App\Reliability;

use App\Database\ModelClock;
use App\Database\SystemModelClock;
use SensitiveParameter;
use Throwable;

/**
 * Application-owned, opt-in protection for one logical external operation.
 * The selected file store coordinates only cooperating processes on one server.
 */
final class CircuitBreaker
{
    private ModelClock $clock;

    public function __construct(private FileCircuitStore $store, ?ModelClock $clock = null)
    {
        $this->clock = $clock ?? new SystemModelClock();
    }

    /**
     * @template T
     * @param callable():T $operation
     * @return T
     */
    public function run(string $name, CircuitPolicy $policy, callable $operation): mixed
    {
        $fingerprint = self::fingerprint($name);
        $permit = $this->admit($fingerprint, $policy);
        try {
            $result = $operation();
        } catch (Throwable $failure) {
            $this->settle($fingerprint, $permit, $policy,
                $policy->failsException($failure) ? 'failure' : 'neutral');
            throw $failure;
        }
        $this->settle($fingerprint, $permit, $policy,
            $policy->failsResponse($result) ? 'failure' : 'success');
        return $result;
    }

    /** Explicitly discard a named circuit's state. In-flight permits become stale. */
    public function clear(string $name): void
    {
        $this->store->clear(self::fingerprint($name));
    }

    /** @return array{mode:string,epoch:string,token:?string} */
    private function admit(string $fingerprint, CircuitPolicy $policy): array
    {
        $now = $this->now();
        return $this->store->mutate($fingerprint,
            function (?array $state) use ($now, $policy): array {
                if ($state === null) {
                    $state = self::closed(bin2hex(random_bytes(16)));
                }
                if ($state['mode'] === 'closed') {
                    return ['state' => $state, 'result' => [
                        'mode' => 'closed', 'epoch' => $state['epoch'], 'token' => null,
                    ]];
                }
                if ($state['mode'] === 'open' && $now < $state['reopen_at']) {
                    throw new CircuitOpenException(max(1, $state['reopen_at'] - $now));
                }
                if ($state['mode'] === 'half_open' && $now < $state['probe_until']) {
                    throw new CircuitOpenException(max(1, $state['probe_until'] - $now));
                }
                // Cooldown passed or a crashed probe lease expired. The token
                // prevents a late old probe from changing the new state.
                $token = bin2hex(random_bytes(16));
                $state['mode'] = 'half_open';
                $state['probe_token'] = $token;
                $state['probe_until'] = self::deadline($now, $policy->probeLeaseSeconds);
                return ['state' => $state, 'result' => [
                    'mode' => 'half_open', 'epoch' => $state['epoch'], 'token' => $token,
                ]];
            });
    }

    /**
     * @param array{mode:string,epoch:string,token:?string} $permit
     * @param 'success'|'failure'|'neutral' $outcome
     */
    private function settle(string $fingerprint, array $permit, CircuitPolicy $policy,
        string $outcome): void
    {
        $now = $this->now();
        $this->store->mutate($fingerprint,
            static function (?array $state) use ($permit, $policy, $outcome, $now): array {
                if ($state === null || $state['mode'] !== $permit['mode']
                    || $state['epoch'] !== $permit['epoch']) {
                    return ['state' => $state, 'result' => null];
                }
                if ($permit['mode'] === 'closed') {
                    if ($outcome === 'success') {
                        $state['failures'] = 0;
                    } elseif ($outcome === 'failure') {
                        $state['failures'] = min($policy->failureThreshold, $state['failures'] + 1);
                        if ($state['failures'] >= $policy->failureThreshold) {
                            $state['mode'] = 'open';
                            $state['reopen_at'] = self::deadline($now, $policy->cooldownSeconds);
                        }
                    }
                } elseif ($state['probe_token'] === $permit['token']
                    && $now < $state['probe_until']) {
                    if ($outcome === 'success') {
                        // New generation rejects completions from closed calls
                        // that predated the open/half-open cycle.
                        $state = self::closed(bin2hex(random_bytes(16)));
                    } else {
                        // An unrelated exception is neutral for failure count,
                        // but cannot prove the dependency healthy.
                        $state['mode'] = 'open';
                        $state['reopen_at'] = self::deadline($now, $policy->cooldownSeconds);
                        $state['probe_token'] = null;
                        $state['probe_until'] = 0;
                    }
                }
                return ['state' => $state, 'result' => null];
            });
    }

    /** @return array{version:int,mode:string,epoch:string,failures:int,reopen_at:int,probe_token:null,probe_until:int} */
    private static function closed(string $epoch): array
    {
        return ['version' => 1, 'mode' => 'closed', 'epoch' => $epoch,
            'failures' => 0, 'reopen_at' => 0, 'probe_token' => null, 'probe_until' => 0];
    }

    private function now(): int
    {
        $now = $this->clock->now()->getTimestamp();
        if ($now < 0) throw new CircuitException('Circuit clock is invalid.');
        return $now;
    }

    private static function deadline(int $now, int $seconds): int
    {
        if ($now > PHP_INT_MAX - $seconds) throw new CircuitException('Circuit clock exceeds supported range.');
        return $now + $seconds;
    }

    private static function fingerprint(#[SensitiveParameter] string $name): string
    {
        if (strlen($name) < 1 || strlen($name) > 128
            || preg_match('/\A[A-Za-z][A-Za-z0-9._:-]*\z/D', $name) !== 1) {
            throw new CircuitException('Circuit name is invalid.');
        }
        return hash('sha256', $name);
    }
}
