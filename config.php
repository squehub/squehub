<?php

declare(strict_types=1);

use App\Foundation\Application;

require_once __DIR__ . '/vendor/autoload.php';

// Compatibility adapter for callers that still require the root config.php.
// The Application owns environment and configuration loading.
if (!isset($squehubApp) || !$squehubApp instanceof Application) {
    $squehubApp = require __DIR__ . '/Bootstrap/App.php';
}
if (!$squehubApp->isBooted()) {
    $squehubApp->bootstrap();
}
if (!defined('BASE_DIR')) {
    define('BASE_DIR', $squehubApp->basePath());
}
if (!defined('DEBUG_MODE')) {
    define('DEBUG_MODE', $squehubApp->isDebug());
}

$config = [
    'database' => $squehubApp->config()->get('database', []),
    'app' => $squehubApp->config()->get('app', []),
];
// Database.php expects the historical string representation.
$config['app']['debug'] = $squehubApp->isDebug() ? 'true' : 'false';

return $config;
