<?php

declare(strict_types=1);

/** Map browser session settings without opening a session during bootstrap. */
/** @var \App\Foundation\Environment $environment */
return [
    'driver' => $environment->get('SESSION_DRIVER', 'native'),
    'redis_connection' => $environment->get('SESSION_REDIS_CONNECTION'),
    'redis_namespace' => $environment->get('SESSION_REDIS_NAMESPACE') ?: null,
    'name' => $environment->get('SESSION_NAME', 'squehub_session'),
    'lifetime' => $environment->get('SESSION_LIFETIME', 120), // Minutes; 0 is a browser-session cookie.
    'path' => $environment->get('SESSION_PATH', '/'),
    'domain' => $environment->get('SESSION_DOMAIN'),
    'secure' => $environment->boolean('SESSION_SECURE', false),
    'http_only' => $environment->boolean('SESSION_HTTP_ONLY', true),
    'same_site' => $environment->get('SESSION_SAME_SITE', 'Lax'),
    'strict_mode' => $environment->boolean('SESSION_STRICT_MODE', true),
];
