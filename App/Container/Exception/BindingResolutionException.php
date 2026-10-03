<?php

declare(strict_types=1);

namespace App\Container\Exception;

/** Reports a binding whose target cannot be constructed safely. */
final class BindingResolutionException extends ContainerException
{
}
