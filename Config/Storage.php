<?php

declare(strict_types=1);

/** Application files default to Storage/Files, separate from Logs and Cache. */
/** @var \App\Foundation\Environment $environment */
return [
    'default' => $environment->get('STORAGE_DRIVE', 'local'),
    'drives' => [
        'local' => ['driver' => 'local', 'root' => null],
        // Inert until selected. The AWS SDK is an optional Composer install.
        's3' => [
            'driver' => 's3',
            'bucket' => $environment->get('STORAGE_S3_BUCKET'),
            'region' => $environment->get('STORAGE_S3_REGION', 'us-east-1'),
            'endpoint' => $environment->get('STORAGE_S3_ENDPOINT'),
            'access_key' => $environment->get('STORAGE_S3_ACCESS_KEY'),
            'secret_key' => $environment->get('STORAGE_S3_SECRET_KEY'),
            'session_token' => $environment->get('STORAGE_S3_SESSION_TOKEN'),
            'prefix' => $environment->get('STORAGE_S3_PREFIX', ''),
            'path_style' => $environment->get('STORAGE_S3_PATH_STYLE', false),
        ],
    ],
];
