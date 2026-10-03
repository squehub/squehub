<?php

declare(strict_types=1);

namespace App\Locks;

/** A live lease belongs to a different owner. */
final class LockOwnershipException extends LockException
{
}
