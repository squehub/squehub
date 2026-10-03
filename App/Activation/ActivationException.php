<?php

declare(strict_types=1);

namespace App\Activation;

use RuntimeException;

/** Safe registry storage or integrity failure; never includes record contents. */
class ActivationException extends RuntimeException
{
}
