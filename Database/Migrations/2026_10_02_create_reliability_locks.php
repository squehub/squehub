<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** A unique identity and expiry index for portable SQLite/MySQL lock claims. */
final class CreateReliabilityLocks
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('reliability_locks', static function (Table $table): void {
            $table->string('key_hash', 64);
            $table->string('owner_token', 64);
            $table->datetime('expires_at');
            $table->primary('key_hash');
            $table->index('expires_at', 'reliability_locks_expiry');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('reliability_locks');
    }
}
