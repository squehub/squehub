<?php

declare(strict_types=1);

/**
 * Opt into public API error formatting for literal path prefixes. A scope never
 * disables CSRF or configures authentication. Version metadata and browser
 * cross-origin access have separate, explicit policies below. Health's enabled
 * built-in endpoints retain their own minimal response contract.
 */
return [
    'enabled' => false,
    'paths' => ['/api'],
    'versioning' => [
        'strategy' => 'uri',
        'header' => 'X-API-Version',
    ],
    'cors' => [
        'enabled' => false,
        'paths' => ['/api'],
        'allowed_origins' => [],
        'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'],
        'allowed_headers' => [],
        'exposed_headers' => [],
        'allow_credentials' => false,
        'max_age' => 600,
    ],
];
