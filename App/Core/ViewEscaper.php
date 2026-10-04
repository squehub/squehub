<?php

declare(strict_types=1);

namespace App\Core;

use Stringable;

/**
 * Converts supported template values at the HTML output boundary.
 *
 * Application data stays unescaped in Models, Sessions, and controllers. The
 * explicit raw path changes only output handling and never marks a value safe.
 */
final class ViewEscaper
{
    public static function escape(mixed $value): string
    {
        return htmlspecialchars(self::string($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Raw interpolation is explicit; it still rejects values PHP cannot render reliably. */
    public static function raw(mixed $value): string
    {
        return self::string($value);
    }

    private static function string(mixed $value): string
    {
        if ($value === null || $value === false) return '';
        if ($value === true) return '1';
        if (is_string($value) || is_int($value) || is_float($value)) return (string) $value;
        if ($value instanceof Stringable) return (string) $value;

        throw new ViewException('Template output requires a scalar or Stringable value.');
    }
}
