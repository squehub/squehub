<?php

declare(strict_types=1);

namespace Project\Controllers;

final class CharacterizationController
{
    public function show(string $id): string
    {
        return "controller:{$id}";
    }
}
