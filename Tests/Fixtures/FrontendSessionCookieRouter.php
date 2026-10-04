<?php

declare(strict_types=1);

use App\Plugins\Auth;
use App\Auth\AuthServiceProvider;
use App\Auth\Contracts\Authenticatable;
use App\Database\DatabaseServiceProvider;
use App\Database\Model;
use App\Foundation\Application;
use App\Http\HttpServiceProvider;
use App\Http\JsonResponse;
use App\Http\Kernel;
use App\Http\Request;
use App\Routing\Route;
use App\Routing\RoutingServiceProvider;
use App\Security\Csrf\CsrfServiceProvider;
use App\Session\SessionManager;
use App\Session\SessionServiceProvider;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/App/Core/Helper.php';

/** A persisted test identity rehydrates on every real HTTP request. */
final class FrontendSessionCookieIdentity extends Model implements Authenticatable
{
    protected string $table = 'frontend_cookie_users';
    protected array $hidden = ['password'];

    public function authIdentifier(): int|string { return (int) $this->getAttribute('id'); }
    public function authPasswordHash(): string { return (string) $this->getAttribute('password'); }
}

/**
 * The built-in server runs this fixture as a fresh Application per request.
 * Native PHP sessions, rather than shared test memory, carry browser identity.
 */
$projectRoot = getenv('SQUEHUB_FRONTEND_COOKIE_PROJECT');
$sessionRoot = getenv('SQUEHUB_FRONTEND_COOKIE_SESSIONS');
if (!is_string($projectRoot) || !is_dir($projectRoot)
    || !is_string($sessionRoot) || !is_dir($sessionRoot)) {
    http_response_code(500);
    return;
}
session_save_path($sessionRoot);

$app = new Application($projectRoot);
foreach ([DatabaseServiceProvider::class, SessionServiceProvider::class,
    CsrfServiceProvider::class, HttpServiceProvider::class,
    RoutingServiceProvider::class, AuthServiceProvider::class] as $provider) {
    $app->register($provider);
}
$app->bootstrap();

Route::path('/api/login')->post(static function (Request $request): JsonResponse {
    $authenticated = Auth::attempt([
        'email' => (string) $request->input('email'),
        'password' => (string) $request->input('password'),
    ]);
    return new JsonResponse(['authenticated' => $authenticated], $authenticated ? 200 : 401);
});
Route::path('/api/me')->get(static fn (): JsonResponse =>
    new JsonResponse(['id' => Auth::id()]))->through('auth');
Route::path('/api/logout')->post(static function (): JsonResponse {
    Auth::logout();
    return new JsonResponse(['logged_out' => true]);
})->through('auth');

$request = Request::capture();
$response = $app->container()->make(Kernel::class)->handle($request);
$app->container()->make(SessionManager::class)->store()->close();
$response->send($request->method() === 'HEAD');
