<?php

// ------------------------------------------------------------
// Bootstrap File - Initializes Core Application
// ------------------------------------------------------------

// -----------------------------
// Start Session
// -----------------------------
// Start PHP session to enable features like CSRF protection, authentication, flash messages, etc.
session_start();

// -----------------------------
// Autoload and Namespaces
// -----------------------------
// Load Composer's PSR-4 autoloader and third-party dependencies
require_once __DIR__ . '/vendor/autoload.php';

// -----------------------------
// CSRF Token Initialization
// -----------------------------
// Generate a CSRF token once per session if not already set
if (!isset($_SESSION['_token'])) {
    $_SESSION['_token'] = bin2hex(random_bytes(32));
}

// -----------------------------
// Load Framework Helpers
// -----------------------------
// Include global helper functions (csrf_token(), route(), etc.)
$helperFile = __DIR__ . '/app/Core/Helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;

    // Inject optional timezone detection JS if function exists
    if (function_exists('storeUserTimezoneScript')) {
        echo storeUserTimezoneScript(); // Useful for client-side datetime localization
    }
} else {
    error_log('❌ Missing helper file: app/Core/Helper.php');
}

// -----------------------------
// Initialize Router
// -----------------------------
// Require core router class and instantiate it
require_once __DIR__ . '/Router.php';
$router = new Router();

// -----------------------------
// Load Routes Recursively
// -----------------------------

// 1. Load project-specific routes from /project/Routes recursively
$projectRoutesPath = __DIR__ . '/project/Routes';
$projectRouteFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($projectRoutesPath)
);
foreach (new RegexIterator($projectRouteFiles, '/\.php$/') as $routeFile) {
    require $routeFile;
}

// 2. Load core framework routes from /app/Routes recursively
$appRoutesPath = __DIR__ . '/app/Routes';
$appRouteFiles = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($appRoutesPath)
);
foreach (new RegexIterator($appRouteFiles, '/\.php$/') as $routeFile) {
    require $routeFile;
}

// 3. Load all package routes from project/Packages/*/routes recursively
$packagesBaseDir = __DIR__ . '/project/Packages';
foreach (new DirectoryIterator($packagesBaseDir) as $packageDir) {
    if ($packageDir->isDot() || !$packageDir->isDir()) {
        continue;
    }

    $routesDir = $packageDir->getPathname() . '/routes';
    if (is_dir($routesDir)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($routesDir)
        );
        foreach (new RegexIterator($iterator, '/\.php$/') as $routeFile) {
            require $routeFile;
        }
    }
}

// -----------------------------
// Load Debug Error Handler
// -----------------------------
// Load custom debug error handler for formatted errors, logging, graceful fallback
$debugFile = __DIR__ . '/app/Core/Exceptions/Debug.php';
if (file_exists($debugFile)) {
    require_once $debugFile;
} else {
    error_log('❌ Missing debug.php file in app/Core/Exceptions/');
    exit('Required debug file not found. Exiting.');
}

// -----------------------------
// Load Package and Project Utility Helpers
// -----------------------------

$packagesBaseDir = __DIR__ . '/project/packages/';
$projectUtilsDir = __DIR__ . '/project/Utils/';

// Load Utils from project-level Utils directory first (if exists)
if (is_dir($projectUtilsDir)) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectUtilsDir)
    ) as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            require_once $file->getRealPath();
        }
    }
}

// Load Utils from each package's Utils directory
foreach (new DirectoryIterator($packagesBaseDir) as $packageDir) {
    if ($packageDir->isDot() || !$packageDir->isDir()) {
        continue;
    }

    $utilsDir = $packageDir->getPathname() . '/Utils';
    if (is_dir($utilsDir)) {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($utilsDir)
        ) as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                require_once $file->getRealPath();
            }
        }
    }
}

