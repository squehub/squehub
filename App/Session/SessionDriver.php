<?php

declare(strict_types=1);

namespace App\Session;

/** Storage and identity operations shared by native and in-memory sessions. */
interface SessionDriver
{
    public function start(): void;
    public function data(): array;
    public function replace(array $data): void;
    public function id(): string;
    public function regenerate(): void;
    public function invalidate(): void;
    public function close(): void;
}
