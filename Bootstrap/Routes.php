<?php

declare(strict_types=1);

/** Load v2 and legacy route files into the shared registry for web or CLI. */
use App\Foundation\Application;
use App\Kits\KitException;
use App\Kits\KitManager;
use App\Packages\PackageManager;
use App\Contributions\ContributionOwner;
use App\Routing\MiddlewareRegistry;
use App\Routing\RouteRegistry;
use App\Support\RuntimeContext;

// Route files share this scope with Bootstrap.php and may still use $router.
// Keep this loader free of database, session, and debug initialization so the
// console can inspect route definitions without handling a web request.
require_once dirname(__DIR__) . '/App/Core/Helper.php';
require_once dirname(__DIR__) . '/Router.php';

$routeRegistry = isset($squehubApp) && $squehubApp instanceof Application
    ? $squehubApp->container()->make(RouteRegistry::class)
    : null;
if (!isset($router) || !$router instanceof Router) {
    $router = new Router($routeRegistry,
        isset($squehubApp) && $squehubApp instanceof Application
            ? $squehubApp->container()->make(PackageManager::class) : null);
}

$routeRoot = isset($squehubApp) && $squehubApp instanceof Application
    ? $squehubApp->basePath()
    : dirname(__DIR__);
$contributions = isset($squehubApp) && $squehubApp instanceof Application
    ? $squehubApp->contributions() : null;
if (isset($squehubApp) && $squehubApp instanceof Application) {
    // Route files can use static developer APIs. Select this Application if
    // another one has booted since its previous route load.
    RuntimeContext::select($squehubApp);
    // RouteCache validates its source fingerprint before replaying a pure
    // declaration snapshot. Missing/stale caches use this same source loader.
    (new \App\Routing\RouteCache($squehubApp))->loadOrRequire($router);
    return;
}
$routeDirectories = [
    $routeRoot . '/Project/Routes',
    $routeRoot . '/Project/routes',
    $routeRoot . '/project/Routes',
    $routeRoot . '/project/routes',
];

// Case variants may resolve to the same directory on Windows.
$seenRouteDirectories = [];
foreach ($routeDirectories as $routeDirectory) {
    $resolvedDirectory = realpath($routeDirectory);
    if ($resolvedDirectory === false || isset($seenRouteDirectories[$resolvedDirectory])) {
        continue;
    }
    $seenRouteDirectories[$resolvedDirectory] = true;

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolvedDirectory, FilesystemIterator::SKIP_DOTS)
    );
    $paths = [];
    foreach ($files as $routeFile) {
        if ($routeFile->isFile() && strtolower($routeFile->getExtension()) === 'php') {
            $paths[] = $routeFile->getPathname();
        }
    }
    sort($paths, SORT_STRING);
    $owner = new ContributionOwner('application', 'Project');
    foreach ($paths as $path) {
        $rootPrefix = rtrim(str_replace('\\', '/', $routeRoot), '/') . '/';
        $normalizedPath = str_replace('\\', '/', $path);
        $source = str_starts_with($normalizedPath, $rootPrefix)
            ? substr($normalizedPath, strlen($rootPrefix)) : null;
        $artifactOwner = $owner;
        if ($source !== null && str_starts_with($source, 'Project/Routes/')
            && isset($squehubApp) && $squehubApp instanceof Application) {
            try {
                // A Kit may have created this ordinary Project route file.
                // Attribution reads its ownership state only; no Kit entry runs.
                $artifactOwner = $squehubApp->container()->make(KitManager::class)
                    ->ownerForFile($source) ?? $owner;
            } catch (KitException) {
                // Broken Kit metadata must not prevent normal Project routes
                // from loading. Doctor reports Kit health separately.
                $artifactOwner = $owner;
            }
        }
        $contributions?->beginOwner($artifactOwner, $source);
        try {
            require $path;
        } finally {
            $contributions?->endOwner();
        }
    }
}

// Package code is executable. Consult the Application's validated activation
// snapshot instead of scanning every copied Package directory.
if (isset($squehubApp) && $squehubApp instanceof Application) {
    $packages = $squehubApp->container()->make(PackageManager::class)->active();
    foreach ($packages as $package) {
        $packageRoot = realpath($package->path());
        if ($packageRoot === false || !is_dir($packageRoot) || is_link($package->path())) {
            throw new LogicException('Enabled Package directory is unavailable or linked.');
        }
        foreach (['Routes', 'routes'] as $routesName) {
            $routeDirectory = $packageRoot . '/' . $routesName;
            if (!is_dir($routeDirectory)) {
                continue;
            }
            $resolvedDirectory = realpath($routeDirectory);
            if ($resolvedDirectory === false || is_link($routeDirectory)
                || dirname($resolvedDirectory) !== $packageRoot) {
                throw new LogicException('Enabled Package route directory is unsafe.');
            }
            if (isset($seenRouteDirectories[$resolvedDirectory])) {
                break;
            }
            $seenRouteDirectories[$resolvedDirectory] = true;

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($resolvedDirectory, FilesystemIterator::SKIP_DOTS)
            );
            $paths = [];
            foreach ($files as $routeFile) {
                if (!$routeFile->isFile() || strtolower($routeFile->getExtension()) !== 'php') {
                    continue;
                }
                $path = $routeFile->getPathname();
                $resolvedFile = realpath($path);
                if ($routeFile->isLink() || $resolvedFile === false
                    || !str_starts_with($resolvedFile, $packageRoot . DIRECTORY_SEPARATOR)) {
                    throw new LogicException('Enabled Package route file is unsafe.');
                }
                $paths[] = $path;
            }
            sort($paths, SORT_STRING);
            $config = $squehubApp->config();
            $middleware = $squehubApp->container()->has(MiddlewareRegistry::class)
                ? $squehubApp->container()->make(MiddlewareRegistry::class) : null;
            $config->beginPackageContext($package->name());
            try {
                $middleware?->beginPackageContext($package->name());
                try {
                    $routeRegistry?->beginPackageContext($package->name());
                    try {
                        foreach ($paths as $path) {
                            $packagePrefix = rtrim(str_replace('\\', '/', $packageRoot), '/') . '/';
                            $normalizedPath = str_replace('\\', '/', $path);
                            $source = str_starts_with($normalizedPath, $packagePrefix)
                                ? 'Project/Packages/' . $package->name() . '/'
                                    . substr($normalizedPath, strlen($packagePrefix)) : null;
                            $contributions?->beginOwner(
                                new ContributionOwner('package', $package->name()), $source);
                            try {
                                require $path;
                            } finally {
                                $contributions?->endOwner();
                            }
                        }
                    } finally {
                        $routeRegistry?->endPackageContext();
                    }
                } finally {
                    $middleware?->endPackageContext();
                }
            } finally {
                $config->endPackageContext();
            }
            break;
        }
    }
}
