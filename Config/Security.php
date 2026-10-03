<?php

declare(strict_types=1);

/** Optional MFA and browser policies; configure their required keys and deployment settings explicitly. */
return [
    'mfa' => [
        'enabled' => false,
        'driver' => 'database',
        'credentials_table' => 'mfa_credentials',
        'recovery_table' => 'mfa_recovery_codes',
        'connection' => null,
        'issuer' => 'SqueHub',
        'enrollment_ttl' => 600,
        'challenge_ttl' => 300,
        'skew' => 1,
        'recovery_count' => 10,
        'max_attempts' => 5,
        'attempt_window' => 300,
    ],
    // Browser headers are explicit so existing inline Views and HTTP installs
    // keep working until an application selects its production policy.
    'browser' => [
        'enabled' => false,
        'csp' => ['directives' => []],
        'hsts' => [
            'enabled' => false,
            'max_age' => 31536000,
            'include_subdomains' => false,
            'preload' => false,
        ],
        'referrer_policy' => 'strict-origin-when-cross-origin',
        'frame_options' => null,
        'nosniff' => true,
    ],
];
