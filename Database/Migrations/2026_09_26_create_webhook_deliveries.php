<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install outgoing webhook delivery metadata explicitly through the Migrator. */
final class CreateWebhookDeliveries
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('webhook_deliveries', static function (Table $table): void {
            $table->id();
            $table->string('event_id', 64);
            $table->string('event_fingerprint', 64);
            $table->string('delivery_id', 64);
            $table->string('delivery_fingerprint', 64);
            $table->string('endpoint_name', 128);
            $table->string('state', 16);
            $table->integer('attempt_count');
            $table->datetime('next_attempt_at')->nullable();
            $table->datetime('completed_at')->nullable();
            $table->integer('last_http_status')->nullable();
            $table->string('last_failure_category', 32)->nullable();
            $table->datetime('created_at');
            $table->datetime('updated_at');
            // A lowercase digest keeps delivery IDs case-sensitive even when
            // the MySQL database uses a case-insensitive text collation.
            $table->unique('delivery_fingerprint', 'webhook_deliveries_identity');
            $table->index(['state', 'next_attempt_at'], 'webhook_deliveries_retry');
            $table->index(['state', 'completed_at'], 'webhook_deliveries_prune');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('webhook_deliveries');
    }
}
