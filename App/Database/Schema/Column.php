<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** A portable column definition collected by Table before SQL is generated. */
final class Column
{
    private bool $nullable = false;
    private bool $hasDefault = false;
    private mixed $default = null;

    public function __construct(
        private string $name,
        private string $type,
        private ?int $length = null
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function length(): ?int
    {
        return $this->length;
    }

    public function nullable(bool $nullable = true): self
    {
        if ($this->type === 'id' && $nullable) {
            throw new SchemaException("Generated key '{$this->name}' cannot be nullable.");
        }
        $this->nullable = $nullable;
        return $this;
    }

    public function isNullable(): bool
    {
        return $this->nullable;
    }

    public function default(mixed $value): self
    {
        if ($this->type === 'id') {
            throw new SchemaException("Generated key '{$this->name}' cannot have a default.");
        }
        if (!is_null($value) && !is_bool($value) && !is_int($value) && !is_float($value) && !is_string($value)) {
            throw new SchemaException("Column '{$this->name}' has an unsupported default literal.");
        }
        $this->default = $value;
        $this->hasDefault = true;
        return $this;
    }

    public function hasDefault(): bool
    {
        return $this->hasDefault;
    }

    public function defaultValue(): mixed
    {
        return $this->default;
    }
}
