<?php

declare(strict_types=1);

namespace App\Session;

use RuntimeException;

/** Signals a session driver or lifecycle failure to the application boundary. */
final class SessionException extends RuntimeException
{
}
