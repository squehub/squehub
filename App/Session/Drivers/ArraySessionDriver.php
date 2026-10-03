<?php

declare(strict_types=1);

namespace App\Session\Drivers;

use App\Session\SessionDriver;

/**
 * In-memory persistence for one Application lifetime. The store decides
 * request boundaries; this driver keeps values and identity through close().
 */
final class ArraySessionDriver implements SessionDriver
{
    private array $values = [];
    private string $identifier = '';

    public function start(): void
    {
        if ($this->identifier === '') $this->identifier = bin2hex(random_bytes(16));
    }

    public function data(): array { return $this->values; }
    public function replace(array $data): void { $this->values = $data; }
    public function id(): string { $this->start(); return $this->identifier; }
    public function regenerate(): void { $this->identifier = bin2hex(random_bytes(16)); }
    public function invalidate(): void { $this->values = []; $this->regenerate(); }
    public function close(): void {}
}
