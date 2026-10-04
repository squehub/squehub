<?php

declare(strict_types=1);

/** No application identity is assumed until a project configures one. */
return [
    'default' => null,
    'guards' => [],
    'identities' => [],
    // Token tables are created by an explicit migration. Neither bootstrap nor
    // token guard registration connects to the database or starts Session.
    'tokens' => [
        'driver' => 'database',
        'table' => 'api_tokens',
        'connection' => null,
        'default_ttl' => 2592000,
        'allow_non_expiring' => false,
        'max_ttl' => 31536000,
        'last_used_interval' => 300,
        'prune_retention' => 2592000,
    ],
    'passwords' => [
        'algorithm' => 'default',
        'options' => [],
        'rehash_on_login' => true,
        'max_bytes' => 4096,
    ],
    'browser' => [
        'login_path' => null,
        'authenticated_path' => null,
    ],
    // Optional persistent browser credentials. A login issues one only when
    // the application explicitly passes remember: true to Auth.
    'remember' => [
        'enabled' => false,
        'driver' => 'database',
        'table' => 'remember_tokens',
        'connection' => null,
        'ttl' => 2592000,
        'cookie' => [
            'name' => 'squehub_remember', // Guard name is appended automatically.
            'path' => null, // URL mount when present, otherwise Session path.
            'domain' => null,
            'secure' => null, // Session policy or effective trusted HTTPS.
            'http_only' => true,
            'same_site' => 'Lax',
        ],
    ],
];
