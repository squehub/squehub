<?php

declare(strict_types=1);

namespace App\Setup;

use Dotenv\Dotenv;
use Dotenv\Exception\InvalidFileException;

/**
 * Edits a small allowlist of .env assignments while retaining unrelated lines.
 *
 * The loader's own Dotenv parser validates both input and output. Existing
 * assignments outside the allowlist, including passwords, remain byte-for-byte
 * intact. Setup never needs their values to render a Change Plan.
 */
final class SetupEnvironmentEditor
{
    /** @param array<string,string> $updates */
    public static function update(string $source, array $updates): string
    {
        self::parse($source);
        if ($updates === []) {
            return $source;
        }

        $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $parts = preg_split('/(\r\n|\n|\r)/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            throw new SetupException('Application .env file cannot be edited safely.');
        }

        $seen = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 !== 0 || preg_match('/\A\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $part, $matches) !== 1) {
                continue;
            }
            $key = $matches[1];
            if (!array_key_exists($key, $updates)) {
                continue;
            }
            if (isset($seen[$key])) {
                throw new SetupException('Application .env contains duplicate managed settings.');
            }
            $seen[$key] = true;
            $parts[$index] = $key . '=' . self::quote($updates[$key]) . self::inlineComment($part);
        }

        $result = implode('', $parts);
        foreach ($updates as $key => $value) {
            if (isset($seen[$key])) {
                continue;
            }
            if ($result !== '' && !preg_match('/(?:\r\n|\n|\r)\z/D', $result)) {
                $result .= $newline;
            }
            $result .= $key . '=' . self::quote($value) . $newline;
        }

        $parsed = self::parse($result);
        foreach ($updates as $key => $value) {
            if (($parsed[$key] ?? null) !== $value) {
                throw new SetupException('Application .env values could not be validated.');
            }
        }
        return $result;
    }

    /** @return array<string,string|null> */
    public static function parse(string $source): array
    {
        try {
            $values = Dotenv::parse($source);
            // Dotenv may silently omit an unfinished quoted assignment. Setup
            // must reject that source rather than accidentally repairing it
            // while writing unrelated settings. Multiline values are left to
            // manual editing until a lossless parser is available.
            $seen = [];
            foreach (preg_split('/\r\n|\n|\r/', $source) ?: [] as $line) {
                if (preg_match('/\A\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=/', $line, $matches) !== 1) {
                    continue;
                }
                if (isset($seen[$matches[1]])) {
                    throw new SetupException('Application .env contains duplicate settings.');
                }
                $seen[$matches[1]] = true;
                $single = Dotenv::parse($line);
                if (!array_key_exists($matches[1], $single)) {
                    throw new SetupException('Application .env file is invalid or uses unsupported multiline values.');
                }
            }
            return $values;
        } catch (InvalidFileException) {
            // Parser exceptions can quote a line containing credentials.
            throw new SetupException('Application .env file is invalid.');
        }
    }

    private static function quote(string $value): string
    {
        // The loader accepts double-quoted values with escaped backslashes and
        // quotes. This also handles spaces, equals signs and literal # safely.
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private static function inlineComment(string $line): string
    {
        $length = strlen($line);
        $quote = null;
        $escaped = false;
        for ($index = 0; $index < $length; ++$index) {
            $char = $line[$index];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($char === '\\' && $quote === '"') {
                $escaped = true;
                continue;
            }
            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '#' && $index > 0 && ctype_space($line[$index - 1])) {
                return ' ' . substr($line, $index);
            }
        }
        return '';
    }
}
