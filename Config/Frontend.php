<?php

declare(strict_types=1);

/**
 * Optional frontend composition. Ordinary PHP and native modules need no
 * build adapter, Node process, manifest, or development-server probe.
 * Profiles change these explicit settings through a reviewed Change Plan.
 *
 * @var \App\Foundation\Environment $environment
 */
return [
    'adapter' => 'none',
    'source' => 'Project/Frontend',
    'entries' => [],
    'build' => [
        'directory' => 'public/assets/build',
        'manifest' => '.vite/manifest.json',
    ],
    'development' => ['enabled' => false, 'url' => $environment->get(
        'SQUEHUB_FRONTEND_DEV_URL', 'http://127.0.0.1:5173'
    )],
    'imports' => [],
    'spa' => ['enabled' => false, 'prefix' => '/', 'view' => null, 'except' => []],
];
