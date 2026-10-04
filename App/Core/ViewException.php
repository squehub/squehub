<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Signals a template value or rendering contract that cannot be handled safely. */
final class ViewException extends RuntimeException
{
}
