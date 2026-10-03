<?php

declare(strict_types=1);

/** Roles and permissions remain opt-in; install their migration explicitly. */
return [
    'enabled' => false,
    'driver' => 'database',
    'connection' => null,
];
