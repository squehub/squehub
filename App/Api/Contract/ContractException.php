<?php

declare(strict_types=1);

namespace App\Api\Contract;

use InvalidArgumentException;

/** Invalid public contract declarations fail before any OpenAPI is emitted. */
final class ContractException extends InvalidArgumentException
{
}
