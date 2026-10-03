<?php

declare(strict_types=1);

/** Own the web boundary: boot, load routes, handle, close session, then send. */
use App\Http\ExceptionHandler;
use App\Http\EnvironmentSetupResponse;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Http\Response;
use App\Foundation\EnvironmentSetup;
use App\Foundation\UrlBasePath;
use App\Routing\RoutePattern;

if (version_compare(PHP_VERSION, '8.2.0', '<')) {
    http_response_code(500);
    exit('SqueHub requires PHP 8.2 or higher.');
}

// Both public entry points share one request and response lifecycle.
require_once dirname(__DIR__) . '/vendor/autoload.php';
$request = Request::capture();
$setupNotice = EnvironmentSetup::notice(dirname(__DIR__));
if ($setupNotice !== null) {
    // A fresh install should show one safe setup page before legacy bootstrap
    // or default database settings can run. Recheck the files each request so
    // a developer can finish setup without restarting the development server.
    EnvironmentSetupResponse::forRequest($request, $setupNotice)->send($request->method() === 'HEAD');
    return;
}
try {
    $squehubApp = require __DIR__ . '/App.php';
} catch (Throwable $exception) {
    // Configuration can fail before an Application exists to render an error.
    (new Response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=UTF-8']))
        ->send($request->method() === 'HEAD');
    return;
}
$level = ob_get_level();
ob_start();
$healthRequest = false;
try {
    // Disable PHP error display before loading legacy route files. Their
    // bootstrap runs before the legacy debug script is included.
    if (!$squehubApp->isDebug()) {
        ini_set('display_errors', '0');
    }

    // Opted-in health routes are already in the Application registry. Bypass
    // legacy route/session bootstrap so liveness stays independent of Session
    // and Redis, and public responses cannot contain the legacy debug bar.
    $urlBasePath = $squehubApp->container()->make(UrlBasePath::class);
    $healthPath = $urlBasePath->strip($request->rawPath());
    $healthRequest = $squehubApp->config()->get('health.endpoints_enabled', false) === true
        && $request->method() === 'GET'
        && $healthPath !== null
        && ($urlBasePath->value() === '' || RoutePattern::safeRequestPath($request->rawPath()))
        && in_array('/' . trim($healthPath, '/'), ['/health/live', '/health/ready'], true);
    if (!$healthRequest) {
        // Legacy routes still need the compatibility constants; PDO remains deferred.
        $config = require $squehubApp->basePath('config.php');
        require $squehubApp->basePath('Bootstrap.php');
    }

    $bootstrapOutput = '';
    while (ob_get_level() > $level) {
        $bootstrapOutput = ob_get_clean() . $bootstrapOutput;
    }

    $response = $squehubApp->container()->make(Kernel::class)->handle($request);
    $contentType = strtolower(trim(explode(';', $response->header('Content-Type', 'text/html'))[0]));
    if ($bootstrapOutput !== '' && !$response instanceof JsonResponse
        && !$response->hasDeferredBody() && $contentType === 'text/html') {
        if ($response->status() < 400) {
            $response = $response->withContent($bootstrapOutput . $response->content());
        } elseif ($squehubApp->isDebug()) {
            // Keep the legacy development bar visible on custom error pages
            // without placing markup before their document type declaration.
            $html = $response->content();
            $bodyEnd = strripos($html, '</body>');
            $html = $bodyEnd === false
                ? $html . $bootstrapOutput
                : substr($html, 0, $bodyEnd) . $bootstrapOutput . substr($html, $bodyEnd);
            $response = $response->withContent($html);
        }
    }
} catch (Throwable $exception) {
    while (ob_get_level() > $level) {
        ob_end_clean();
    }
    $response = $squehubApp->container()->make(ExceptionHandler::class)->render($exception, $request);
}

if (!$healthRequest) {
    try {
        // Persist data and release PHP's session lock before sending a response body.
        $squehubApp->container()->make(\App\Session\SessionManager::class)->store()->close();
    } catch (Throwable $exception) {
        $response = $squehubApp->container()->make(ExceptionHandler::class)->render($exception, $request);
    }
}
$response->send($request->method() === 'HEAD');
