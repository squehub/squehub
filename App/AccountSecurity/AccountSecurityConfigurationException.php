<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use LogicException;

/** Invalid account-security wiring is distinct from an invalid user token. */
final class AccountSecurityConfigurationException extends LogicException
{
}
