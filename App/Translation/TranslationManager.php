<?php

declare(strict_types=1);

namespace App\Translation;

use App\Foundation\Application;
use App\Packages\PackageDescriptor;
use App\Packages\PackageFiles;
use App\Packages\PackageManager;
use App\Packages\PackageName;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlDateFormatter;
use JsonException;
use LogicException;
use MessageFormatter;
use NumberFormatter;

/** Application-owned locale state and on-demand, bounded JSON catalog reads. */
final class TranslationManager
{
    private string $defaultLocale;
    private string $fallbackLocale;
    /** @var list<string> */
    private array $supportedLocales;
    private string $applicationLocale;
    private string $currentLocale;
    /** @var list<string> */
    private array $scopes = [];

    /** @param array<string,mixed> $settings */
    public function __construct(private readonly Application $app, array $settings = [])
    {
        if (array_diff(array_keys($settings), ['default', 'fallback', 'supported']) !== []) {
            throw new TranslationException('Translation configuration has an unknown setting.');
        }
        $settings += ['default' => 'en', 'fallback' => 'en', 'supported' => ['en']];
        if (!is_string($settings['default']) || !is_string($settings['fallback'])
            || !is_array($settings['supported']) || !array_is_list($settings['supported'])
            || count($settings['supported']) < 1 || count($settings['supported']) > 32) {
            throw new TranslationException('Translation locale configuration is invalid.');
        }
        try {
            $this->defaultLocale = LocaleId::normalize($settings['default']);
            $this->fallbackLocale = LocaleId::normalize($settings['fallback']);
            $supported = [];
            foreach ($settings['supported'] as $locale) {
                if (!is_string($locale)) {
                    throw new TranslationException('Supported locale must be a string.');
                }
                $normalized = LocaleId::normalize($locale);
                if (in_array($normalized, $supported, true)) {
                    throw new TranslationException('Supported locales contain a duplicate.');
                }
                $supported[] = $normalized;
            }
        } catch (\InvalidArgumentException $exception) {
            throw new TranslationException('Translation locale configuration is invalid.', 0, $exception);
        }
        if (!in_array($this->defaultLocale, $supported, true)
            || !in_array($this->fallbackLocale, $supported, true)) {
            throw new TranslationException('Default and fallback locales must be supported.');
        }
        $this->supportedLocales = $supported;
        $this->applicationLocale = $this->defaultLocale;
        $this->currentLocale = $this->defaultLocale;
    }

    public function defaultLocale(): string { return $this->defaultLocale; }
    public function fallbackLocale(): string { return $this->fallbackLocale; }
    /** @return list<string> */
    public function supportedLocales(): array { return $this->supportedLocales; }
    public function locale(): string { return $this->currentLocale; }

    /** Set only the active scope, or change this Application's future scope default. */
    public function setLocale(string $locale): void
    {
        $selected = $this->requireSupported($locale);
        $this->currentLocale = $selected;
        if ($this->scopes === []) {
            $this->applicationLocale = $selected;
        }
    }

    /** A fresh request/job starts at the Application selection, never a prior operation. */
    public function beginScope(?string $locale = null): void
    {
        $selected = $locale === null ? $this->applicationLocale : $this->requireSupported($locale);
        $this->scopes[] = $this->currentLocale;
        $this->currentLocale = $selected;
    }

    public function endScope(): void
    {
        if ($this->scopes === []) {
            throw new LogicException('Translation locale scope is not active.');
        }
        $this->currentLocale = array_pop($this->scopes);
    }

    /** Missing keys return their identifier; extra parameters are ignored. */
    public function get(string $key, array $parameters = [], ?string $locale = null): string
    {
        $parsed = self::parseKey($key);
        $requested = $this->effectiveLocale($locale);
        $values = self::parameters($parameters);
        $entry = $this->lookup($parsed, $requested);
        return $entry === null ? $key : self::substitute($entry['text'], $values);
    }

