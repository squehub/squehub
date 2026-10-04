<?php

declare(strict_types=1);

namespace App\Support;

use App\Auth\Auth;
use App\Cache\Cache;
use App\Core\View;
use App\Events\Events;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Logging\Log;
use App\Routing\Route;
use App\Session\Session;
use App\Storage\Storage;

/** Installs public shortcuts for unnamespaced project and package route files. */
final class GlobalAliases
{
    public static function register(): void
    {
        $aliases = [
            'Route' => Route::class,
            'View' => View::class,
            'Request' => Request::class,
            'Response' => Response::class,
            'JsonResponse' => JsonResponse::class,
            'Auth' => Auth::class,
            'Cache' => Cache::class,
            'Events' => Events::class,
            'Log' => Log::class,
            'Session' => Session::class,
            'Storage' => Storage::class,
        ];

        foreach ($aliases as $shortName => $class) {
            // An application or package may already own a global class name.
            // Aliases supplement Composer; they never replace that class.
            if (!class_exists($shortName) && !interface_exists($shortName) && !trait_exists($shortName)) {
                class_alias($class, $shortName);
            }
        }
    }
}
