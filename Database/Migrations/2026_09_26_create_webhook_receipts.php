<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install replay-resistant incoming receipts explicitly through the Migrator. */
final class CreateWebhookReceipts
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('webhook_receipts', static function (Table $table): void {
            $table->id();
            $table->string('source', 128);
            $table->string('event_id', 128);
            $table->string('identity_fingerprint', 64);
            $table->string('state', 16);
            $table->string('claim_token', 64)->nullable();
            $table->datetime('lease_expires_at')->nullable();
            $table->datetime('created_at');
            $table->datetime('updated_at');
            // The digest preserves case-sensitive IDs under MySQL's common
            // case-insensitive string collations.
            $table->unique('identity_fingerprint', 'webhook_receipts_identity');
            $table->index(['state', 'updated_at'], 'webhook_receipts_prune');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('webhook_receipts');
    }
}
