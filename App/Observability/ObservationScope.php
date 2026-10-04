<?php

declare(strict_types=1);

namespace App\Observability;

/** A single explicit scope lifetime; finishing twice has no effect. */
final class ObservationScope
{
    private bool $finished = false;

    public function __construct(private ObservabilityManager $manager, private int $token)
    {
    }

    /** @param array<string, mixed> $attributes */
    public function finish(array $attributes = [], bool $failed = false): void
    {
        if ($this->finished) return;
        $this->finished = true;
        $this->manager->finish($this->token, $attributes, $failed);
    }
}
