<?php

declare(strict_types=1);

namespace App\Database\Schema;

use App\Database\Identifier;

/** Collects a complete portable CREATE TABLE definition before execution. */
final class Table
{
    /** @var array<string, Column> */
    private array $columns = [];
    /** @var list<string> */
    private array $primary = [];
    /** @var list<array{name: string, columns: list<string>, unique: bool}> */
    private array $indexes = [];
    /** @var list<ForeignKey> */
    private array $foreignKeys = [];

    public function __construct(private string $name)
    {
        self::name($name);
    }

    public function tableName(): string
    {
        return $this->name;
    }

    public function id(string $name = 'id'): Column
    {
        return $this->add($name, 'id');
    }

    public function integer(string $name): Column
    {
        return $this->add($name, 'integer');
    }

    /** Matches the physical type of id() on both supported drivers. */
    public function foreignId(string $name): Column
    {
        return $this->add($name, 'foreign_id');
    }

    public function string(string $name, int $length = 255): Column
    {
        if ($length < 1 || $length > 255) {
            throw new SchemaException("String column '{$name}' requires a length from 1 to 255.");
        }
        return $this->add($name, 'string', $length);
    }

    public function text(string $name): Column
    {
        return $this->add($name, 'text');
    }

    public function boolean(string $name): Column
    {
        return $this->add($name, 'boolean');
    }

    public function datetime(string $name): Column
    {
        return $this->add($name, 'datetime');
    }

    /** @param string|list<string> $columns */
    public function primary(string|array $columns): void
    {
        if ($this->primary !== []) {
            throw new SchemaException("Table '{$this->name}' already has a primary key.");
        }
        $this->primary = self::columnList($columns);
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $this->addIndex($columns, $name, true);
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $this->addIndex($columns, $name, false);
    }

    /**
     * @param string|list<string> $columns
     * @param string|list<string> $referencedColumns
     */
    public function foreign(
        string|array $columns,
        string $referencedTable,
        string|array $referencedColumns,
        ?string $name = null
    ): ForeignKey {
        $local = self::columnList($columns);
        $referenced = self::columnList($referencedColumns);
        self::name($referencedTable);
        if (count($local) !== count($referenced)) {
            throw new SchemaException("Foreign key on '{$this->name}' requires matching column counts.");
        }
        $name ??= $this->generatedName($local, 'foreign');
        self::name($name);
        $key = new ForeignKey($name, $local, $referencedTable, $referenced);
        $this->foreignKeys[] = $key;
        return $key;
    }

    /** @return list<Column> */
    public function columns(): array
    {
        return array_values($this->columns);
    }

    /** @return list<string> */
    public function primaryColumns(): array
    {
        return $this->primary;
    }

    /** @return list<array{name: string, columns: list<string>, unique: bool}> */
    public function indexes(): array
    {
        return $this->indexes;
    }

    /** @return list<ForeignKey> */
    public function foreignKeys(): array
    {
        return $this->foreignKeys;
    }

    public function validate(): void
    {
        if ($this->columns === []) {
            throw new SchemaException("Table '{$this->name}' requires at least one column.");
        }

        $generated = array_filter($this->columns, static fn (Column $column): bool => $column->type() === 'id');
        if (count($generated) > 1 || ($generated !== [] && $this->primary !== [])) {
            throw new SchemaException("Table '{$this->name}' has conflicting primary keys.");
        }
        foreach ($this->columns as $column) {
            $this->validateDefault($column);
        }
        foreach ($this->primary as $name) {
            $column = $this->requireColumn($name);
            if ($column->isNullable()) {
                throw new SchemaException("Primary column '{$name}' cannot be nullable.");
            }
            if ($column->type() === 'text') {
                throw new SchemaException("Text column '{$name}' cannot be a primary key in the portable schema API.");
            }
        }

        $constraintNames = [];
        foreach ($this->indexes as $index) {
            $this->uniqueConstraintName($index['name'], $constraintNames);
            foreach ($index['columns'] as $name) {
                if ($this->requireColumn($name)->type() === 'text') {
                    throw new SchemaException("Text column '{$name}' cannot be indexed by the portable schema API.");
                }
            }
        }
        foreach ($this->foreignKeys as $key) {
            $this->uniqueConstraintName($key->name(), $constraintNames);
            foreach ($key->columns() as $name) {
                $column = $this->requireColumn($name);
                if ($column->type() !== 'foreign_id') {
                    throw new SchemaException("Foreign key column '{$name}' must be declared with foreignId().");
                }
                if (($key->deleteAction() === 'SET NULL' || $key->updateAction() === 'SET NULL') && !$column->isNullable()) {
                    throw new SchemaException("Foreign key column '{$name}' must be nullable for SET NULL.");
                }
            }
        }
    }

