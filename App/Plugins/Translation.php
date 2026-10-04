<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Translation\Translation as TranslationGateway;
use App\Translation\TranslationManager;
use DateTimeInterface;
use DateTimeZone;

/** Application-facing locale, translation, and formatting gateway. */
final class Translation
{
    public static function manager(): TranslationManager { return TranslationGateway::manager(); }
    public static function locale(): string { return TranslationGateway::locale(); }
    public static function setLocale(string $locale): void { TranslationGateway::setLocale($locale); }
    public static function get(string $key, array $parameters = [], ?string $locale = null): string
    {
        return TranslationGateway::get($key, $parameters, $locale);
    }
    public static function plural(string $key, int|float $count,
        array $parameters = [], ?string $locale = null): string
    {
        return TranslationGateway::plural($key, $count, $parameters, $locale);
    }
    public static function number(int|float $number, ?string $locale = null,
        ?int $maxFractionDigits = null): string
    {
        return TranslationGateway::number($number, $locale, $maxFractionDigits);
    }
    public static function currency(int|float $amount, string $currencyCode,
        ?string $locale = null): string
    {
        return TranslationGateway::currency($amount, $currencyCode, $locale);
    }
    public static function dateTime(DateTimeInterface $date, ?string $locale = null,
        ?DateTimeZone $timezone = null, string $dateStyle = 'medium',
        string $timeStyle = 'short'): string
    {
        return TranslationGateway::dateTime($date, $locale, $timezone, $dateStyle, $timeStyle);
    }
}
