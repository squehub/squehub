<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use App\Http\Request;
use App\Http\Response;
use Closure;

/** Attach to a modern route after authentication or authorization as needed. */
final class RequireSignedUrl
{
    public function __construct(private string $purpose, private string $method = 'GET')
    {
    }

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (!SignedUrl::manager()->valid($request, $this->purpose, $this->method)) {
            throw new InvalidSignedUrlException();
        }
        return $next($request);
    }
}
