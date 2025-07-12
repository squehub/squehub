<?php

// ------------------------------------------------------------
// Bootstrap File - Initializes Core Application
// ------------------------------------------------------------

// ✅ Start the session to enable session-based features (CSRF, Auth, Flash, etc.)
session_start();

// ✅ Load Composer autoloader (handles PSR-4 class loading and package dependencies)
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\Service; // Optional, useful for service container logic

// ✅ Load environment variables from .env (if available) using PHP dotenv
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad(); // Doesn't throw error if .env is missing

// ✅ Optionally verify the presence of critical .env mail config
$requiredEnv = [
    'MAIL_HOST',
    'MAIL_USERNAME',
    'MAIL_PASSWORD',
    'MAIL_ENCRYPTION',
    'MAIL_PORT',
    'MAIL_FROM_ADDRESS'
];

$missingEnv = array_filter($requiredEnv, fn($key) => empty(getenv($key)));
if (!empty($missingEnv)) {
    error_log('⚠️ Missing .env variables: ' . implode(', ', $missingEnv));
}

// ✅ Generate CSRF token once per session if not already present
if (!isset($_SESSION['_token'])) {
    $_SESSION['_token'] = bin2hex(random_bytes(32));
}

// ✅ Load framework global helper functions (e.g., csrf_token(), route(), etc.)
$helperFile = __DIR__ . '/app/Core/Helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;

    // ✅ Inject optional timezone detection JavaScript if the function exists
    if (function_exists('storeUserTimezoneScript')) {
        echo storeUserTimezoneScript(); // Useful for datetime localization
    }
} else {
    error_log('❌ Missing helper file: app/Core/Helper.php');
}

// ✅ Initialize the router (central dispatcher for HTTP requests)
require_once __DIR__ . '/Router.php';
$router = new Router();

// ✅ Load project-specific route files recursively from /project/routes/
$projectRoutesPath = __DIR__ . '/project/Routes';
$projectRouteFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoutesPath)
);
foreach (new RegexIterator($projectRouteFiles, '/\.php$/') as $routeFile) {
    require $routeFile; // Each file registers its routes
}

// ✅ Load core route files recursively from /app/routes/
$appRoutesPath = __DIR__ . '/app/Routes';
$appRouteFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoutesPath)
);
foreach (new RegexIterator($appRouteFiles, '/\.php$/') as $routeFile) {
    require $routeFile;
}

// ✅ Load custom debug error handler (for formatting, logging, graceful fallback)
$debugFile = __DIR__ . '/app/Core/Exceptions/Debug.php';
if (file_exists($debugFile)) {
    require_once $debugFile;
} else {
    error_log('❌ Missing debug.php file in app/Core/Exceptions/');
    exit('Required debug file not found. Exiting.');
}
