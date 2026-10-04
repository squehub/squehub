<?php

declare(strict_types=1);

namespace App\Kits;

use App\Packages\PackageName;

/** Kit identities use the same portable PHP spelling as Package identities. */
final class KitName
{
    public static function valid(string $name): bool
    {
        return PackageName::valid($name)
            && !in_array(strtolower($name), ['state', 'lifecycle'], true);
    }

    public static function require(string $name): string
    {
        if (!self::valid($name)) {
            throw new KitException('Kit name must be an exact, capitalized PHP identifier.');
        }
        return $name;
    }
}
