<?php

declare(strict_types=1);

use App\Database\Schema\Schema;

/**
 * Upgrade installed Queue tables for explicit failed-job retry. The retained
 * JSON payload is sensitive application data and must be protected as such.
 */
final class AddFailedQueuePayload
{
    public function up(PDO $pdo, Schema $schema): void
    {
        if ($schema->hasTable('queue_failed_jobs') && !$schema->hasColumn('queue_failed_jobs', 'payload')) {
            $pdo->exec('ALTER TABLE `queue_failed_jobs` ADD COLUMN `payload` TEXT NULL');
        }
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        if ($schema->hasTable('queue_failed_jobs') && $schema->hasColumn('queue_failed_jobs', 'payload')) {
            $pdo->exec('ALTER TABLE `queue_failed_jobs` DROP COLUMN `payload`');
        }
    }
}
