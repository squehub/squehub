<?php

declare(strict_types=1);

/** Scheduler definitions are code; this file selects only time and lock storage. */
/** @var \App\Foundation\Environment $environment */
return [
    'timezone' => $environment->get('APP_TIMEZONE', 'UTC'),
    'prefix' => $environment->get('SCHEDULE_PREFIX', 'squehub'),
    'store' => 'database',
    'database_connection' => $environment->get('SCHEDULE_DATABASE_CONNECTION'),
    'runs_table' => 'schedule_runs',
    'locks_table' => 'schedule_locks',
];
