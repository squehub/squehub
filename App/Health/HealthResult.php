<?php

declare(strict_types=1);

namespace App\Health;

use InvalidArgumentException;

/** One bounded, operator-safe outcome. Exception text and configuration values never belong here. */
final class HealthResult
{
    private const STATUSES = ['pass', 'warning', 'fail', 'skipped'];

    public function __construct(
        private readonly string $name,
        private readonly string $category,
        private readonly string $status,
        private readonly string $summary,
        private readonly string $code,
        private readonly float $durationMs = 0.0
    ) {
        foreach ([$name, $category, $code] as $identifier) {
            if (preg_match('/\A[a-z][a-z0-9._-]{0,63}\z/D', $identifier) !== 1) {
                throw new InvalidArgumentException('Health result identifier is invalid.');
            }
        }
        if (!in_array($status, self::STATUSES, true) || $summary === '' || strlen($summary) > 160
            || preg_match('/[\x00-\x1F\x7F]/', $summary) || !is_finite($durationMs) || $durationMs < 0) {
            throw new InvalidArgumentException('Health result content is invalid.');
        }
    }

    public static function pass(string $name, string $category, string $summary = 'Available.', string $code = 'ok'): self
    { return new self($name, $category, 'pass', $summary, $code); }

    public static function warning(string $name, string $category, string $summary, string $code): self
    { return new self($name, $category, 'warning', $summary, $code); }

    public static function fail(string $name, string $category, string $summary, string $code): self
    { return new self($name, $category, 'fail', $summary, $code); }

    public static function skipped(string $name, string $category, string $summary, string $code): self
    { return new self($name, $category, 'skipped', $summary, $code); }

    public function name(): string { return $this->name; }
    public function category(): string { return $this->category; }
    public function status(): string { return $this->status; }
    public function summary(): string { return $this->summary; }
    public function code(): string { return $this->code; }
    public function durationMs(): float { return $this->durationMs; }

    /** Preserve an outcome while the manager attaches measured time and redacts text. */
    public function measured(float $durationMs, string $summary): self
    { return new self($this->name, $this->category, $this->status, $summary, $this->code, $durationMs); }

    /** @return array{name:string,category:string,status:string,summary:string,code:string,duration_ms:float} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'category' => $this->category, 'status' => $this->status,
            'summary' => $this->summary, 'code' => $this->code, 'duration_ms' => $this->durationMs];
    }
}
