<?php

declare(strict_types=1);

namespace App\View\Assets;

/**
 * Reports an invalid asset declaration without including a URL, captured
 * markup, or a runtime value that might contain application secrets.
 */
final class AssetException extends \RuntimeException
{
}
