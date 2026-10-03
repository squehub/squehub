<?php

declare(strict_types=1);

namespace App\Api\Sdk;

use RuntimeException;

/** A public contract cannot be represented faithfully by a generated SDK. */
final class SdkException extends RuntimeException
{
}
