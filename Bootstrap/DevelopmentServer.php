<?php

declare(strict_types=1);

/** Route local development requests without exposing files outside public/. */
$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$publicRoot = realpath(dirname(__DIR__) . '/public');
$assetsRoot = realpath(dirname(__DIR__) . '/public/assets');

// The built-in server cannot map /app/assets to public/assets by itself.
// Read the same Config/Http.php mount as Application for static requests;
// the router must not invent a second deployment-path policy.
$mount = '';
if (in_array($method, ['GET', 'HEAD'], true) && is_string($path)) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    try {
        $environment = new \App\Foundation\Environment(dirname(__DIR__));
        $configuration = require dirname(__DIR__) . '/Config/Http.php';
        $configured = is_array($configuration) ? ($configuration['base_path'] ?? '') : null;
        if (!is_string($configured)) throw new \InvalidArgumentException('Invalid URL mount.');
        $mount = (new \App\Foundation\UrlBasePath($configured))->value();
    } catch (\Throwable) {
        // Let the application entry point report invalid configuration through
        // its normal safe error boundary rather than serving a guessed asset.
        $mount = null;
    }
}
$assetPath = is_string($path) && is_string($mount) && $mount !== ''
    ? (new \App\Foundation\UrlBasePath($mount))->strip($path)
    : ($mount === null ? null : $path);

// Only existing, ordinary static assets may bypass the application entry
// point. Reject dot-leading segments, encoded paths and executable files.
if (in_array($method, ['GET', 'HEAD'], true)
    && is_string($assetPath)
    // Package assets are authorized by Application activation on every
    // request. A colliding public file must never bypass that check.
    && !str_starts_with($assetPath, '/assets/Packages/')
    && preg_match('~\A/assets/(?:[A-Za-z0-9_-][A-Za-z0-9._-]*/)*[A-Za-z0-9_-][A-Za-z0-9._-]*\.(?:css|js|mjs|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|otf|eot|json|webmanifest|map|txt|xml)\z~iD', $assetPath) === 1
    && $publicRoot !== false
    && $assetsRoot !== false
    && str_starts_with($assetsRoot, $publicRoot . DIRECTORY_SEPARATOR)) {
    $asset = realpath(dirname(__DIR__) . '/public' . $assetPath);
    if ($asset !== false
        && str_starts_with($asset, $assetsRoot . DIRECTORY_SEPARATOR)
        && is_file($asset)) {
        if ($mount === '') return false;

        // A mounted URL has no matching physical file under the built-in
        // server's document root. Stream only the already validated asset.
        $extension = strtolower(pathinfo($asset, PATHINFO_EXTENSION));
        $types = [
            'css' => 'text/css', 'js' => 'text/javascript', 'mjs' => 'text/javascript',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
            'webp' => 'image/webp', 'avif' => 'image/avif',
            'woff' => 'font/woff', 'woff2' => 'font/woff2',
            'ttf' => 'font/ttf', 'otf' => 'font/otf', 'eot' => 'application/vnd.ms-fontobject',
            'json' => 'application/json', 'webmanifest' => 'application/manifest+json',
            'map' => 'application/json', 'txt' => 'text/plain', 'xml' => 'application/xml',
        ];
        header('Content-Type: ' . $types[$extension]);
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . (string) filesize($asset));
        if ($method === 'GET') readfile($asset);
        return true;
    }
}

require dirname(__DIR__) . '/public/index.php';
