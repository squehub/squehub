<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** Explicit foreign-key columns and referential actions for one table. */
final class ForeignKey
{
    private string $deleteAction = 'RESTRICT';
    private string $updateAction = 'RESTRICT';

    /** @param list<string> $columns @param list<string> $referencedColumns */
    public function __construct(
        private string $name,
        private array $columns,
        private string $referencedTable,
        private array $referencedColumns
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function columns(): array
    {
        return $this->columns;
    }

    public function referencedTable(): string
    {
        return $this->referencedTable;
    }

    /** @return list<string> */
    public function referencedColumns(): array
    {
        return $this->referencedColumns;
    }

    public function onDelete(string $action): self
    {
        $this->deleteAction = $this->action($action);
        return $this;
    }

    public function onUpdate(string $action): self
    {
        $this->updateAction = $this->action($action);
        return $this;
    }

    public function deleteAction(): string
    {
        return $this->deleteAction;
    }

    public function updateAction(): string
    {
        return $this->updateAction;
    }

    private function action(string $action): string
    {
        $normalized = strtoupper(str_replace('-', ' ', trim($action)));
        if (!in_array($normalized, ['RESTRICT', 'CASCADE', 'SET NULL'], true)) {
            throw new SchemaException("Foreign key '{$this->name}' has an unsupported action.");
        }
        return $normalized;
    }
}
