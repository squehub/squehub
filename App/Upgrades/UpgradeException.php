<?php

declare(strict_types=1);

namespace App\Upgrades;

use RuntimeException;

/** A safe failure to inspect local upgrade inputs; no source bytes or secrets are exposed. */
final class UpgradeException extends RuntimeException
{
}
