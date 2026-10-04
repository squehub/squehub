<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Explicit durable HTTP idempotency state; the scope is a one-way digest. */
final class CreateIdempotencyRecords
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('idempotency_records', static function (Table $table): void {
            $table->id();
            $table->string('scope_hash', 64);
            $table->string('fingerprint', 64);
            $table->string('state', 16);
            $table->string('owner_token', 64)->nullable();
            $table->datetime('lease_until')->nullable();
            $table->datetime('expires_at');
            $table->text('snapshot')->nullable();
            $table->unique('scope_hash', 'idempotency_scope_unique');
            $table->index('expires_at', 'idempotency_expiry');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('idempotency_records');
    }
}
