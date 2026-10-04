<?php

declare(strict_types=1);

namespace App\Routing;

use RuntimeException;

/** Reports a rejected or unsafe derived route cache without exposing source contents. */
final class RouteCacheException extends RuntimeException
{
}
