<?php

declare(strict_types=1);

namespace App\Validation;

use App\Database\Database;
use App\Database\Identifier;

/** Table-level uniqueness, including soft-deleted rows. */
final class UniqueRule implements ValidationRule
{
    private mixed $ignored = null;
    private ?string $ignoreColumn = null;

    public function __construct(private string $table, private string $column)
    {
        Identifier::simple($table);
        Identifier::simple($column);
    }

    public function ignore(int|string $key, string $column = 'id'): self
    {
        Identifier::simple($column);
        $copy = clone $this;
        $copy->ignored = $key;
        $copy->ignoreColumn = $column;
        return $copy;
    }

    public function validate(string $field, mixed $value, array $data): ?string
    {
        if (!is_scalar($value)) {
            return 'The ' . str_replace(['_', '.'], [' ', ' '], $field) . ' field is invalid.';
        }
        $query = Database::manager()->table($this->table)->filter($this->column, $value);
        if ($this->ignoreColumn !== null) {
            $query->filter($this->ignoreColumn, '!=', $this->ignored);
        }
        return $query->exists()
            ? 'The ' . str_replace(['_', '.'], [' ', ' '], $field) . ' field is already in use.' : null;
    }

    /** @internal Shared by Validator to batch wildcard uniqueness checks. */
    public function existing(array $values): array
    {
        return DatabaseRuleLookup::matching($values, $this->table, $this->column,
            $this->ignoreColumn, $this->ignored);
    }
}