    /** ICU plural categories are selected using the locale that supplied the message. */
    public function plural(string $key, int|float $count,
        array $parameters = [], ?string $locale = null): string
    {
        if (!is_finite((float) $count)) {
            throw new TranslationException('Plural count must be finite.');
        }
        $parsed = self::parseKey($key);
        $requested = $this->effectiveLocale($locale);
        $values = self::parameters($parameters);
        $entry = $this->lookup($parsed, $requested);
        if ($entry === null) return $key;
        if (!class_exists(MessageFormatter::class)) {
            throw new TranslationCapabilityException('ICU pluralization requires PHP intl or a compatible formatter.');
        }
        unset($values['count']);
        $formatted = MessageFormatter::formatMessage($entry['locale'], $entry['text'],
            ['count' => $count, ...$values]);
        if (!is_string($formatted)) {
            throw new TranslationException('Translation plural pattern is invalid.');
        }
        return $formatted;
    }

    public function number(int|float $number, ?string $locale = null,
        ?int $maxFractionDigits = null): string
    {
        $selected = $this->effectiveLocale($locale);
        if (!is_finite((float) $number)) {
            throw new TranslationException('Number must be finite.');
        }
        if ($maxFractionDigits !== null && ($maxFractionDigits < 0 || $maxFractionDigits > 20)) {
            throw new TranslationException('Maximum fraction digits are invalid.');
        }
        if (!class_exists(NumberFormatter::class)) {
            throw new TranslationCapabilityException('Locale-aware number formatting requires PHP intl or a compatible formatter.');
        }
        $formatter = new NumberFormatter($selected, NumberFormatter::DECIMAL);
        if ($maxFractionDigits !== null
            && !$formatter->setAttribute(NumberFormatter::MAX_FRACTION_DIGITS, $maxFractionDigits)) {
            throw new TranslationException('Number formatter configuration failed.');
        }
        $formatted = $formatter->format($number);
        if (!is_string($formatted)) {
            throw new TranslationException('Number formatting failed.');
        }
        return $formatted;
    }

    public function currency(int|float $amount, string $currencyCode,
        ?string $locale = null): string
    {
        $selected = $this->effectiveLocale($locale);
        if (!is_finite((float) $amount)) {
            throw new TranslationException('Currency amount must be finite.');
        }
        $currencyCode = strtoupper($currencyCode);
        if (preg_match('/\A[A-Z]{3}\z/D', $currencyCode) !== 1) {
            throw new TranslationException('Currency code must be an ISO-style three-letter code.');
        }
        if (!class_exists(NumberFormatter::class)) {
            throw new TranslationCapabilityException('Locale-aware currency formatting requires PHP intl or a compatible formatter.');
        }
        $formatted = (new NumberFormatter($selected, NumberFormatter::CURRENCY))
            ->formatCurrency($amount, $currencyCode);
        if (!is_string($formatted)) {
            throw new TranslationException('Currency formatting failed.');
        }
        return $formatted;
    }

    /** Locale changes presentation; timezone is explicit or taken from the value. */
    public function dateTime(DateTimeInterface $date, ?string $locale = null,
        ?DateTimeZone $timezone = null, string $dateStyle = 'medium',
        string $timeStyle = 'short'): string
    {
        $selected = $this->effectiveLocale($locale);
        if (!class_exists(IntlDateFormatter::class)) {
            throw new TranslationCapabilityException('Locale-aware date formatting requires PHP intl or a compatible formatter.');
        }
        $styles = [
            'none' => IntlDateFormatter::NONE,
            'short' => IntlDateFormatter::SHORT,
            'medium' => IntlDateFormatter::MEDIUM,
            'long' => IntlDateFormatter::LONG,
            'full' => IntlDateFormatter::FULL,
        ];
        if (!isset($styles[$dateStyle], $styles[$timeStyle])) {
            throw new TranslationException('Date or time style is invalid.');
        }
        $timezone ??= $date->getTimezone();
        $formatter = new IntlDateFormatter($selected, $styles[$dateStyle], $styles[$timeStyle],
            $timezone->getName(), IntlDateFormatter::GREGORIAN);
        $instant = DateTimeImmutable::createFromInterface($date)->setTimezone($timezone);
        $formatted = $formatter->format($instant);
        if (!is_string($formatted)) {
            throw new TranslationException('Date formatting failed.');
        }
        return $formatted;
    }

