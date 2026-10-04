<?php

declare(strict_types=1);

namespace App\View;

use App\Core\ViewEscaper;
use Stringable;

/**
 * Immutable HTML attributes supplied separately from component props.
 * Names are constrained before markup generation and every text value is
 * escaped in attribute context; no raw invocation value becomes HTML syntax.
 */
final class ComponentAttributeBag
{
    /** @var array<string, string|int|float|Stringable|bool|null> */
    private readonly array $attributes;

    /** @param array<array-key, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        foreach ($attributes as $name => $value) {
            if (!is_string($name)
                || preg_match('/\A[A-Za-z][A-Za-z0-9_.:-]*\z/D', $name) !== 1) {
                throw new ComponentException('A component HTML attribute name is invalid.');
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)
                && !is_bool($value) && $value !== null && !$value instanceof Stringable) {
                throw new ComponentException('A component HTML attribute value has an unsupported type.');
            }
        }
        $this->attributes = $attributes;
    }

    /** @return array<string, string|int|float|Stringable|bool|null> */
    public function all(): array
    {
        return $this->attributes;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->attributes);
    }

    public function get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    /** @param list<string> $names */
    public function only(array $names): self
    {
        return new self(array_intersect_key($this->attributes, array_flip($names)));
    }

    /** @param list<string> $names */
    public function except(array $names): self
    {
        return new self(array_diff_key($this->attributes, array_flip($names)));
    }

    /**
     * Component defaults are emitted first. Invocation values override a
     * matching default without implicit class or style concatenation.
     *
     * @param array<array-key, mixed> $defaults
     */
    public function merge(array $defaults): self
    {
        return new self(array_replace((new self($defaults))->all(), $this->attributes));
    }

    public function toHtml(): string
    {
        $parts = [];
        foreach ($this->attributes as $name => $value) {
            if ($value === false || $value === null) {
                continue;
            }
            $parts[] = $value === true
                ? $name
                : $name . '="' . ViewEscaper::escape((string) $value) . '"';
        }
        return implode(' ', $parts);
    }
}
