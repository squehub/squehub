# Errors, exceptions, and custom pages

The HTTP Kernel converts routing, validation, CSRF, authentication, authorization, rate-limit, and application failures into Responses at one boundary. Enabled [API response scopes](ApiResponses.md) receive a stable JSON error envelope and trusted request ID, safe in debug and production. Outside those scopes, JSON negotiation and browser HTML/custom pages retain their existing behavior. See [HTTP](Http.md) for the request lifecycle and normal response conversion.

## Route and request errors

An unknown path returns 404. ExceptionHandler-generated 404 responses use `Cache-Control: no-store`, including HTML fallback templates and JSON, so a cached miss cannot outlive a newly published page. A known path with the wrong method returns 405 and an `Allow` header. Global CSRF checks unsafe methods before route matching, so an unsafe request without a token can return 403 even when its path is unknown. Validation returns structured JSON 422 or a safe browser redirect/422. Authentication and authorization have their own 401/403 behavior; a denied named rate limiter returns 429 with `Retry-After` and rate headers. These are expected application outcomes, not generic 500 errors.

## Custom HTML error pages

Define HTML-only error handlers in an application route file:

```php
use App\Plugins\{Route, View};

Route::error(403, function (): void {
    View::render('Errors.Forbidden');
});

Route::error(500, function (): void {
    View::render('Errors.Server');
});
```

Create matching templates such as `Project/Views/Errors/Forbidden.squehub.php` and `Project/Views/Errors/Server.squehub.php`. The handler takes no arguments and keeps the original error status and framework headers. The last registered handler for a status wins. JSON errors retain their normal structured response. A failing custom handler falls back to the built-in safe page and is reported to the configured logger.

The legacy routing hook remains for applications migrating from v1:

```php
$router->setNotFoundHandler(function (): void {
    View::render('Errors.NotFound');
});

$router->setErrorHandler(500, function (): void {
    View::render('Errors.Server');
});
```

The supplied fallback HTML pages live under `Project/Views/Default/Error/<status>.php`. They are used in debug and production. For a generic error other than 404, debug mode supplies `$squehubErrorDebug` to the PHP template with the already-redacted `error`, `exception`, and `message` values, plus `file`, `line`, and `trace` when that diagnostic type permits them. In production and for 404, the variable is `null`. A fallback template must escape every value it renders; the supplied 500 page does so. Deliberately quiet security denials, such as CSRF and rate-limit failures, do not receive this diagnostic data. A missing or broken template uses the escaped framework fallback. Never place request bodies, credentials, or tokens on a public error page. Missing routes do not expose a trace.

## Debug mode and logging

`APP_DEBUG=false` suppresses the debug bar and detailed errors. `APP_DEBUG=true` permits development details in the legacy HTML/JSON error responses outside API scope; `.env` edits are read on the next request during `php squehub start` unless a shell environment variable overrides them. Managed API envelopes never expose exception internals in either mode, and JSON never receives the debug bar. The exception handler redacts known configured secrets and captured Authorization tokens, but do not intentionally put secrets in public messages/details. Uncaught 5xx failures are logged once at the HTTP boundary; routine validation and 404 results are quiet. See [Logging](Logging.md), [Diagnostics](Diagnostics.md), and [Configuration](Configuration.md).

For recognized View diagnostics, the debug HTML page shows escaped logical View information without the generated PHP file path or trace. A missing root View, an absent requested Fragment, a missing required layout/include/component, a compiler error, and an active dependency cycle retain their focused diagnostic types. `InvalidFragmentNameException` rejects a public Fragment name before lookup without echoing the input. Root View and Fragment selection failures do not invent source lines; in-template dependency and structural errors use the owning template's source line. An ordinary runtime error retains its original cause internally but receives only logical View attribution on the debug page. A generic `HttpException` crossing a View render retains its status, headers, and cause through a logical View wrapper; managed Auth, Authorization, Validation, CSRF, rate-limit, and API errors keep their normal handling. The catchable diagnostics have `App\Plugins` aliases; the internal `ViewHttpException` does not. Production 5xx responses stay generic, and no generated line is presented as an original template line. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md).