    public static function name(string $name): string
    {
        Identifier::simple($name);
        if (strlen($name) > 64) {
            throw new SchemaException('Schema identifiers cannot exceed 64 characters.');
        }
        return $name;
    }

    private function add(string $name, string $type, ?int $length = null): Column
    {
        self::name($name);
        foreach ($this->columns as $existing) {
            if (strcasecmp($existing->name(), $name) === 0) {
                throw new SchemaException("Table '{$this->name}' repeats column '{$name}'.");
            }
        }
        $column = new Column($name, $type, $length);
        $this->columns[$name] = $column;
        return $column;
    }

    /** @param string|list<string> $columns */
    private function addIndex(string|array $columns, ?string $name, bool $unique): void
    {
        $columns = self::columnList($columns);
        $name ??= $this->generatedName($columns, $unique ? 'unique' : 'index');
        self::name($name);
        if (strcasecmp($name, 'PRIMARY') === 0) {
            throw new SchemaException("Index name 'PRIMARY' is reserved.");
        }
        $this->indexes[] = ['name' => $name, 'columns' => $columns, 'unique' => $unique];
    }

    /** @param string|list<string> $columns @return list<string> */
    private static function columnList(string|array $columns): array
    {
        $names = is_string($columns) ? [$columns] : array_values($columns);
        if ($names === []) {
            throw new SchemaException('A schema constraint requires at least one column.');
        }
        $seen = [];
        foreach ($names as $name) {
            if (!is_string($name)) {
                throw new SchemaException('A schema constraint contains an invalid column.');
            }
            self::name($name);
            $key = strtolower($name);
            if (isset($seen[$key])) {
                throw new SchemaException("Schema constraint repeats column '{$name}'.");
            }
            $seen[$key] = true;
        }
        return $names;
    }

    /** @param list<string> $columns */
    private function generatedName(array $columns, string $suffix): string
    {
        return self::name($this->name . '_' . implode('_', $columns) . '_' . $suffix);
    }

    private function requireColumn(string $name): Column
    {
        foreach ($this->columns as $column) {
            if (strcasecmp($column->name(), $name) === 0) {
                return $column;
            }
        }
        throw new SchemaException("Table '{$this->name}' has no column '{$name}' for its constraint.");
    }

    /** @param array<string, bool> $names */
    private function uniqueConstraintName(string $name, array &$names): void
    {
        $key = strtolower($name);
        if (isset($names[$key])) {
            throw new SchemaException("Table '{$this->name}' repeats constraint '{$name}'.");
        }
        $names[$key] = true;
    }

    private function validateDefault(Column $column): void
    {
        if (!$column->hasDefault()) {
            return;
        }
        $value = $column->defaultValue();
        $type = $column->type();
        if ($value === null && !$column->isNullable()) {
            throw new SchemaException("Column '{$column->name()}' requires nullable() for a null default.");
        }
        if ($value !== null && (
            ($type === 'text') ||
            (($type === 'integer' || $type === 'foreign_id') && !is_int($value)) ||
            ($type === 'foreign_id' && is_int($value) && $value < 0) ||
            ($type === 'boolean' && !is_bool($value)) ||
            (($type === 'string' || $type === 'datetime') && !is_string($value))
        )) {
            throw new SchemaException("Column '{$column->name()}' has an incompatible default literal.");
        }
        if (is_string($value) && preg_match('/[\x00-\x1F\\\\]/', $value) === 1) {
            // MySQL backslash escaping depends on SQL mode; exclude ambiguous literals.
            throw new SchemaException("Column '{$column->name()}' has an unsupported default literal.");
        }
    }
}
