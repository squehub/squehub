<?php

declare(strict_types=1);

/**
 * Private router for `php squehub studio`. Never return false: PHP's built-in
 * server would otherwise expose files beneath the document root, including
 * source files that are not Studio assets.
 */
require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Foundation\Application;
use App\Studio\StudioHttp;

$method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? $_SERVER['REQUEST_METHOD'] : '';
$host = is_string($_SERVER['HTTP_HOST'] ?? null) ? $_SERVER['HTTP_HOST'] : '';
$remote = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
$port = filter_var($_SERVER['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT);

// Refuse untrusted peers before loading application configuration or any
// provider. A matching Host header alone cannot authorize a remote client.
if (!StudioHttp::peerAllowed($remote, $host, is_int($port) ? $port : 0)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if ($method !== 'HEAD') echo 'Forbidden.';
    return true;
}

try {
    $uri = $_SERVER['REQUEST_URI'] ?? null;
    if (!is_string($uri) || strlen($uri) > 2048) {
        throw new RuntimeException('Invalid Studio request URI.');
    }
    $path = parse_url($uri, PHP_URL_PATH);
    if (!is_string($path)) {
        throw new RuntimeException('Invalid Studio request path.');
    }

    // The marker is consumed by Bootstrap/App.php before provider boot. Studio
    // never activates Package code and always reads current development config.
    if (!defined('SQUEHUB_STUDIO_INSPECTION_BOOT')) {
        define('SQUEHUB_STUDIO_INSPECTION_BOOT', true);
    }
    $app = require dirname(__DIR__) . '/Bootstrap/App.php';
    if (!$app instanceof Application) {
        throw new RuntimeException('Studio application bootstrap is unavailable.');
    }
    (new StudioHttp($app))->handle($method, $path, $remote, $host, $port)
        ->send($method === 'HEAD');
} catch (Throwable) {
    // This boundary remains private even when APP_DEBUG=true. A broken Studio
    // must not alter or reveal the normal application's exception surface.
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    if ($method !== 'HEAD') echo 'Studio is unavailable.';
}

return true;
