<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Mfa\Mfa as MfaGateway;
use App\Mfa\MfaContext;
use App\Mfa\MfaManager;

/** Current Application's second-factor service. */
final class Mfa
{
    public static function manager(): MfaManager { return MfaGateway::manager(); }
    public static function forGuard(string $name): MfaContext { return self::manager()->forGuard($name); }
    public static function pending(): bool { return self::manager()->pending(); }
    public static function completeChallenge(#[\SensitiveParameter] string $proof): bool
    {
        return self::manager()->completeChallenge($proof);
    }
}
