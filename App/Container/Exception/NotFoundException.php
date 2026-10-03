<?php

declare(strict_types=1);

namespace App\Container\Exception;

use Psr\Container\NotFoundExceptionInterface;

/** PSR container signal for a requested service that has no binding. */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
