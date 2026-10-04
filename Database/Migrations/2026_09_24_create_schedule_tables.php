<?php

declare(strict_types=1);

use App\Database\Schema\Schema;
use App\Database\Schema\Table;

/** Install hashed run identities and expiring overlap locks explicitly. */
final class CreateScheduleTables
{
    public function up(PDO $pdo, Schema $schema): void
    {
        $schema->create('schedule_runs', static function (Table $table): void {
            $table->id();
            $table->string('task_key', 64);
            $table->datetime('occurrence_at');
            $table->string('status', 16);
            $table->datetime('started_at');
            $table->datetime('finished_at')->nullable();
            $table->unique(['task_key', 'occurrence_at'], 'schedule_runs_occurrence');
        });
        $schema->create('schedule_locks', static function (Table $table): void {
            $table->string('task_key', 64);
            $table->string('token', 64);
            $table->datetime('expires_at');
            $table->unique('task_key', 'schedule_locks_task');
        });
    }

    public function down(PDO $pdo, Schema $schema): void
    {
        $schema->dropIfExists('schedule_locks');
        $schema->dropIfExists('schedule_runs');
    }
}
