<?php

declare(strict_types=1);

namespace App\Api;

/** @internal Distinguishes an excluded field from an explicitly exposed null. */
enum OmittedValue
{
    case Field;
}
