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

/** Blocks authenticated identities from routes reserved for guests. */
final class RequireGuest
{
    public function __construct(private AuthManager $auth, private Repository $config, private ResponseFactory $responses)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->auth->guest()) return $next($request);
        // Guest-only policy is still Auth's decision; API formatting is central.
        if ((new ApiRequestPolicy($this->config))->matches($request)) throw new HttpException(403);
        if ($request->expectsJson()) return $this->responses->json(['message' => 'Guest access required.'], 403);
        $path = $this->config->get('auth.browser.authenticated_path');
        return $path === null
            ? $this->responses->make('<h1>Guest access required.</h1>', 403, ['Content-Type' => 'text/html; charset=UTF-8'])
            : $this->responses->redirect($path, 303);
    }
}
