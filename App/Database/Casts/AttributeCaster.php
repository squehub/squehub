<?php

declare(strict_types=1);

namespace App\Database\Casts;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * Converts declared model attributes at the database, assignment, and output boundaries.
 * Values returned here are detached, canonical PHP values; JSON arrays contain no objects.
 */
final class AttributeCaster
{
    private const TYPES = ['integer', 'float', 'boolean', 'string', 'array', 'date', 'datetime'];

    /** @phpstan-assert 'integer'|'float'|'boolean'|'string'|'array'|'date'|'datetime' $type */
    public static function assertSupported(string $model, string $attribute, string $type): void
    {
        if (!in_array($type, self::TYPES, true)) {
            throw new CastException(
                "Model {$model} attribute {$attribute} expects a supported cast type: " . implode(', ', self::TYPES) . '.'
            );
        }
    }

    public static function fromStorage(string $model, string $attribute, string $type, mixed $value): mixed
    {
        self::assertSupported($model, $attribute, $type);
        if ($value === null) {
            return null;
        }
        if ($type !== 'array') {
            return self::fromInput($model, $attribute, $type, $value);
        }
        if (is_array($value)) {
            return self::jsonArray($model, $attribute, $value);
        }
        if (!is_string($value)) {
            throw self::invalid($model, $attribute, $type);
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $exception) {
            throw self::invalid($model, $attribute, $type, $exception);
        }
        if ($decoded === null) {
            // Both SQL NULL and JSON null have the documented PHP representation null.
            return null;
        }
        if (!is_array($decoded)) {
            throw self::invalid($model, $attribute, $type);
        }

        return $decoded;
    }

