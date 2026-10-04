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

Reference a class with `->through(CacheControl::class)` or give it an alias in `Config/Routing.php`. The framework supplies the `auth` and `guest` aliases; use `->through('auth')` to protect a route. A custom middleware must enforce its own policy.

See the [official middleware guide](https://www.squehub.com/docs/v2.x/middleware) for route examples, alias registration, and execution order.
