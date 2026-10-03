<?php

declare(strict_types=1);

namespace App\Health;

/** Fresh, immutable snapshot of one sequential health run. */
final class HealthReport
{
    /** @param list<HealthResult> $results */
    public function __construct(private readonly string $type, private readonly array $results)
    {
    }

    public function type(): string { return $this->type; }
    /** @return list<HealthResult> */
    public function results(): array { return $this->results; }
    public function hasFailures(): bool
    { foreach ($this->results as $result) if ($result->status() === 'fail') return true; return false; }
    public function hasWarnings(): bool
    { foreach ($this->results as $result) if ($result->status() === 'warning') return true; return false; }
    public function healthy(): bool { return !$this->hasFailures(); }

    /** @return array{pass:int,warning:int,fail:int,skipped:int} */
    public function counts(): array
    {
        $counts = ['pass' => 0, 'warning' => 0, 'fail' => 0, 'skipped' => 0];
        foreach ($this->results as $result) ++$counts[$result->status()];
        return $counts;
    }

    /** @return array{type:string,status:string,counts:array,results:array} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'status' => $this->hasFailures() ? 'fail'
            : ($this->hasWarnings() ? 'warning' : 'pass'), 'counts' => $this->counts(),
            'results' => array_map(static fn (HealthResult $result): array => $result->toArray(), $this->results)];
    }
}