    public static function fromInput(string $model, string $attribute, string $type, mixed $value): mixed
    {
        self::assertSupported($model, $attribute, $type);
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => self::integer($model, $attribute, $value),
            'float' => self::float($model, $attribute, $value),
            'boolean' => self::boolean($model, $attribute, $value),
            'string' => self::string($model, $attribute, $value),
            'array' => self::jsonArray($model, $attribute, $value),
            'date' => self::date($model, $attribute, $value),
            'datetime' => self::datetime($model, $attribute, $value),
        };
    }

    public static function toStorage(string $model, string $attribute, string $type, mixed $value): mixed
    {
        $value = self::fromInput($model, $attribute, $type, $value);
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? 1 : 0,
            'array' => self::encodeArray($model, $attribute, $value),
            'date' => $value->format('Y-m-d'),
            'datetime' => $value->format('Y-m-d H:i:s'),
            default => $value,
        };
    }

    public static function serialize(string $model, string $attribute, string $type, mixed $value): mixed
    {
        $value = self::fromInput($model, $attribute, $type, $value);
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'date' => $value->format('Y-m-d'),
            'datetime' => $value->format('Y-m-d\TH:i:s\Z'),
            default => $value,
        };
    }

    /** Compare two already normalized values at the precision persisted by this cast. */
    public static function same(string $model, string $attribute, string $type, mixed $left, mixed $right): bool
    {
        self::assertSupported($model, $attribute, $type);
        if ($left === null || $right === null) {
            return $left === $right;
        }
        if ($type === 'date' || $type === 'datetime') {
            return self::toStorage($model, $attribute, $type, $left)
                === self::toStorage($model, $attribute, $type, $right);
        }
        if ($type === 'array') {
            return self::canonicalArray(self::jsonArray($model, $attribute, $left))
                === self::canonicalArray(self::jsonArray($model, $attribute, $right));
        }

        return self::fromInput($model, $attribute, $type, $left)
            === self::fromInput($model, $attribute, $type, $right);
    }

    private static function integer(string $model, string $attribute, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/\A[+-]?[0-9]+\z/D', $value) !== 1) {
            throw self::invalid($model, $attribute, 'integer');
        }
        $negative = str_starts_with($value, '-');
        $digits = ltrim(ltrim($value, '+-'), '0');
        if ($digits === '') {
            return 0;
        }
        $limit = $negative ? substr((string) PHP_INT_MIN, 1) : (string) PHP_INT_MAX;
        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            throw self::invalid($model, $attribute, 'integer');
        }

        return (int) $value;
    }

    private static function float(string $model, string $attribute, mixed $value): float
    {
        if (!is_int($value) && !is_float($value)
            && (!is_string($value)
                || preg_match('/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/D', $value) !== 1)) {
            throw self::invalid($model, $attribute, 'float');
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            throw self::invalid($model, $attribute, 'float');
        }

        return $number;
    }

    private static function boolean(string $model, string $attribute, mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || (is_string($value) && strcasecmp($value, 'true') === 0)) {
            return true;
        }
        if ($value === 0 || $value === '0' || (is_string($value) && strcasecmp($value, 'false') === 0)) {
            return false;
        }

        throw self::invalid($model, $attribute, 'boolean');
    }

    private static function string(string $model, string $attribute, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_float($value) && is_finite($value)) {
            return (string) $value;
        }

        throw self::invalid($model, $attribute, 'string');
    }

    private static function jsonArray(string $model, string $attribute, mixed $value): array
    {
        if (!is_array($value)) {
            throw self::invalid($model, $attribute, 'array');
        }
        self::assertJsonTree($model, $attribute, $value, 0);
        self::encodeArray($model, $attribute, $value);

        return $value;
    }

    private static function assertJsonTree(string $model, string $attribute, mixed $value, int $depth): void
    {
        if ($depth > 511) {
            throw self::invalid($model, $attribute, 'array');
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertJsonTree($model, $attribute, $item, $depth + 1);
            }
            return;
        }
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value))) {
            return;
        }

        throw self::invalid($model, $attribute, 'array');
    }

    private static function encodeArray(string $model, string $attribute, array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw self::invalid($model, $attribute, 'array', $exception);
        }
    }

    private static function canonicalArray(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonicalArray($item);
            }
        }
        unset($item);

        return $value;
    }

    private static function date(string $model, string $attribute, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            // A date is a calendar day in the caller's timezone, not an instant to shift.
            $value = DateTimeImmutable::createFromInterface($value)->format('Y-m-d');
        }
        if (!is_string($value) || preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}\z/D', $value) !== 1) {
            throw self::invalid($model, $attribute, 'date');
        }

        return self::parseExact($model, $attribute, 'date', '!Y-m-d', $value);
    }

    private static function datetime(string $model, string $attribute, mixed $value): DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            $value = DateTimeImmutable::createFromInterface($value)->setTimezone(self::utc());
            return self::parseExact($model, $attribute, 'datetime', '!Y-m-d H:i:s', $value->format('Y-m-d H:i:s'));
        }
        if (!is_string($value)) {
            throw self::invalid($model, $attribute, 'datetime');
        }
        $format = match (true) {
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', $value) === 1 => '!Y-m-d H:i:s',
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z\z/D', $value) === 1 => '!Y-m-d\TH:i:s\Z',
            preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}[+-](?:(?:0[0-9]|1[0-3]):[0-5][0-9]|14:00)\z/D', $value) === 1 => '!Y-m-d\TH:i:sP',
            default => null,
        };
        if ($format === null) {
            throw self::invalid($model, $attribute, 'datetime');
        }

        $parsed = self::parseExact($model, $attribute, 'datetime', $format, $value);

        return $parsed->setTimezone(self::utc());
    }

    private static function parseExact(string $model, string $attribute, string $type, string $format, string $value): DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $value, self::utc());
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(substr($format, 1)) !== $value) {
            throw self::invalid($model, $attribute, $type);
        }

        return $parsed;
    }

    private static function utc(): DateTimeZone
    {
        return new DateTimeZone('UTC');
    }

    private static function invalid(string $model, string $attribute, string $type, ?Throwable $previous = null): CastException
    {
        return new CastException("Model {$model} attribute {$attribute} expects {$type}.", 0, $previous);
    }
}
