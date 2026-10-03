# Application middleware

Keep application-specific middleware classes here. This directory is optional: SqueHub does not scan it or register its classes automatically. Composer loads `Project\Middleware` classes when a route or middleware alias references them.

New middleware should use the v2 request pipeline:

```php
<?php

declare(strict_types=1);

namespace Project\Middleware;

use App\Plugins\Request;
use App\Plugins\Response;
use Closure;

final class CacheControl
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('Cache-Control', 'no-store');
    }
}
```

Reference a class with `->through(CacheControl::class)` or give it an alias in `Config/Routing.php`. The framework already supplies the `auth` and `guest` aliases; use `->through('auth')` to protect a route. The removed starter `AuthMiddleware` was a pass-through example and did not enforce authentication.

See [Middleware](../../Docs/V2.x/Middleware.md) for route examples, alias registration, and execution order.
