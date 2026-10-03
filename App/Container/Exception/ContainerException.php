<?php

declare(strict_types=1);

namespace App\Container\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/** Base PSR container failure exposed by binding and resolution operations. */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
