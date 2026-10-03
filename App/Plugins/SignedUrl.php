<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Http\Request;
use App\Security\SignedUrl\RequireSignedUrl;
use App\Security\SignedUrl\SignedUrl as Gateway;
use App\Security\SignedUrl\SignedUrlManager;

/** Public gateway for temporary, purpose-bound named-route links. */
final class SignedUrl
{
    public static function manager(): SignedUrlManager { return Gateway::manager(); }

    /** @param array<string,mixed> $routeParams @param array<string,string|int> $query */
    public static function temporary(string $routeName, array $routeParams, array $query,
        int $expiresAt, string $purpose, string $method = 'GET'): string
    {
        return self::manager()->temporary($routeName, $routeParams, $query, $expiresAt, $purpose, $method);
    }

    public static function valid(Request $request, string $purpose, string $method = 'GET'): bool
    {
        return self::manager()->valid($request, $purpose, $method);
    }

    public static function middleware(string $purpose, string $method = 'GET'): RequireSignedUrl
    {
        return new RequireSignedUrl($purpose, $method);
    }
}
