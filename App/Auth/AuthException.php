<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

/** Reports invalid authentication configuration or lifecycle misuse without exposing secrets. */
class AuthException extends RuntimeException
{
}
