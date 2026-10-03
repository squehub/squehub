<?php

declare(strict_types=1);

namespace App\Cryptography;

use Closure;

/** Canonical gateway to the current Application-owned cryptographic manager. */
final class Crypt
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): CryptManager
    {
        if (self::$resolver === null) {
            throw new CryptConfigurationException('Cryptography is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
