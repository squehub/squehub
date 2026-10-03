<?php

declare(strict_types=1);

namespace App\Foundation;

/**
 * One release identity for framework-owned output. An application's own
 * version and the installed Composer ref remain separate concerns.
 */
final class FrameworkVersion
{
    public const CURRENT = '2.0.0';

    private function __construct()
    {
    }
}
