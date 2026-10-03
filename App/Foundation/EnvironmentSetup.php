<?php

declare(strict_types=1);

namespace App\Foundation;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/**
 * Detects an unfinished installation without exposing environment values.
 *
 * The comparison uses parsed values, so comments, ordering, and line endings
 * cannot disguise an otherwise unchanged copy of the distributed template.
 * Invalid or unreadable files remain the normal bootstrap's responsibility.
 */
final class EnvironmentSetup
{
    public const MISSING = 'missing';
    public const UNCHANGED = 'unchanged';

    public static function status(string $basePath): ?string
    {
        $environmentFile = rtrim($basePath, '/\\') . '/.env';
        if (!file_exists($environmentFile)) {
            return self::MISSING;
        }

        $templateFile = rtrim($basePath, '/\\') . '/.example.env';
        if (!is_file($environmentFile) || !is_readable($environmentFile)
            || !is_file($templateFile) || !is_readable($templateFile)) {
            return null;
        }

        $environment = @file_get_contents($environmentFile);
        $template = @file_get_contents($templateFile);
        if ($environment === false || $template === false) {
            return null;
        }

        try {
            $environmentValues = Dotenv::parse($environment);
            $templateValues = Dotenv::parse($template);
        } catch (InvalidFileException) {
            return null;
        }

        ksort($environmentValues);
        ksort($templateValues);
        return $environmentValues === $templateValues ? self::UNCHANGED : null;
    }

    public static function notice(string $basePath): ?string
    {
        return match (self::status($basePath)) {
            self::MISSING => 'The .env file is missing. Copy .example.env to .env, then set APP_KEY and review your application settings.',
            self::UNCHANGED => 'The .env file still has the example settings. Set APP_KEY and review your application settings before continuing.',
            default => null,
        };
    }
}
