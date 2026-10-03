<?php

declare(strict_types=1);

namespace App\Api;

use RuntimeException;

/**
 * Signals an invalid resource definition or public representation. Framework
 * messages omit source values; chained application exceptions remain diagnostic
 * causes and must not be printed as public API payloads.
 */
final class ResourceException extends RuntimeException
{
}
