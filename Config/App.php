<?php

declare(strict_types=1);

/** Convert APP_* environment values into application identity and debug policy. */
/** @var \App\Foundation\Environment $environment */
$applicationEnvironment = $environment->get('APP_ENV', 'development');
return [
    'name' => $environment->get('APP_NAME', 'SqueHub'),
    'env' => $applicationEnvironment,
    'debug' => $environment->boolean('APP_DEBUG', false),
];
