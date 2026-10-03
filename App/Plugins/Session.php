<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Session\Session as SessionGateway;
use App\Session\SessionManager;
use App\Session\SessionStore;

/** Session access through the current Application's configured driver. */
final class Session
{
    public static function manager(): SessionManager { return SessionGateway::manager(); }
    public static function store(): SessionStore { return self::manager()->store(); }
    public static function get(string $key, mixed $default = null): mixed { return self::store()->get($key, $default); }
    public static function put(string $key, mixed $value): void { self::store()->put($key, $value); }
    public static function flash(string $key, mixed $value): void { self::store()->flash($key, $value); }
    public static function old(?string $key = null, mixed $default = null): mixed
    {
        return self::store()->old($key, $default);
    }
}
