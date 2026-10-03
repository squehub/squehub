<?php

declare(strict_types=1);

/**
 * Network peers and forwarding headers are never trusted by default.
 * Configure only proxy addresses controlled by the application operator.
 */
return [
    'proxies' => [],
    'profile' => 'none', // none, forwarded, or x-forwarded
    'allowed_hosts' => [], // Optional exact hosts or *.example.com subdomains.
];
