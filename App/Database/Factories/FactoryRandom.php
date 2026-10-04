<?php

declare(strict_types=1);

namespace App\Database\Factories;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Small per-factory generator; seeding it never changes PHP's process RNG. */
final class FactoryRandom
{
    private int $state;
    private int $sequence = 0;

    public function __construct(?int $seed = null)
    {
        $this->state = ($seed ?? random_int(0, 0xffffffff)) & 0xffffffff;
    }

    private function next(): int
    {
        $this->state = ($this->state * 1664525 + 1013904223) & 0xffffffff;
        return $this->state;
    }

    public function integer(int $minimum = 0, int $maximum = 100): int
    {
        if ($minimum > $maximum || $maximum - $minimum >= 0xffffffff) {
            throw new InvalidArgumentException('Factory random integer range is invalid or too large.');
        }
        return $minimum + $this->next() % ($maximum - $minimum + 1);
    }

    public function boolean(): bool
    {
        return $this->integer(0, 1) === 1;
    }

    /** @param array<array-key, mixed> $values */
    public function element(array $values): mixed
    {
        if ($values === []) {
            throw new InvalidArgumentException('Factory random element requires values.');
        }
        $values = array_values($values);
        return $values[$this->integer(0, count($values) - 1)];
    }

    public function name(): string
    {
        return $this->element(['Ada', 'Grace', 'Lin', 'Sam', 'Alex', 'Morgan'])
            . ' ' . $this->element(['Kalu', 'Stone', 'Lee', 'Rivera', 'Patel', 'Nolan']);
    }

    public function username(): string
    {
        return strtolower((string) $this->element(['ada', 'grace', 'lin', 'sam', 'alex', 'morgan']))
            . $this->integer(1000, 9999);
    }

    public function email(): string
    {
        $this->sequence++;
        return $this->username() . '.' . $this->sequence . '@example.test';
    }

    public function sentence(): string
    {
        return ucfirst((string) $this->element(['simple', 'clear', 'useful', 'small']))
            . ' ' . $this->element(['example', 'record', 'message', 'sample']) . '.';
    }

    public function uuid(): string
    {
        $hex = sprintf('%08x%08x%08x%08x', $this->next(), $this->next(), $this->next(), $this->next());
        $hex[12] = '4';
        $hex[16] = dechex(8 + hexdec($hex[16]) % 4);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-'
            . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }

    public function date(): string
    {
        return $this->instant()->format('Y-m-d');
    }

    public function datetime(): string
    {
        return $this->instant()->format('Y-m-d H:i:s');
    }

    private function instant(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . (1577836800 + $this->integer(0, 3650) * 86400)))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
