<?php

declare(strict_types=1);

namespace App\Api\Contract;

use InvalidArgumentException;
use JsonException;
use SensitiveParameter;

/**
 * An immutable, SqueHub-native JSON Schema declaration.
 *
 * Only the supported Draft 2020-12 vocabulary is emitted. Nested schemas are
 * captured as values when declared, so later declarations cannot mutate an
 * operation's schema through a shared PHP object or array reference.
 */
final class Schema
{
    private const MAX_NESTING = 32;
    private const MAX_VALUES = 10000;

    private function __construct(private array|bool $value)
    {
    }

    public static function string(): self { return new self(['type' => 'string']); }
    public static function integer(): self { return new self(['type' => 'integer']); }
    public static function number(): self { return new self(['type' => 'number']); }
    public static function boolean(): self { return new self(['type' => 'boolean']); }
    public static function null(): self { return new self(['type' => 'null']); }
    public static function true(): self { return new self(true); }
    public static function false(): self { return new self(false); }

    /** @param array<string, self> $properties */
    public static function object(array $properties = []): self
    {
        $compiled = [];
        foreach ($properties as $name => $schema) {
            if (!is_string($name) || $name === '' || strlen($name) > 128
                || preg_match('/[\x00-\x1f\x7f]/', $name) === 1 || !$schema instanceof self) {
                throw new InvalidArgumentException('Object properties require bounded names and Schema values.');
            }
            $compiled[$name] = $schema->toArray();
        }
        ksort($compiled, SORT_STRING);
        return new self($compiled === [] ? ['type' => 'object']
            : ['type' => 'object', 'properties' => $compiled]);
    }

    public static function array(self $items): self
    {
        return new self(['type' => 'array', 'items' => $items->toArray()]);
    }

    /** @param list<mixed> $values */
    public static function enum(#[SensitiveParameter] array $values): self
    {
        if ($values === [] || !array_is_list($values)) {
            throw new InvalidArgumentException('Schema enum values must be a nonempty list.');
        }
        $values = self::jsonValue($values);
        $seen = [];
        foreach ($values as $value) {
            $key = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('Schema enum values must be distinct.');
            }
            $seen[$key] = true;
        }
        return new self(['enum' => $values]);
    }

    /** @param list<self> $schemas */
    public static function oneOf(array $schemas): self { return self::compose('oneOf', $schemas); }
    /** @param list<self> $schemas */
    public static function anyOf(array $schemas): self { return self::compose('anyOf', $schemas); }
    /** @param list<self> $schemas */
    public static function allOf(array $schemas): self { return self::compose('allOf', $schemas); }

