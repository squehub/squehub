<?php

declare(strict_types=1);

namespace App\Activation;

/** A registry lock path or acquisition failure, distinct from state validation. */
final class ActivationLockException extends ActivationException
{
}
