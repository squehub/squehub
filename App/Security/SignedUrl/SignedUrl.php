<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use Closure;
use LogicException;

/** Resolve signed URLs from the Application selected for this operation. */
final class SignedUrl
{
    private static ?Closure $resolver = null;

    public static function setResolver(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    public static function manager(): SignedUrlManager
    {
        if (self::$resolver === null) {
            throw new LogicException('Signed URLs are unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }
}
