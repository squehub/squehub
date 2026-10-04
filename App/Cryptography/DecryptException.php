<?php

declare(strict_types=1);

namespace App\Cryptography;

/** Malformed, unsupported, or unauthenticated encrypted values fail closed. */
final class DecryptException extends CryptException
{
}
