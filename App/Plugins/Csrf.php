<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Security\Csrf\Csrf as CsrfGateway;
use App\Security\Csrf\CsrfTokenManager;

/** Explicit access to the current Application's CSRF token manager. */
final class Csrf
{
    public static function manager(): CsrfTokenManager { return CsrfGateway::manager(); }
}
