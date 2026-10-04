<?php

declare(strict_types=1);

namespace App\View\Assets;

/** Fluent registration for one or more exact logical View owners. */
final class AssetOwnerRegistration
{
    /** @param list<string> $owners */
    public function __construct(
        private readonly AssetRegistry $registry,
        private readonly array $owners
    ) {
    }

    public function style(mixed $url, mixed $once = null): self
    {
        $this->registry->register($this->owners, 'style', $url, $once);
        return $this;
    }

    public function script(mixed $url, mixed $once = null): self
    {
        $this->registry->register($this->owners, 'script', $url, $once);
        return $this;
    }
}
