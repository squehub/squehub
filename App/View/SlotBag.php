<?php

declare(strict_types=1);

namespace App\View;

/** Read-only named slots captured for one component invocation. */
final class SlotBag
{
    /** @var array<string, ComponentSlot> */
    private readonly array $slots;

    /** @param array<string, ComponentSlot> $slots */
    public function __construct(array $slots = [])
    {
        foreach ($slots as $name => $slot) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $name) !== 1
                || !$slot instanceof ComponentSlot) {
                throw new ComponentException('A named component slot is invalid.');
            }
        }
        $this->slots = $slots;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->slots);
    }

    public function get(string $name): ?ComponentSlot
    {
        return $this->slots[$name] ?? null;
    }

    /** @return array<string, ComponentSlot> */
    public function all(): array
    {
        return $this->slots;
    }
}
