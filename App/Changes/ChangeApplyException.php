<?php

declare(strict_types=1);

namespace App\Changes;

use RuntimeException;
use Throwable;

/**
 * Carries observed partial effects without claiming a filesystem rollback.
 * The public message is fixed because lower-level failures may contain paths
 * or source data; the original exception remains available as the cause.
 */
final class ChangeApplyException extends RuntimeException
{
    public function __construct(public readonly ChangeResult $result, ?Throwable $previous = null)
    {
        parent::__construct('Change application was incomplete; review applied actions and recovery path.',
            0, $previous);
    }
}
