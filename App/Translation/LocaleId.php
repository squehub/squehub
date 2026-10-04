<?php

declare(strict_types=1);

namespace App\Translation;

use InvalidArgumentException;

/** A bounded filesystem-safe subset of BCP 47: language[-Script][-REGION]. */
final class LocaleId
{
    public static function normalize(string $locale): string
    {
        if ($locale === '' || strlen($locale) > 35
            || preg_match('/\A([A-Za-z]{2,3})(?:-([A-Za-z]{4}))?(?:-([A-Za-z]{2}|[0-9]{3}))?\z/D',
                $locale, $parts) !== 1) {
            throw new InvalidArgumentException('Locale identifier is invalid.');
        }
        $canonical = strtolower($parts[1]);
        if (($parts[2] ?? '') !== '') {
            $canonical .= '-' . ucfirst(strtolower($parts[2]));
        }
        if (($parts[3] ?? '') !== '') {
            $canonical .= '-' . strtoupper($parts[3]);
        }
        return $canonical;
    }

    public static function parent(string $locale): ?string
    {
        $separator = strrpos($locale, '-');
        return $separator === false ? null : substr($locale, 0, $separator);
    }
}
