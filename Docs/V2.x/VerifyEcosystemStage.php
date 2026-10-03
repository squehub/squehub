<?php

declare(strict_types=1);

/** Focused route, response, and metadata smoke for the staged public pages. */

use App\Foundation\Application;
use App\Http\Exception\NotFoundHttpException;
use App\Http\Request;
use App\Http\Response;
use App\Routing\Route;
use App\Routing\RouteMatcher;
use App\Routing\RouteRegistry;

$repository = dirname(__DIR__, 2);
$site = realpath($argv[1] ?? (__DIR__ . '/PortalSite'));
if ($site === false || !is_file($site . '/Project/Ecosystem/catalog.json')) {
    fwrite(STDERR, "Expected a staged PortalSite root.\n");
    exit(2);
}
require $repository . '/vendor/autoload.php';
require $site . '/Project/Controllers/DocumentationController.php';
require $site . '/Project/Controllers/EcosystemController.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

new Application($site);
$registry = new RouteRegistry();
Route::setResolver(static fn (): RouteRegistry => $registry);
require $site . '/Project/Routes/Web.php';
$matcher = new RouteMatcher();

/** @return Response */
function requestPage(RouteMatcher $matcher, RouteRegistry $registry, string $path): Response
{
    $request = new Request('GET', $path);
    $matched = $matcher->match($registry, $request);
    $request->setAttribute('route.params', $matched->parameters);
    $action = $matched->route->action();
    check(is_array($action) && count($action) === 2, "Unexpected route action for $path");
    [$class, $method] = $action;
    $controller = new $class();
    $reflection = new ReflectionMethod($controller, $method);
    $response = $reflection->getNumberOfRequiredParameters() === 0
        ? $controller->{$method}() : $controller->{$method}($request);
    check($response instanceof Response, "No Response for $path");
    return $response;
}

foreach (['/packages' => 'squehub/media', '/kits' => 'squehub/app-starter'] as $path => $planned) {
    $response = requestPage($matcher, $registry, $path);
    check($response->status() === 200 && str_contains($response->content(), $planned),
        "$path listing failed");
    check(str_contains($response->content(), 'No published'), "$path missing honest release state");
    check(str_contains($response->content(), 'Planned / not a published release'),
        "$path planned entry is not labeled");
}
foreach (['/Packages' => '/packages', '/Kits' => '/kits'] as $alias => $canonical) {
    $response = requestPage($matcher, $registry, $alias);
    check($response->status() === 301 && $response->header('Location') === $canonical,
        "$alias did not redirect to canonical path");
}
foreach (['/packages/media', '/packages/media/details',
    '/packages/media/docs', '/kits/app-starter', '/kits/app-starter/details',
    '/kits/app-starter/docs'] as $path) {
    $response = requestPage($matcher, $registry, $path);
    check($response->status() === 200, "$path did not render");
    check(str_contains($response->content(), 'Planned / not a published release'),
        "$path lost its planned boundary");
    check(str_contains($response->content(), '<meta name="robots" content="noindex,follow">'),
        "$path should not be indexed as a release");
    check(str_contains($response->content(), 'Planned, not released'),
        "$path title or summary lost the planned label");
    check(str_contains($response->content(), 'https://www.squehub.com' . $path),
        "$path canonical is missing");
}
foreach (['/community', '/changelogs', '/contact'] as $path) {
    $response = requestPage($matcher, $registry, $path);
    check($response->status() === 200 && str_contains($response->content(), 'ecosystem-page'),
        "$path did not render");
}
$kitDocs = requestPage($matcher, $registry, '/kits/app-starter/docs')->content();
check(str_contains($kitDocs, 'Proposed future workflow')
    && str_contains($kitDocs, 'kit:install squehub/app-starter')
    && str_contains($kitDocs, 'kit:enable app-starter'),
    'Proposed Kit commands lost their unavailable label');
$mediaDocs = requestPage($matcher, $registry, '/packages/media/docs')->content();
check(str_contains($mediaDocs, 'Media::attach(...) is conceptual only'),
    'Media conceptual API warning is missing');
foreach (['/packages/unknown', '/kits/unknown/docs',
    '/packages/media/installation'] as $path) {
    $response = requestPage($matcher, $registry, $path);
    check($response->status() === 404, "$path did not return 404");
    check($response->header('Cache-Control') === 'no-store', "$path cached a 404");
    check(str_contains($response->content(), 'content="noindex,follow"'),
        "$path is missing noindex metadata");
}
check(requestPage($matcher, $registry, '/packages/media/overview')->header('Location')
    === '/packages/media', 'Overview alias did not canonicalize');
foreach (['/packages/media/unknown', '/kits/app-starter/docs/extra',
    '/packages/%2E%2E/docs'] as $path) {
    try {
        requestPage($matcher, $registry, $path);
        throw new RuntimeException("Unsafe or unknown path matched: $path");
    } catch (NotFoundHttpException) {
        // Expected matcher-level 404.
    }
}

echo "Staged ecosystem routes, aliases, planned entries, 404s, and metadata verified.\n";
