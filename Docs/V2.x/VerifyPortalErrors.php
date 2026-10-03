<?php

declare(strict_types=1);

/** Exercise the staged or deployed site error boundary without a web server. */

use App\Foundation\Application;
use App\Http\Exception\HttpException;
use App\Http\Request;
use App\Security\Csrf\CsrfException;

$repository = dirname(__DIR__, 2);
$site = realpath($argv[1] ?? (__DIR__ . '/PortalSite'));
if ($site === false || !is_file($site . '/App/Http/ExceptionHandler.php')) {
    fwrite(STDERR, "Expected a site root with App/Http/ExceptionHandler.php.\n");
    exit(2);
}
require $repository . '/vendor/autoload.php';
// Load the site-owned patch before Composer can resolve the framework copy.
require $site . '/App/Http/ExceptionHandler.php';

function verify(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$app = new Application($site);
$app->config()->set('app.debug', false);
$handler = new App\Http\ExceptionHandler($app);
$htmlRequest = new Request('GET', '/missing', [], [], [], [], ['Accept' => 'text/html']);
$secret = 'PRIVATE_ERROR_CONTEXT_58a';

foreach ([403, 404, 429] as $status) {
    $headers = $status === 429 ? ['Retry-After' => '12'] : [];
    $response = $handler->render(new HttpException($status, $secret, $headers), $htmlRequest);
    verify($response->status() === $status, "$status status changed");
    verify(str_contains($response->content(), 'class="error-page"'), "$status did not use the site template");
    verify(str_contains($response->content(), "<title>$status "), "$status title is missing");
    verify(str_contains($response->content(), '/assets/docs/css/error.css'), "$status styles are missing");
    verify(str_contains($response->content(), '/assets/docs/images/squehub-icon.png'),
        "$status official logo or favicon is missing");
    verify(str_contains($response->content(), '/assets/docs/js/preloader.js')
        && str_contains($response->content(), '/assets/docs/images/sq-l.png')
        && str_contains($response->content(), '/assets/docs/images/sq-r.png'),
        "$status preloader assets are missing");
    verify(!str_contains($response->content(), $secret), "$status exposed exception text");
    if ($status === 429) {
        verify($response->header('Retry-After') === '12', '429 lost Retry-After');
    }
}

$csrf = $handler->render(new CsrfException(), $htmlRequest);
verify($csrf->status() === 403 && str_contains($csrf->content(), 'class="error-page"'),
    'CSRF denial lost its generic page');

$server = $handler->render(new RuntimeException($secret), $htmlRequest);
verify($server->status() === 500 && str_contains($server->content(), 'class="error-page"'),
    'Production 500 did not use the site template');
verify(!str_contains($server->content(), $secret)
    && !str_contains($server->content(), 'Development diagnostics'),
    'Production 500 exposed debug details');

$app->config()->set('app.debug', true);
$configuredSecret = 'DB_PRIVATE_VALUE_8f3d';
$app->config()->set('database.password', $configuredSecret);
$missing = $handler->render(new HttpException(404, $secret), $htmlRequest);
verify($missing->status() === 404 && !str_contains($missing->content(), $secret),
    'Debug 404 exposed a route exception');
$forbiddenDebug = $handler->render(new HttpException(403, $configuredSecret), $htmlRequest);
verify($forbiddenDebug->status() === 403
    && str_contains($forbiddenDebug->content(), 'Development diagnostics')
    && str_contains($forbiddenDebug->content(), '[redacted]')
    && !str_contains($forbiddenDebug->content(), $configuredSecret),
    'Generic debug 403 lost its safe diagnostics');
$csrfDebug = $handler->render(new CsrfException(), $htmlRequest);
verify($csrfDebug->status() === 403
    && !str_contains($csrfDebug->content(), 'Development diagnostics'),
    'CSRF debug denial exposed diagnostics');
$limitedDebug = $handler->render(new HttpException(429, $configuredSecret), $htmlRequest);
verify($limitedDebug->status() === 429
    && str_contains($limitedDebug->content(), 'Development diagnostics')
    && !str_contains($limitedDebug->content(), $configuredSecret),
    'Generic debug 429 lost its safe diagnostics');
$debug = $handler->render(new RuntimeException('debug <script>' . $configuredSecret), $htmlRequest);
verify($debug->status() === 500 && str_contains($debug->content(), 'class="error-page"'),
    'Debug 500 did not use the site template');
verify(str_contains($debug->content(), 'Development diagnostics')
    && str_contains($debug->content(), '&lt;script&gt;')
    && str_contains($debug->content(), '[redacted]')
    && !str_contains($debug->content(), $configuredSecret)
    && !str_contains($debug->content(), '<script>'),
    'Debug 500 diagnostics were missing or unescaped');

$jsonRequest = new Request('GET', '/missing', [], [], [], [], ['Accept' => 'application/json']);
$json = $handler->render(new HttpException(404, $secret), $jsonRequest);
verify($json->status() === 404
    && str_starts_with((string) $json->header('Content-Type'), 'application/json')
    && !str_contains($json->content(), '<html')
    && !str_contains($json->content(), $secret),
    'JSON 404 contract changed');

$method = $handler->render(new HttpException(405, $secret, ['Allow' => 'GET, HEAD']), $htmlRequest);
verify($method->status() === 405 && $method->header('Allow') === 'GET, HEAD',
    '405 status or Allow header changed');

$asset = is_file($site . '/public/assets/docs/css/error.css')
    ? $site . '/public/assets/docs/css/error.css'
    : $site . '/Assets/docs/css/error.css';
verify(is_file($asset), 'Site error stylesheet is missing');
echo "Portal error boundary: 403, 404, 429, 500, debug, JSON, and headers verified.\n";
