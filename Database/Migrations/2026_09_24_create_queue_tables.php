<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install the durable Queue tables explicitly with `php squehub migrate`. */
final class CreateQueueTables
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('queue_jobs', static function (Table $table): void {
            $table->id();
            $table->string('queue', 128);
            $table->text('payload');
            $table->integer('attempts');
            $table->datetime('available_at');
            $table->datetime('reserved_at')->nullable();
            $table->string('reservation_token', 64)->nullable();
            $table->datetime('created_at');
            $table->index(['queue', 'available_at', 'reserved_at'], 'queue_jobs_ready');
        });
        $schema->create('queue_failed_jobs', static function (Table $table): void {
            $table->id();
            $table->string('queue', 128);
            $table->string('job_class');
            $table->string('error_type');
            $table->string('error_message');
            $table->integer('attempts');
            $table->datetime('failed_at');
            $table->index('failed_at', 'queue_failed_at');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('queue_failed_jobs');
        $schema->dropIfExists('queue_jobs');
    }
}
