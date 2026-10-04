<?php

declare(strict_types=1);

namespace App\Authorization;

use LogicException;

/** Invalid authorization wiring is an application failure, never a user denial. */
final class AuthorizationConfigurationException extends LogicException
{
}
