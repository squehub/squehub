<?php

declare(strict_types=1);

/**
 * Mail transport settings are environment sourced and safe to load before
 * credentials exist. A missing SMTP host or sender is reported at send time.
 *
 * @var \App\Foundation\Environment $environment
 */
$mailPort = $environment->get('MAIL_PORT', '587');
$mailTimeout = $environment->get('MAIL_TIMEOUT', '10');
$optional = static fn (mixed $value): mixed => $value === '' ? null : $value;

return [
    'default' => $environment->get('MAIL_TRANSPORT', 'smtp'),
    'from' => [
        'address' => $optional($environment->get('MAIL_FROM_ADDRESS')),
        'name' => $optional($environment->get('MAIL_FROM_NAME')),
    ],
    'transports' => [
        'smtp' => [
            'driver' => 'smtp',
            'host' => $optional($environment->get('MAIL_HOST')),
            'port' => ctype_digit((string) $mailPort) ? (int) $mailPort : $mailPort,
            'encryption' => $environment->get('MAIL_ENCRYPTION', 'tls'),
            'username' => $optional($environment->get('MAIL_USERNAME')),
            'password' => $optional($environment->get('MAIL_PASSWORD')),
            'timeout' => ctype_digit((string) $mailTimeout) ? (int) $mailTimeout : $mailTimeout,
            'verify_peer' => $environment->boolean('MAIL_VERIFY_PEER', true),
            'allow_self_signed' => $environment->boolean('MAIL_ALLOW_SELF_SIGNED', false),
        ],
        'array' => ['driver' => 'array'],
        // API transports use fixed provider HTTPS endpoints. Credentials are
        // optional at boot and required only when a message is sent through one.
        'resend' => [
            'driver' => 'resend',
            'api_key' => $optional($environment->get('RESEND_API_KEY')),
            'timeout' => ctype_digit((string) $mailTimeout) ? (int) $mailTimeout : $mailTimeout,
        ],
        'postmark' => [
            'driver' => 'postmark',
            'api_key' => $optional($environment->get('POSTMARK_SERVER_TOKEN')),
            'timeout' => ctype_digit((string) $mailTimeout) ? (int) $mailTimeout : $mailTimeout,
        ],
    ],
];
