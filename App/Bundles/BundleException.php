<?php

declare(strict_types=1);

namespace App\Bundles;

use RuntimeException;

/** A bounded, content-free failure while inspecting or applying a project bundle. */
final class BundleException extends RuntimeException
{
}
