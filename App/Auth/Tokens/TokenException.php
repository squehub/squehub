<?php

declare(strict_types=1);

namespace App\Auth\Tokens;

use RuntimeException;

/** Reports token configuration, persistence, or lifecycle failures without credential values. */
final class TokenException extends RuntimeException
{
}
