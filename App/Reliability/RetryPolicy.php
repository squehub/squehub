<?php

declare(strict_types=1);

namespace App\Reliability;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Bounded retry timing shared by callers that retain their own failure rules.
 *
 * The policy decides when another attempt may start; HTTP, Webhooks, and Queue
 * still decide which outcomes are retryable and who owns delivery/settlement.
 * It never executes an operation or makes a failed mutation safe to replay.
 */
final readonly class RetryPolicy
{
    public const MAX_DELAY_MS = 30_000;

    public function __construct(
        private int $maxAttempts = 1,
        private int $initialDelayMs = 0,
        private float $multiplier = 1.0,
        private int $maxDelayMs = self::MAX_DELAY_MS,
        private float $jitter = 0.0,
        private ?int $maxElapsedMs = null,
    ) {
        if ($maxAttempts < 1 || $maxAttempts > 100
            || $initialDelayMs < 0 || $initialDelayMs > self::MAX_DELAY_MS
            || $maxDelayMs < $initialDelayMs || $maxDelayMs > self::MAX_DELAY_MS
            || !is_finite($multiplier) || $multiplier < 1.0 || $multiplier > 10.0
            || !is_finite($jitter) || $jitter < 0.0 || $jitter > 1.0
            || ($maxElapsedMs !== null && ($maxElapsedMs < 1 || $maxElapsedMs > 3_600_000))) {
            throw new InvalidArgumentException('Retry policy is invalid.');
        }
    }

    public function maxAttempts(): int { return $this->maxAttempts; }

    /**
     * Return null when no later attempt is permitted. The caller passes a
     * server hint only after deciding that the outcome is retryable. A hint
     * cannot extend the policy's delay or elapsed limits.
     */
    public function nextDelayMs(int $completedAttempt, int $elapsedMs,
        ?int $serverDelayMs = null, ?float $jitterSample = null): ?int
    {
        if ($completedAttempt < 1 || $elapsedMs < 0
            || ($serverDelayMs !== null && $serverDelayMs < 0)
            || ($jitterSample !== null && (!is_finite($jitterSample)
                || $jitterSample < 0.0 || $jitterSample > 1.0))) {
            throw new InvalidArgumentException('Retry attempt timing is invalid.');
        }
        if ($completedAttempt >= $this->maxAttempts
            || ($this->maxElapsedMs !== null && $elapsedMs >= $this->maxElapsedMs)) {
            return null;
        }

        $base = min((float) $this->maxDelayMs,
            $this->initialDelayMs * ($this->multiplier ** min(100, $completedAttempt - 1)));
        if ($this->jitter > 0.0) {
            // Supplying the unit sample makes jitter tests exact without a
            // process-global random seed; production uses nonsecret randomness.
            $sample = $jitterSample ?? (mt_rand() / mt_getrandmax());
            $base *= 1.0 - $this->jitter + (2.0 * $this->jitter * $sample);
        }
        $delay = min($this->maxDelayMs, max(0, (int) round($base)));
        if ($serverDelayMs !== null) {
            $delay = max($delay, min($this->maxDelayMs, $serverDelayMs));
        }
        if ($this->maxElapsedMs !== null && $delay >= $this->maxElapsedMs - $elapsedMs) {
            return null;
        }
        return $delay;
    }

    /**
     * Parse either delta seconds or the standard HTTP date without accepting
     * an unbounded remote sleep instruction. Malformed hints are ignored.
     */
    public static function retryAfterMs(?string $header,
        ?DateTimeImmutable $now = null): ?int
    {
        if ($header === null || strlen($header) > 128
            || preg_match('/[\x00-\x1f\x7f]/', $header) === 1) {
            return null;
        }
        $header = trim($header);
        if (preg_match('/\A[0-9]+\z/D', $header) === 1) {
            $seconds = ltrim($header, '0');
            if ($seconds === '') return 0;
            if (strlen($seconds) > 2 || (int) $seconds >= 30) return self::MAX_DELAY_MS;
            return (int) $seconds * 1000;
        }
        if (preg_match('/\A[A-Z][a-z]{2}, [0-9]{2} [A-Z][a-z]{2} [0-9]{4} '
            . '[0-9]{2}:[0-9]{2}:[0-9]{2} GMT\z/D', $header) !== 1) {
            return null;
        }
        $utc = new DateTimeZone('UTC');
        $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $header, $utc);
        if ($date === false || $date->format('D, d M Y H:i:s \G\M\T') !== $header) return null;
        $now ??= new DateTimeImmutable('now', $utc);
        $seconds = $date->getTimestamp() - $now->getTimestamp();
        return min(self::MAX_DELAY_MS, max(0, $seconds) * 1000);
    }
}
