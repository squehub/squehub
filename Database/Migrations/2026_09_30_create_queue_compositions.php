<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/**
 * Adds durable composition metadata without replacing installed Queue tables.
 * Dormant chain payloads are sensitive and live only until their step starts,
 * fails, or is cancelled. The application runs this migration explicitly.
 */
final class CreateQueueCompositions
{
    public function up(PDO $pdo, Schema $schema): void
    {
        if (!$schema->hasTable('queue_compositions')) {
            $schema->create('queue_compositions', static function (Table $table): void {
                $table->string('id', 32);
                $table->string('kind', 8);
                $table->string('queue', 128);
                $table->string('state', 32);
                $table->integer('total');
                $table->integer('succeeded');
                $table->integer('failed');
                $table->integer('cancelled');
                $table->datetime('created_at');
                $table->datetime('finished_at')->nullable();
                $table->primary('id');
                $table->index('finished_at', 'queue_compositions_finished');
            });
        }
        if (!$schema->hasTable('queue_composition_items')) {
            $schema->create('queue_composition_items', static function (Table $table): void {
                $table->string('composition_id', 32);
                $table->integer('position');
                $table->string('state', 16);
                $table->foreignId('queue_job_id')->nullable();
                $table->text('payload')->nullable();
                $table->primary(['composition_id', 'position']);
                $table->unique('queue_job_id', 'queue_composition_job_unique');
            });
        }
        // The current portable Schema API creates tables but has no ADD COLUMN.
        // Check each additive operation so interrupted MySQL DDL can be resumed.
        if (!$schema->hasColumn('queue_jobs', 'composition_id')) {
            $pdo->exec('ALTER TABLE `queue_jobs` ADD COLUMN `composition_id` VARCHAR(32) NULL');
        }
        if (!$schema->hasColumn('queue_jobs', 'composition_position')) {
            $pdo->exec('ALTER TABLE `queue_jobs` ADD COLUMN `composition_position` INTEGER NULL');
        }
        if (!$schema->hasIndex('queue_jobs', 'queue_jobs_composition_unique')) {
            $pdo->exec('CREATE UNIQUE INDEX `queue_jobs_composition_unique`'
                . ' ON `queue_jobs` (`composition_id`, `composition_position`)');
        }
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        // An in-flight composition still needs this metadata to fence worker
        // acknowledgements and advance chains. Refuse rollback before any
        // schema change instead of stranding its already queued jobs.
        if ($schema->hasTable('queue_compositions')) {
            $active = $pdo->query("SELECT 1 FROM `queue_compositions`"
                . " WHERE `state` IN ('active', 'cancelling') LIMIT 1")->fetchColumn();
            if ($active !== false) {
                throw new RuntimeException('Cannot roll back Queue composition schema while work is active.');
            }
        }
        if ($schema->hasColumn('queue_jobs', 'composition_id')) {
            $queued = $pdo->query('SELECT 1 FROM `queue_jobs`'
                . ' WHERE `composition_id` IS NOT NULL LIMIT 1')->fetchColumn();
            if ($queued !== false) {
                throw new RuntimeException('Cannot roll back Queue composition schema while work is queued.');
            }
        }
        if ($schema->hasIndex('queue_jobs', 'queue_jobs_composition_unique')) {
            $schema->dropIndex('queue_jobs', 'queue_jobs_composition_unique');
        }
        if ($schema->hasColumn('queue_jobs', 'composition_position')) {
            $pdo->exec('ALTER TABLE `queue_jobs` DROP COLUMN `composition_position`');
        }
        if ($schema->hasColumn('queue_jobs', 'composition_id')) {
            $pdo->exec('ALTER TABLE `queue_jobs` DROP COLUMN `composition_id`');
        }
        $schema->dropIfExists('queue_composition_items');
        $schema->dropIfExists('queue_compositions');
    }
}
