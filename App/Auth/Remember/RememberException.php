<?php

declare(strict_types=1);

namespace App\Auth\Remember;

use App\Auth\AuthException;

/** A safe public failure without cookie or validator material. */
final class RememberException extends AuthException
{
}
