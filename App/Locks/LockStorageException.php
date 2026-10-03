<?php

declare(strict_types=1);

namespace App\Locks;

/** Stored lock state is malformed or an atomic backend reply is invalid. */
final class LockStorageException extends LockBackendException
{
}
