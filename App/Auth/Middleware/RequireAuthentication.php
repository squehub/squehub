<?php

declare(strict_types=1);

namespace App\Auth\Middleware;

use App\Auth\AuthManager;
use App\Api\ApiRequestPolicy;
use App\Http\Exception\HttpException;
use App\Config\Repository;
use App\Http\Request;
use App\Http\Response;
use App\Http\ResponseFactory;
use Closure;

/** Runs after global CSRF middleware and before a protected controller. */
final class RequireAuthentication
{
    public function __construct(private AuthManager $auth, private Repository $config, private ResponseFactory $responses)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->check()) return $next($request);
        // The central API handler owns formatting; session/browser behavior
        // below stays unchanged for requests outside explicitly configured scopes.
        if ((new ApiRequestPolicy($this->config))->matches($request)) throw new HttpException(401);
        if ($request->expectsJson()) return $this->responses->json(['message' => 'Authentication required.'], 401);
        $path = $this->config->get('auth.browser.login_path');
        return $path === null
            ? $this->responses->make('<h1>Authentication required.</h1>', 401, ['Content-Type' => 'text/html; charset=UTF-8'])
            : $this->responses->redirect($path, 303);
    }
}