    private function requireSupported(string $locale): string
    {
        $normalized = LocaleId::normalize($locale);
        if (!in_array($normalized, $this->supportedLocales, true)) {
            throw new TranslationException('Locale is not supported by this Application.');
        }
        return $normalized;
    }

    private function effectiveLocale(?string $locale): string
    {
        return $locale === null ? $this->currentLocale : $this->requireSupported($locale);
    }

    /** @return list<string> */
    private function fallbackOrder(string $requested): array
    {
        $order = [];
        foreach ([$requested, $this->fallbackLocale] as $start) {
            for ($candidate = $start; $candidate !== null; $candidate = LocaleId::parent($candidate)) {
                if (in_array($candidate, $this->supportedLocales, true)
                    && !in_array($candidate, $order, true)) {
                    $order[] = $candidate;
                }
            }
        }
        return $order;
    }

    /** @return array{package:?string,group:string,segments:list<string>} */
    private static function parseKey(string $key): array
    {
        if ($key === '' || strlen($key) > 255) {
            throw new TranslationException('Translation key is invalid.');
        }
        $parts = explode('::', $key);
        if (count($parts) > 2) {
            throw new TranslationException('Translation key is invalid.');
        }
        $package = count($parts) === 2 ? $parts[0] : null;
        if ($package !== null && !PackageName::valid($package)) {
            throw new TranslationException('Translation Package namespace is invalid.');
        }
        $segments = explode('.', $parts[count($parts) - 1]);
        if (count($segments) < 2 || count($segments) > 16) {
            throw new TranslationException('Translation key is invalid.');
        }
        foreach ($segments as $segment) {
            if (!self::validSegment($segment)) {
                throw new TranslationException('Translation key is invalid.');
            }
        }
        return ['package' => $package, 'group' => array_shift($segments),
            'segments' => $segments];
    }

