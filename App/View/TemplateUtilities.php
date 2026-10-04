<?php

declare(strict_types=1);

namespace App\View;

use InvalidArgumentException;
use JsonException;
use Stringable;

/** Pure presentation transformations used by templates and ordinary PHP. */
final class TemplateUtilities
{
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP
        | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES;

    /** Encode a JavaScript/JSON value without exposing HTML-dangerous syntax. */
    public static function json(mixed $value): string
    {
        $encoded = json_encode($value, self::JSON_FLAGS);
        if (!is_string($encoded)) {
            // JSON_THROW_ON_ERROR should make this unreachable, but never turn
            // an encoding failure into empty output if PHP behavior changes.
            throw new JsonException('JSON encoding failed.');
        }
        return $encoded;
    }

    /**
     * Join class entries in encounter order, retaining each distinct whole entry.
     * A class string is never tokenized, rewritten internally, or marked safe HTML.
     *
     * @param array<array-key, mixed> $entries
     */
    public static function classes(array $entries): string
    {
        $classes = [];
        $seen = [];
        self::appendClasses($entries, $classes, $seen);
        return implode(' ', $classes);
    }

    /**
     * @param array<array-key, mixed> $entries
     * @param list<string> $classes
     * @param array<string, true> $seen
     */
    private static function appendClasses(array $entries, array &$classes, array &$seen): void
    {
        foreach ($entries as $key => $value) {
            if (is_string($key)) {
                if ($value !== true && $value !== false && $value !== null) {
                    throw new InvalidArgumentException(
                        'A conditional class requires a boolean or null value.');
                }
                if ($value === true) {
                    self::appendClass($key, $classes, $seen);
                }
                continue;
            }

            if (is_array($value)) {
                self::appendClasses($value, $classes, $seen);
            } elseif ($value === null || $value === false) {
                continue;
            } elseif (is_string($value) || $value instanceof Stringable) {
                self::appendClass((string) $value, $classes, $seen);
            } else {
                throw new InvalidArgumentException(
                    'A class entry has an unsupported value type.');
            }
        }
    }

    /** @param list<string> $classes @param array<string, true> $seen */
    private static function appendClass(string $value, array &$classes, array &$seen): void
    {
        $entry = trim($value);
        if ($entry === '' || isset($seen[$entry])) {
            return;
        }
        $seen[$entry] = true;
        $classes[] = $entry;
    }
}
