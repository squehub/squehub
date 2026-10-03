<?php

declare(strict_types=1);

namespace App\Clis\Make;

use RuntimeException;

/** A safe, user-facing failure while planning or writing a generated file. */
final class GeneratorException extends RuntimeException
{
}
