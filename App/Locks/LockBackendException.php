<?php

declare(strict_types=1);

namespace App\Locks;

/** The selected coordination backend could not complete an operation. */
class LockBackendException extends LockException
{
}
