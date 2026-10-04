<?php

declare(strict_types=1);

namespace App\Cryptography;

use RuntimeException;

/** Safe public boundary for cryptographic failures; inputs never enter messages. */
class CryptException extends RuntimeException
{
}
