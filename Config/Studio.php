<?php

declare(strict_types=1);

/**
 * Studio is an explicit local development inspector. The HTTP/CLI boundary
 * also requires APP_ENV=development and a loopback listener; APP_DEBUG alone
 * cannot enable it or make it available in production.
 *
 * @var \App\Foundation\Environment $environment
 */
return [
    'enabled' => $environment->boolean('STUDIO_ENABLED', false),
];
