<?php

declare(strict_types=1);

namespace App\Authorization\Rbac;

use RuntimeException;

/** Invalid RBAC configuration or mutation without identity details. */
class RbacException extends RuntimeException
{
}
