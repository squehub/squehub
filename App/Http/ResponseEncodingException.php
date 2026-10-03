<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/** Reports a response payload that cannot be encoded as valid JSON. */
final class ResponseEncodingException extends RuntimeException
{
}
