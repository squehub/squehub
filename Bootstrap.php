<?php

require_once __DIR__ . '/vendor/autoload.php';

// Legacy routes and templates inspect $_SESSION while loading. The manager
// starts it once here; CLI/bootstrap-only paths still defer the start.
if (!isset($squehubApp) || !$squehubApp instanceof \App\Foundation\Application) {
    $squehubApp = require __DIR__ . '/Bootstrap/App.php';
}
session()->start();

// Adopt an existing v1 token without issuing one for every anonymous GET.
\App\Security\Csrf\Csrf::manager()->migrateLegacy();

// Global helper functions are loaded for legacy route files.
$helperFile = __DIR__ . '/App/Core/Helper.php';
if (file_exists($helperFile)) {
    require_once $helperFile;

    // This legacy bootstrap emits timezone detection markup during setup.
    if (function_exists('storeUserTimezoneScript')) {
        echo storeUserTimezoneScript();
    }
} else {
    error_log('❌ Missing helper file: App/Core/Helper.php');
}

// Share the v2 route registry when an Application is present.
require_once __DIR__ . '/Router.php';
$router = new Router(
    isset($squehubApp) && $squehubApp instanceof \App\Foundation\Application
        ? $squehubApp->container()->make(\App\Routing\RouteRegistry::class)
        : null,
    isset($squehubApp) && $squehubApp instanceof \App\Foundation\Application
        ? $squehubApp->container()->make(\App\Packages\PackageManager::class)
        : null
);

// Web requests and route:list use the same route loader.
require __DIR__ . '/Bootstrap/Routes.php';

$debugFile = __DIR__ . '/App/Core/Exceptions/Debug.php';
if (file_exists($debugFile)) {
    require_once $debugFile;
} else {
    error_log('❌ Missing debug.php file in App/Core/Exceptions/');
    exit('Required debug file not found. Exiting.');
}

$projectUtilsDir = null;
foreach (['Project/Utils', 'Project/utils', 'project/Utils', 'project/utils'] as $candidate) {
    if (is_dir(__DIR__ . '/' . $candidate)) {
        $projectUtilsDir = __DIR__ . '/' . $candidate;
        break;
    }
}

// Legacy utility files define functions, so they require eager loading.
if ($projectUtilsDir !== null) {
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($projectUtilsDir)
    ) as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            require_once $file->getRealPath();
        }
    }
}
