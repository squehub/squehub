<?php

declare(strict_types=1);

namespace App\Database\Schema;

/** Compiles a validated table definition without opening a database. */
interface SchemaCompiler
{
    /** @return list<string> Fully compiled statements in execution order. */
    public function compileCreate(Table $table): array;

    public function compileDrop(string $table, bool $ifExists = false): string;
}
