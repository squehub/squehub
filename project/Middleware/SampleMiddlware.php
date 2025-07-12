<?php
namespace Project\Middleware;

use App\Core\MiddlewareHandler;

class SampleMiddlware extends MiddlewareHandler
{
    public function handle($request, $next)
    {
        // TODO: Write your middleware logic here

        return $next($request);
    }
}