    private static function validSegment(string $segment): bool
    {
        return strlen($segment) <= 80
            && preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $segment) === 1;
    }

    /** @return array<string,string|int|float|bool> */
    private static function parameters(array $parameters): array
    {
        $values = [];
        if (count($parameters) > 64) {
            throw new TranslationException('Translation has too many parameters.');
        }
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || strlen($name) > 80
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || !(is_string($value) || is_int($value) || is_bool($value)
                    || (is_float($value) && is_finite($value)))) {
                throw new TranslationException('Translation parameter is invalid.');
            }
            $values[$name] = $value;
        }
        return $values;
    }

    /** @param array<string,string|int|float|bool> $parameters */
    private static function substitute(string $message, array $parameters): string
    {
        return (string) preg_replace_callback('/(?<![A-Za-z0-9_]):([A-Za-z_][A-Za-z0-9_]*)/',
            static function (array $match) use ($parameters): string {
                $name = $match[1];
                if (!array_key_exists($name, $parameters)) return $match[0];
                $value = $parameters[$name];
                if (is_bool($value)) return $value ? 'true' : 'false';
                return (string) $value;
            }, $message);
    }

    /**
     * Locale order outranks source order: requested Package text beats a
     * fallback-locale Project override, while same-locale Project wins.
     *
     * @param array{package:?string,group:string,segments:list<string>} $key
     * @return array{text:string,locale:string}|null
     */
    private function lookup(array $key, string $requested): ?array
    {
        $package = $key['package'] === null ? null : $this->activePackage($key['package']);
        if ($key['package'] !== null && $package === null) return null;
        foreach ($this->fallbackOrder($requested) as $locale) {
            $sources = $package === null
                ? [[$this->app->projectPath(), ['Translations', $locale, $key['group'] . '.json']]]
                : [
                    [$this->app->projectPath(), ['Translations', 'Packages', $key['package'],
                        $locale, $key['group'] . '.json']],
                    [$package->path(), ['Translations', $locale, $key['group'] . '.json']],
                ];
            foreach ($sources as [$root, $segments]) {
                $catalog = $this->readCatalog($root, $segments);
                if ($catalog === null) continue;
                $value = $catalog;
                foreach ($key['segments'] as $segment) {
                    if (!is_array($value) || !array_key_exists($segment, $value)) {
                        $value = null;
                        break;
                    }
                    $value = $value[$segment];
                }
                if (is_string($value)) return ['text' => $value, 'locale' => $locale];
            }
        }
        return null;
    }

    private function activePackage(string $name): ?PackageDescriptor
    {
        if (!$this->app->container()->has(PackageManager::class)) return null;
        foreach ($this->app->container()->make(PackageManager::class)->active() as $descriptor) {
            if ($descriptor->name() === $name) return $descriptor;
        }
        return null;
    }

    /** @param list<string> $segments @return array<string,mixed>|null */
    private function readCatalog(string $root, array $segments): ?array
    {
        if (!file_exists($root) && !is_link($root)) return null;
        $physicalRoot = self::safePhysical($root);
        if (!is_dir($root)) throw new TranslationException('Translation catalog root is invalid.');
        $path = $root;
        foreach ($segments as $index => $segment) {
            $entries = @scandir($path);
            if ($entries === false) throw new TranslationException('Translation catalog directory is unreadable.');
            if (!in_array($segment, $entries, true)) return null;
            $path .= '/' . $segment;
            $physical = self::safePhysical($path);
            if (!self::within($physical, $physicalRoot)) {
                throw new TranslationException('Translation catalog path is unsafe.');
            }
            if ($index < count($segments) - 1 && !is_dir($path)) {
                throw new TranslationException('Translation catalog directory is invalid.');
            }
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new TranslationException('Translation catalog file is unreadable.');
        }
        $size = @filesize($path);
        if (!is_int($size) || $size > 262144) {
            throw new TranslationException('Translation catalog exceeds its size limit.');
        }
        $contents = @file_get_contents($path);
        if (!is_string($contents) || strlen($contents) > 262144) {
            throw new TranslationException('Translation catalog could not be read.');
        }
        try {
            $catalog = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new TranslationException('Translation catalog JSON is invalid.', 0, $exception);
        }
        if (!is_array($catalog) || ($catalog !== [] && array_is_list($catalog))) {
            throw new TranslationException('Translation catalog must be a JSON object.');
        }
        $entries = 0;
        self::validateCatalog($catalog, 0, $entries);
        return $catalog;
    }

    private static function safePhysical(string $path): string
    {
        try {
            PackageFiles::assertPhysical($path);
        } catch (\Throwable $exception) {
            throw new TranslationException('Translation catalog path is unsafe.', 0, $exception);
        }
        $physical = realpath($path);
        if ($physical === false) throw new TranslationException('Translation catalog path is unavailable.');
        return rtrim(str_replace('\\', '/', $physical), '/');
    }

    private static function within(string $path, string $root): bool
    {
        $prefix = $root . '/';
        return DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($path, $prefix, strlen($prefix)) === 0
            : str_starts_with($path, $prefix);
    }

    /** @param array<string,mixed> $catalog */
    private static function validateCatalog(array $catalog, int $depth, int &$entries): void
    {
        if ($depth > 16 || $entries > 4096) {
            throw new TranslationException('Translation catalog exceeds its structure limit.');
        }
        foreach ($catalog as $key => $value) {
            ++$entries;
            if ($entries > 4096) {
                throw new TranslationException('Translation catalog exceeds its structure limit.');
            }
            if (!is_string($key) || !self::validSegment($key)
                || !(is_string($value) || is_array($value))
                || (is_array($value) && $value !== [] && array_is_list($value))) {
                throw new TranslationException('Translation catalog entry is invalid.');
            }
            if (is_array($value)) self::validateCatalog($value, $depth + 1, $entries);
        }
    }
}
