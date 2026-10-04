<?php

declare(strict_types=1);

namespace App\Data;

use App\Database\Model;
use BackedEnum;
use ReflectionClass;
use ReflectionEnum;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;
use SensitiveParameter;
use Throwable;

/**
 * Constructs a caller-selected PHP class from only its declared parameters.
 *
 * Mapping is stateless and does not use a Container, property injection,
 * PHP serialization, or an application-global reflection cache. Its strict
 * conversions are independent of PHP's weak argument coercion.
 */
final class DataMapper
{
    private const MAX_DEPTH = 16;

    /**
     * @template T of object
     * @param class-string<T> $class A class selected by trusted application code.
     * @param array<string, mixed> $values
     * @return T
     */
    public static function map(string $class, #[SensitiveParameter] array $values): object
    {
        return self::construct($class, $values, '', 0);
    }

    /** @param array<string, mixed> $values */
    private static function construct(string $class, #[SensitiveParameter] array $values,
        string $prefix, int $depth): object
    {
        if ($depth > self::MAX_DEPTH) {
            throw new DataMappingException('depth_limit', $prefix !== '' ? $prefix : null);
        }
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException $exception) {
            throw new DataMappingException('invalid_class', $prefix !== '' ? $prefix : null, $exception);
        }
        // Models own persistence and fillable policy; mapping constructor input
        // into them would blur the boundary between request data and ORM state.
        if (!$reflection->isInstantiable() || $reflection->isInternal()
            || $reflection->isSubclassOf(Model::class)) {
            throw new DataMappingException('invalid_class', $prefix !== '' ? $prefix : null);
        }
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && !$constructor->isPublic()) {
            throw new DataMappingException('invalid_class', $prefix !== '' ? $prefix : null);
        }
        $arguments = [];
        foreach ($constructor?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic() || $parameter->isPassedByReference()) {
                throw new DataMappingException('unsupported_type', self::field($prefix, $parameter->getName()));
            }
            $name = $parameter->getName();
            $field = self::field($prefix, $name);
            $nested = self::nested($parameter, $field);
            $type = $parameter->getType();
            if (!$type instanceof ReflectionNamedType || $type->getName() === 'mixed') {
                throw new DataMappingException('unsupported_type', $field);
            }
            self::assertSupported($type, $nested, $field);
            if (!array_key_exists($name, $values)) {
                if ($parameter->isDefaultValueAvailable()) {
                    continue;
                }
                throw new DataMappingException('missing_field', $field);
            }
            $arguments[$name] = self::convert($values[$name], $type, $nested, $field, $depth);
        }

        try {
            $instance = $reflection->newInstanceArgs($arguments);
            return $instance;
        } catch (Throwable $exception) {
            // A domain constructor may reject validly typed values. Preserve its
            // cause internally without publishing its message or arguments.
            throw new DataMappingException('constructor_failure', $prefix !== '' ? $prefix : null,
                $exception);
        }
    }

    private static function assertSupported(ReflectionNamedType $type, bool $nested,
        string $field): void
    {
        $name = $type->getName();
        if ($nested) {
            if ($type->isBuiltin() || enum_exists($name) || !class_exists($name)) {
                throw new DataMappingException('invalid_nested', $field);
            }
            return;
        }
        if ($type->isBuiltin()) {
            if (!in_array($name, ['string', 'int', 'float', 'bool', 'array'], true)) {
                throw new DataMappingException('unsupported_type', $field);
            }
            return;
        }
        if (!is_subclass_of($name, BackedEnum::class)) {
            throw new DataMappingException('unsupported_type', $field);
        }
    }

    private static function nested(ReflectionParameter $parameter, string $field): bool
    {
        $found = false;
        foreach ($parameter->getAttributes() as $attribute) {
            if (!is_a($attribute->getName(), NestedData::class, true)) {
                continue;
            }
            if ($found) {
                throw new DataMappingException('invalid_nested', $field);
            }
            // Instantiation validates that the attribute has no unsupported
            // arguments, including when used through the Plugins class alias.
            try {
                $attribute->newInstance();
            } catch (Throwable $exception) {
                throw new DataMappingException('invalid_nested', $field, $exception);
            }
            $found = true;
        }
        return $found;
    }

    private static function convert(#[SensitiveParameter] mixed $value, ReflectionNamedType $type,
        bool $nested, string $field, int $depth): mixed
    {
        if ($value === null) {
            if ($type->allowsNull()) return null;
            throw new DataMappingException('invalid_type', $field);
        }
        $name = $type->getName();
        if ($nested) {
            if (!is_array($value)) {
                throw new DataMappingException('invalid_type', $field);
            }
            return self::construct($name, $value, $field, $depth + 1);
        }
        if (!$type->isBuiltin()) {
            if (!is_subclass_of($name, BackedEnum::class)) {
                throw new DataMappingException('unsupported_type', $field);
            }
            return self::backedEnum($name, $value, $field);
        }
        return match ($name) {
            'string' => is_string($value) ? $value : throw new DataMappingException('invalid_type', $field),
            'int' => self::integer($value, $field),
            'float' => self::float($value, $field),
            'bool' => self::boolean($value, $field),
            'array' => is_array($value) ? $value : throw new DataMappingException('invalid_type', $field),
            default => throw new DataMappingException('unsupported_type', $field),
        };
    }

    private static function integer(#[SensitiveParameter] mixed $value, string $field): int
    {
        if (is_int($value)) return $value;
        if (is_string($value) && preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $value) === 1) {
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            if ($parsed !== false) return $parsed;
        }
        throw new DataMappingException('invalid_type', $field);
    }

    private static function float(#[SensitiveParameter] mixed $value, string $field): float
    {
        if (is_float($value) && is_finite($value)) return $value;
        if (is_int($value) && abs((float) $value) <= 9007199254740991.0) return (float) $value;
        if (is_string($value) && preg_match(
            '/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $value
        ) === 1) {
            $parsed = (float) $value;
            // Textual numbers outside the exactly representable integer range
            // can round a submitted identifier before the constructor sees it.
            if (is_finite($parsed) && abs($parsed) <= 9007199254740991.0) return $parsed;
        }
        throw new DataMappingException('invalid_type', $field);
    }

    private static function boolean(#[SensitiveParameter] mixed $value, string $field): bool
    {
        if (is_bool($value)) return $value;
        if ($value === 1 || $value === '1' || $value === 'true') return true;
        if ($value === 0 || $value === '0' || $value === 'false') return false;
        throw new DataMappingException('invalid_type', $field);
    }

    /** @param class-string<BackedEnum> $class */
    private static function backedEnum(string $class, #[SensitiveParameter] mixed $value,
        string $field): BackedEnum
    {
        $backing = (new ReflectionEnum($class))->getBackingType()?->getName();
        if ($backing === 'int') {
            $value = self::integer($value, $field);
        } elseif ($backing !== 'string' || !is_string($value)) {
            throw new DataMappingException('invalid_type', $field);
        }
        return $class::tryFrom($value) ?? throw new DataMappingException('invalid_enum', $field);
    }

    private static function field(string $prefix, string $name): string
    {
        return $prefix === '' ? $name : $prefix . '.' . $name;
    }
}
