<?php

declare(strict_types=1);

namespace App\Data;

use RuntimeException;

/** A content-safe failure at the explicitly registered payload boundary. */
final class TypedPayloadException extends RuntimeException
{
}
