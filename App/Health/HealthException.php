<?php

declare(strict_types=1);

namespace App\Health;

use RuntimeException;

/** Registration and configuration misuse, without provider exception details. */
final class HealthException extends RuntimeException
{
}
