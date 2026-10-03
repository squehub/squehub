<?php

declare(strict_types=1);

namespace App\Translation;

use Closure;
use DateTimeInterface;
use DateTimeZone;

/** Resolve the currently selected Application's translation service. */
final class Translation
{
    private static ?Closure $resolver = null;

    public static function setResolver(?Closure $resolver): void { self::$resolver = $resolver; }

    public static function manager(): TranslationManager
    {
        if (self::$resolver === null) {
            throw new TranslationException('Translation is unavailable before Application bootstrap.');
        }
        return (self::$resolver)();
    }

    public static function locale(): string { return self::manager()->locale(); }
    public static function setLocale(string $locale): void { self::manager()->setLocale($locale); }
    public static function get(string $key, array $parameters = [], ?string $locale = null): string
    {
        return self::manager()->get($key, $parameters, $locale);
    }
    public static function plural(string $key, int|float $count,
        array $parameters = [], ?string $locale = null): string
    {
        return self::manager()->plural($key, $count, $parameters, $locale);
    }
    public static function number(int|float $number, ?string $locale = null,
        ?int $maxFractionDigits = null): string
    {
        return self::manager()->number($number, $locale, $maxFractionDigits);
    }
    public static function currency(int|float $amount, string $currencyCode,
        ?string $locale = null): string
    {
        return self::manager()->currency($amount, $currencyCode, $locale);
    }
    public static function dateTime(DateTimeInterface $date, ?string $locale = null,
        ?DateTimeZone $timezone = null, string $dateStyle = 'medium',
        string $timeStyle = 'short'): string
    {
        return self::manager()->dateTime($date, $locale, $timezone, $dateStyle, $timeStyle);
    }
}
