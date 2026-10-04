<?php

declare(strict_types=1);

namespace App\Container\Exception;

/** Reports a dependency cycle found while resolving a container service. */
final class CircularDependencyException extends ContainerException
{
}
