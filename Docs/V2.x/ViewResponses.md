# Returnable View Responses

`View::response()` renders a complete `.squehub.php` page and returns a normal `App\Http\Response`. Use it from a controller or route when the page needs an explicit HTTP status or headers. It is available through the application-facing `App\Plugins\View` gateway in v2.

```php
use App\Plugins\{Response, View};

final class DashboardController
{
    public function index(): Response
    {
        return View::response(
            'Dashboard.Index',
            ['title' => 'Dashboard'],
        );
    }
}
```

The method signature is `View::response(string $view, array $data = [], int $status = 200, array $headers = []): App\Http\Response`. `App\Plugins\Response` is an exact alias of that returned type. The response starts with status `200` and `Content-Type: text/html; charset=UTF-8`. Returning it lets the existing dispatcher, middleware, Kernel, and sender handle it like any other `Response`.

## Set status and headers

Use named arguments for an application-selected status and headers:

```php
return View::response(
    'Orders.Created',
    ['order' => $order],
    status: 201,
    headers: [
        'X-Order-Id' => (string) $order->id,
        'Cache-Control' => 'private, no-store',
    ],
);
```

`View::response()` constructs the existing `Response`, so its status range and header-name/value validation apply. Header lookup and replacement are case-insensitive. An explicit caller `Content-Type` replaces the HTML default without creating a duplicate. `withHeader()`, `withoutHeader()`, `withContent()`, and the Phase 15C `withCookie()` method keep their copy-returning `Response` behavior; changing the returned body or adding a cookie does not render the View again. A response middleware may attach or replace headers as usual. Status, headers, and cookies belong to this response call, not the compiled template, and can differ on the next request. See [HTTP Responses](Responses.md) for the typed cookie contract.

An application can deliberately return a page with `status: 404` or `status: 422`. A missing View is still a rendering exception; it does not automatically become an application-selected 404 page. The normal Response sender also suppresses bodies for HEAD and bodyless statuses according to its existing contract.

## One rendering path

`View::response()` eagerly calls the non-echoing full-render path, `View::renderResult($view, $data)`, then places its finalized `html()` in the response body. It does not echo the page while constructing the response. The normal HTTP sender writes the body once when the returned response is sent. Read `content()` on the returned response to inspect its already rendered HTML. Phase 15C's deferred `response()->stream()` is a separate body-production choice and does not make template rendering lazy.

The established APIs keep their distinct purposes:

| API | Result |
| --- | --- |
| `View::render($view, $data)` | Emits the complete page for the legacy-compatible output path. It does not return a `Response`. |
| `View::renderResult($view, $data)` | Captures full-page HTML and finalized asset stacks in a transport-neutral `ViewRenderResult`. |
| `View::response($view, $data, $status, $headers)` | Captures that same full-page HTML and returns a normal HTTP `Response`. |
| `View::fragment($view, $name, $data)` | Renders only the selected body and returns `FragmentRenderResult`; the application chooses its transport. |

Return the response directly from controller code. Do not call `View::render()` first for the same page: that call writes output separately. Layouts, sections, includes, components, props, slots, forms, security presentation, template utilities, and template-owned asset stacks all run through the existing full-page renderer. A root composer and reached template code run once per `View::response()` call; request providers retain their existing request scope. Template asset stacks remain in the resulting HTML at their normal layout locations, not in HTTP headers.

```php
use App\Plugins\{Route, View};

Route::path('/orders')->get(static function () {
    return View::response('Orders.Index', ['orders' => []]);
});
```

## Package Views and compiled output

The same API accepts an active Package's explicit namespace:

```php
return View::response(
    'Commerce::Orders.Index',
    ['orders' => $orders],
);
```

The current safe application override takes precedence over the Package source. A disabled or unavailable Package namespace cannot be revived by an override or a compiled artifact. Ordinary View and Package View responses use the same source containment, compiler, and Application-owned `Storage/Views` artifacts as `View::render()`. `view:cache` warms instructions without rendering a response; `view:clear` removes only derived compiled Views. Neither command caches status, headers, response objects, or request state.

## Errors and testing

Rendering completes before the `Response` is constructed. A missing or unsafe root View still raises `ViewNotFoundException`; malformed source raises `CompilerException`; ordinary runtime failures retain the View exception contract. Inside an HTTP request, the existing `ExceptionHandler` applies the development diagnostic or production-safe response. A failure does not return a partially rendered View response. Intentional HTTP exceptions thrown during rendering retain their established status and headers.

Use the real HTTP test client when status, headers, middleware, or once-only body output matters:

```php
$this->get('/orders')
    ->assertOk()
    ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
    ->assertContains('Orders');
```

Use `$this->view('Orders.Index', $data)` for direct renderer and asset assertions. It returns a `ViewTestResult`, not an HTTP response. Use `$this->fragment(...)` for a selected Fragment. A Fragment result does not automatically become a View response, and SqueHub does not infer an AJAX transport.

See [Views](Views.md), [HTTP requests and responses](Http.md), [Fragments and Partial Responses](Fragments.md), [Package and Namespaced Views](PackageViews.md), [Compiled Views and Production Lifecycle](CompiledViews.md), and [View Diagnostics and Testing](ViewDiagnosticsTesting.md).

Phase 14P is implemented in the local working tree and included in the reported Phase 14 Windows/SQLite and Linux/SQLite qualification. Phase 14 is complete within those test profiles. The wider v2.0.0 release gate remains open.