    public static function ref(string $name): self
    {
        if (strlen($name) > 128 || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $name) !== 1) {
            throw new InvalidArgumentException('Schema reference name must be a bounded component identifier.');
        }
        // Component names cannot contain JSON Pointer separators, so no
        // escaping or remote reference resolution is needed here.
        return new self(['$ref' => '#/components/schemas/' . $name]);
    }

    /** @param list<string> $names */
    public function required(array $names): self
    {
        $this->requireType('object', 'required');
        if (!array_is_list($names)) {
            throw new InvalidArgumentException('Required property names must be a list.');
        }
        $properties = $this->value['properties'] ?? [];
        $required = $this->value['required'] ?? [];
        foreach ($names as $name) {
            if (!is_string($name) || !array_key_exists($name, $properties)) {
                throw new InvalidArgumentException('A required property must be declared on the object schema.');
            }
            $required[] = $name;
        }
        $copy = clone $this;
        $required = array_values(array_unique($required));
        sort($required, SORT_STRING);
        if ($required !== []) $copy->value['required'] = $required;
        return $copy;
    }

    /** Optional and nullable are independent: this adds null to valid values. */
    public function nullable(): self
    {
        if (is_bool($this->value)) {
            throw new InvalidArgumentException('Boolean schemas do not support nullable().');
        }
        $copy = clone $this;
        if (isset($copy->value['type'])) {
            $types = (array) $copy->value['type'];
            if (!in_array('null', $types, true)) $types[] = 'null';
            $copy->value['type'] = count($types) === 1 ? $types[0] : $types;
        } elseif (isset($copy->value['enum'])) {
            if (!in_array(null, $copy->value['enum'], true)) $copy->value['enum'][] = null;
        } elseif (!self::permitsNull($copy->value)) {
            // A reference or compound schema may have constraints that are
            // independent of `type`; wrap it rather than attaching an invalid
            // sibling `type` to a referenced schema.
            $copy->value = ['anyOf' => [$copy->value, ['type' => 'null']]];
        }
        return $copy;
    }

    public function description(string $description): self
    {
        if (strlen($description) > 8192 || !self::isUtf8($description)) {
            throw new InvalidArgumentException('Schema description must be bounded UTF-8 text.');
        }
        return $this->with('description', $description);
    }

    public function format(string $format): self
    {
        $this->requireType('string', 'format');
        if (strlen($format) > 64 || preg_match('/\A[A-Za-z][A-Za-z0-9._-]*\z/D', $format) !== 1) {
            throw new InvalidArgumentException('Schema format must be a bounded identifier.');
        }
        return $this->with('format', $format);
    }

    /** @param list<mixed> $examples */
    public function examples(#[SensitiveParameter] array $examples): self
    {
        if (!array_is_list($examples)) {
            throw new InvalidArgumentException('Schema examples must be a list.');
        }
        return $this->with('examples', self::jsonValue($examples));
    }

    public function default(#[SensitiveParameter] mixed $value): self
    {
        return $this->with('default', self::jsonValue($value));
    }

    public function deprecated(): self { return $this->with('deprecated', true); }

    public function readOnly(): self
    {
        if (!is_bool($this->value) && ($this->value['writeOnly'] ?? false)) {
            throw new InvalidArgumentException('A schema cannot be both read-only and write-only.');
        }
        return $this->with('readOnly', true);
    }

    public function writeOnly(): self
    {
        if (!is_bool($this->value) && ($this->value['readOnly'] ?? false)) {
            throw new InvalidArgumentException('A schema cannot be both read-only and write-only.');
        }
        return $this->with('writeOnly', true);
    }

    public function minimum(int|float $value): self { return $this->numericBound('minimum', $value); }
    public function maximum(int|float $value): self { return $this->numericBound('maximum', $value); }
    public function exclusiveMinimum(int|float $value): self { return $this->numericBound('exclusiveMinimum', $value); }
    public function exclusiveMaximum(int|float $value): self { return $this->numericBound('exclusiveMaximum', $value); }
    public function minLength(int $value): self { return $this->sizeBound('string', 'minLength', $value); }
    public function maxLength(int $value): self { return $this->sizeBound('string', 'maxLength', $value); }
    public function minItems(int $value): self { return $this->sizeBound('array', 'minItems', $value); }
    public function maxItems(int $value): self { return $this->sizeBound('array', 'maxItems', $value); }

    public function pattern(string $pattern): self
    {
        $this->requireType('string', 'pattern');
        // JSON Schema patterns use the ECMA-262 dialect. PCRE compilation here
        // would incorrectly reject some valid patterns and imply full parity.
        if (strlen($pattern) > 1024 || !self::isUtf8($pattern)) {
            throw new InvalidArgumentException('Schema pattern must be bounded UTF-8 text.');
        }
        return $this->with('pattern', $pattern);
    }

    public function uniqueItems(bool $unique): self
    {
        $this->requireType('array', 'uniqueItems');
        return $this->with('uniqueItems', $unique);
    }

    public function additionalProperties(bool|self $value): self
    {
        $this->requireType('object', 'additionalProperties');
        return $this->with('additionalProperties', $value instanceof self ? $value->toArray() : $value);
    }

    /** @return array<string,mixed>|bool */
    public function toArray(): array|bool
    {
        return $this->value;
    }

    /** @internal Rehydrate validated JSON-only route-cache declarations. */
    public static function fromCacheArray(array|bool $value): self
    {
        $copy = self::jsonValue($value);
        if (!is_array($copy) && !is_bool($copy)) {
            throw new InvalidArgumentException('Cached Schema must be JSON object or boolean.');
        }
        return new self($copy);
    }

    /** @param list<self> $schemas */
    private static function compose(string $keyword, array $schemas): self
    {
        if ($schemas === [] || !array_is_list($schemas)) {
            throw new InvalidArgumentException('Schema composition needs a nonempty list.');
        }
        $parts = [];
        foreach ($schemas as $schema) {
            if (!$schema instanceof self) {
                throw new InvalidArgumentException('Schema composition accepts Schema values only.');
            }
            $parts[] = $schema->toArray();
        }
        return new self([$keyword => $parts]);
    }

    private function numericBound(string $keyword, int|float $value): self
    {
        $this->requireNumericType($keyword);
        if (is_float($value) && !is_finite($value)) {
            throw new InvalidArgumentException('Numeric schema bounds must be finite.');
        }
        $copy = $this->with($keyword, $value);
        $minimum = $copy->value['minimum'] ?? -INF;
        $exclusiveMinimum = $copy->value['exclusiveMinimum'] ?? -INF;
        $maximum = $copy->value['maximum'] ?? INF;
        $exclusiveMaximum = $copy->value['exclusiveMaximum'] ?? INF;
        $lower = max($minimum, $exclusiveMinimum);
        $upper = min($maximum, $exclusiveMaximum);
        $lowerExclusive = isset($copy->value['exclusiveMinimum']) && $exclusiveMinimum >= $minimum;
        $upperExclusive = isset($copy->value['exclusiveMaximum']) && $exclusiveMaximum <= $maximum;
        if ($lower > $upper || ($lower === $upper && ($lowerExclusive || $upperExclusive))) {
            throw new InvalidArgumentException('Numeric schema bounds cannot exclude all values.');
        }
        if (in_array('integer', (array) ($copy->value['type'] ?? []), true)) {
            $first = $lowerExclusive ? floor($lower) + 1 : ceil($lower);
            $last = $upperExclusive ? ceil($upper) - 1 : floor($upper);
            if ($first > $last) {
                throw new InvalidArgumentException('Integer schema bounds cannot exclude all whole numbers.');
            }
        }
        return $copy;
    }

    private function sizeBound(string $type, string $keyword, int $value): self
    {
        $this->requireType($type, $keyword);
        if ($value < 0) {
            throw new InvalidArgumentException('Schema size bounds cannot be negative.');
        }
        $copy = $this->with($keyword, $value);
        $lower = $copy->value[$type === 'array' ? 'minItems' : 'minLength'] ?? 0;
        $upper = $copy->value[$type === 'array' ? 'maxItems' : 'maxLength'] ?? PHP_INT_MAX;
        if ($lower > $upper) {
            throw new InvalidArgumentException('Schema minimum size cannot exceed its maximum size.');
        }
        return $copy;
    }

    private function requireType(string $type, string $keyword): void
    {
        if (is_bool($this->value) || !in_array($type, (array) ($this->value['type'] ?? []), true)) {
            throw new InvalidArgumentException("{$keyword} requires a {$type} schema.");
        }
    }

    private function requireNumericType(string $keyword): void
    {
        if (is_bool($this->value) || !array_intersect(['integer', 'number'], (array) ($this->value['type'] ?? []))) {
            throw new InvalidArgumentException("{$keyword} requires a numeric schema.");
        }
    }

    private function with(string $keyword, mixed $value): self
    {
        if (is_bool($this->value)) {
            throw new InvalidArgumentException('Boolean schemas cannot carry keyword declarations.');
        }
        $copy = clone $this;
        $copy->value[$keyword] = $value;
        return $copy;
    }

    /** JSON-only values prevent arbitrary PHP objects from entering artifacts. */
    private static function jsonValue(#[SensitiveParameter] mixed $value): mixed
    {
        $count = 0;
        self::validateJsonValue($value, 0, $count);
        try {
            return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Schema values must be valid JSON data.', 0, $exception);
        }
    }

    private static function validateJsonValue(#[SensitiveParameter] mixed $value, int $depth, int &$count): void
    {
        if ($depth > self::MAX_NESTING || ++$count > self::MAX_VALUES) {
            throw new InvalidArgumentException('Schema value exceeds supported bounds.');
        }
        if (is_array($value)) {
            foreach ($value as $item) self::validateJsonValue($item, $depth + 1, $count);
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) return;
        throw new InvalidArgumentException('Schema values must contain JSON scalars and arrays only.');
    }

    private static function permitsNull(array $schema): bool
    {
        if (isset($schema['anyOf'])) {
            foreach ($schema['anyOf'] as $branch) {
                if ($branch === ['type' => 'null']) return true;
            }
        }
        return false;
    }

    private static function isUtf8(string $value): bool
    {
        return json_encode($value) !== false;
    }
}
