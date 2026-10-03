<?php

declare(strict_types=1);

namespace App\AccountSecurity;

use RuntimeException;

/** Safe application-facing refusal of an account credential transition. */
class AccountSecurityException extends RuntimeException
{
}
