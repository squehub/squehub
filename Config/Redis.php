<?php

declare(strict_types=1);

/**
 * Redis is optional. A blank host and URL leave it unconfigured; no Redis
 * client, extension, socket, or server is required for Application bootstrap.
 * URL connection coordinates are exclusive of individual host/auth settings.
 *
 * @var \App\Foundation\Environment $environment
 */
$optional = static fn (mixed $value): mixed => $value === '' ? null : $value;
$url = $optional($environment->get('REDIS_URL'));
$main = $url !== null ? ['url' => $url] : [
    'host' => $optional($environment->get('REDIS_HOST')),
    'port' => $optional($environment->get('REDIS_PORT')) ?? 6379,
    'username' => $optional($environment->get('REDIS_USERNAME')),
    'password' => $optional($environment->get('REDIS_PASSWORD')),
    'database' => $optional($environment->get('REDIS_DATABASE')) ?? 0,
    'tls' => $optional($environment->get('REDIS_TLS')),
];
$main['connect_timeout'] = $optional($environment->get('REDIS_CONNECT_TIMEOUT')) ?? 5;
$main['read_timeout'] = $optional($environment->get('REDIS_READ_TIMEOUT')) ?? 5;
$main['prefix'] = $environment->get('REDIS_PREFIX', '');

return [
    'default' => 'main',
    'client' => $optional($environment->get('REDIS_CLIENT')) ?? 'auto',
    'connections' => ['main' => $main],
];
