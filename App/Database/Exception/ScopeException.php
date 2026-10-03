<?php

declare(strict_types=1);

namespace App\Database\Exception;

use LogicException;

/** Invalid scope declarations or scope application on a model query. */
final class ScopeException extends LogicException
{
}
