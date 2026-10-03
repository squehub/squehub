<?php

declare(strict_types=1);

/** File logging is the default; runtime files stay under ignored Storage/Logs. */
/** @var \App\Foundation\Environment $environment */
return [
    'driver' => $environment->get('LOG_DRIVER', 'file'),
    'level' => $environment->get('LOG_LEVEL', 'info'),
    'path' => null,
    'fail_fast' => false,
];
