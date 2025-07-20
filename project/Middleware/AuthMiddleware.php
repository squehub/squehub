<?php
namespace Project\Middleware;

use App\Core\MiddlewareHandler;

class AuthMiddleware extends MiddlewareHandler
{
    public function handle($request, $next)
    {
        // TODO: Write your middleware logic here

        return $next($request);
    }
}