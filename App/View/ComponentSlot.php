<?php

declare(strict_types=1);

namespace App\View;

/**
 * Holds HTML rendered once in the invoking template's scope. The caller uses
 * toHtml() with a raw template echo because the slot has already been escaped
 * and rendered by its source template.
 */
final class ComponentSlot
{
    public function __construct(private readonly string $html)
    {
    }

    public function toHtml(): string
    {
        return $this->html;
    }
}
