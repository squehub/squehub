<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

/** Rejects invalid application configuration before a dependent service runs. */
final class ConfigurationException extends RuntimeException
{
}
