# SqueHub v2 middleware

The v2 routing path runs an ordered middleware pipeline. Application middleware can import `App\Plugins\Request` and `App\Plugins\Response`; these are exact aliases for the HTTP types. Calling `$next($request)` continues to the next middleware or the route handler; returning a response without calling it stops the pipeline.

`php squehub make:middleware CacheControlMiddleware` creates a pass-through class under `Project/Middleware/`. It has no alias until the application registers one. See [Generators](Generators.md) for preview and Package targeting.

```php
use App\Plugins\Request;
use App\Plugins\Response;
use Closure;

final class CacheControlMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request)->withHeader('Cache-Control', 'no-store');
    }
}
```

Register aliases in `Config/Routing.php`:

```php
return [
    'middleware' => [
        'cache-control' => CacheControlMiddleware::class,
    ],
];
```

The routing provider loads that map at application startup. A provider can also register an alias through `MiddlewareRegistry::alias()`.

Attach middleware to one route or a group. The other class names below stand for middleware classes in the application:

```php
use App\Plugins\Route;

Route::path('/dashboard')->get([DashboardController::class, 'index'])
    ->through('cache-control');

Route::group()->through([FirstMiddleware::class, SecondMiddleware::class])->routes(function (): void {
    Route::path('/admin/users')->get([AdminUserController::class, 'index'])
        ->through(ThirdMiddleware::class);
});
```

The `auth` and `guest` aliases are installed by the [authentication provider](Authentication.md) and use the configured default guard. A named [rate limiter](RateLimiting.md) can be attached as `RateLimitRequests::named('login')`; its placement in the route list determines whether earlier guards consume a permit. Modern middleware may be a registered alias, class name, explicit object with `handle()`, or callable object. Class names are resolved through the Application container; explicit objects are used as supplied. An unknown alias fails clearly during dispatch. `route:list` shows an object's class name without exposing its fields.

The [authorization foundation](Authorization.md) supplies `RequireAbility::named('reports.view')` for global abilities. Attach it after `auth` when guests should receive authentication behavior before authorization:

```php
use App\Plugins\RequireAbility;

Route::path('/reports')->get([ReportController::class, 'index'])
    ->through(['auth', RequireAbility::named('reports.view')]);
```

It checks only a global named ability. A resource policy needs an actual loaded subject and is checked in controller or service code with `authorize()->require('update', $post)`.

A named [personal access token](ApiTokens.md) guard uses explicit middleware objects, not a colon parameter in an alias string:

```php
use App\Plugins\{RequireAbility, RequireToken, RequireTokenAbility, Route};

Route::path('/api/orders')->get([OrderApiController::class, 'index'])
    ->through([
        RequireToken::guard('api'),
        RequireTokenAbility::named('orders.read'),
        RequireAbility::named('orders.view'),
    ]);
```

The token guard runs first, then the token's ability boundary, then the application's Authorization rule. `RequireToken` does not fall back to a session cookie when the Bearer credential is missing or invalid. A token's ability never grants an application policy permission by itself. Global CSRF middleware still precedes this route list; a dedicated unsafe token-only path needs an explicit CSRF exclusion, not a global `/api` exemption. See [CSRF](Csrf.md).

The pipeline preserves registration order on the way in and reverses it on the way out. For outer group `auth`, inner group `admin`, and route `audit`, execution is:

```text
auth before
  admin before
    audit before
      handler
    audit after
  admin after
auth after
```

A middleware that returns a response without calling `$next` prevents all later middleware and the handler from running. Middleware may return a `Response`; the normal HTTP response conversion also handles supported route results such as strings and arrays.

Legacy `$router->add()` middleware callbacks keep their historical signature, receiving positional route parameters and a no-op `$next`. This also applies when their routes are mirrored into the new registry. The modern `Route` API uses real nested `$next($request)` calls.

Middleware parameters in alias strings, such as `throttle:60,1`, are deferred.

## Application-facing Plugins import

Application code may import `App\Plugins\Request`, `App\Plugins\Response`, `App\Plugins\RequireAbility`, `App\Plugins\RequireToken`, and `App\Plugins\RequireTokenAbility`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
