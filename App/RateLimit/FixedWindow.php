<?php

declare(strict_types=1);

namespace App\RateLimit;

use DateTimeImmutable;

/** Shared fixed-window calculation, called inside each store's atomic section. */
final class FixedWindow
{
    /**
     * @param array{version:int,count:int,limit:int,window_seconds:int,resets_at:int}|null $record
     * @return array{0:array{version:int,count:int,limit:int,window_seconds:int,resets_at:int},1:RateLimitResult}
     */
    public static function consume(?array $record, int $limit, int $seconds, DateTimeImmutable $now): array
    {
        if ($limit < 1 || $limit >= PHP_INT_MAX || $seconds < 1) {
            throw new RateLimitException('Rate-limit attempts and window seconds must be positive and bounded.');
        }
        $current = $now->getTimestamp();
        if ($record === null || $current >= $record['resets_at']
            || $record['limit'] !== $limit || $record['window_seconds'] !== $seconds) {
            // An expiry or policy change starts a new window at this consume.
            if ($current > PHP_INT_MAX - $seconds) {
                throw new RateLimitException('Rate-limit reset time is out of range.');
            }
            $record = ['version' => 1, 'count' => 0, 'limit' => $limit,
                'window_seconds' => $seconds, 'resets_at' => $current + $seconds];
        }
        // Denials count, but do not move the reset boundary. Capping at limit+1
        // avoids integer growth under sustained abuse.
        $record['count'] = min($limit + 1, $record['count'] + 1);
        $allowed = $record['count'] <= $limit;
        $reset = (new DateTimeImmutable('@' . $record['resets_at']));
        return [$record, new RateLimitResult($allowed, $limit,
            max(0, $limit - $record['count']), $allowed ? 0 : max(1, $record['resets_at'] - $current), $reset)];
    }
}
