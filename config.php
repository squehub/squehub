<?php
// -------------------------------------------------------
// config.php - Centralized Configuration Loader for App
// -------------------------------------------------------

/**
 * This file loads and merges environment variables using Dotenv,
 * sets application and database configuration, applies defaults,
 * validates essentials, and returns a global $config array.
 */

// ✅ Define the base directory constant for global reference
define('BASE_DIR', __DIR__);

// ✅ Autoload dependencies via Composer
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

// ✅ Load environment variables from .env file into $_ENV
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// ✅ Define default configuration values (used as fallback)
$defaultConfig = [
    'database' => [
        'host'     => 'localhost',
        'database' => 'squehub',
        'user'     => 'root',
        'password' => '',
    ],
    'app' => [
        'env'   => 'development',
        'debug' => 'true',
    ],
];

// ✅ Merge .env variables with defaults for full configuration
$config = [
    'database' => [
        'host'     => $_ENV['DB_HOST']     ?? $defaultConfig['database']['host'],
        'database' => $_ENV['DB_DATABASE'] ?? $defaultConfig['database']['database'],
        'user'     => $_ENV['DB_USER']     ?? $defaultConfig['database']['user'],
        'password' => $_ENV['DB_PASSWORD'] ?? $defaultConfig['database']['password'],
    ],
    'app' => [
        'env'   => $_ENV['APP_ENV']   ?? $defaultConfig['app']['env'],
        'debug' => $_ENV['APP_DEBUG'] ?? $defaultConfig['app']['debug'],
    ],
];

// ✅ Basic validation to alert on missing critical database fields
if (
    empty($config['database']['host']) ||
    empty($config['database']['database']) ||
    empty($config['database']['user'])
) {
    error_log('⚠️ Warning: Database configuration is incomplete. Some default values are being used.');
}

// ✅ Define DEBUG_MODE as a global constant (for conditional logging, error display, etc.)
define('DEBUG_MODE', $config['app']['debug'] === 'true');

// ✅ Return the finalized configuration array for use throughout the application
return $config;
