<?php

declare(strict_types=1);

namespace App\View;

/**
 * Reports an invalid component interface without including prop or attribute
 * values, which may contain private application data.
 */
final class ComponentException extends \RuntimeException
{
}
