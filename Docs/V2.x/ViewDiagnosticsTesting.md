# View Diagnostics and Testing

SqueHub identifies View failures by their **logical View name**, source line where known, and the dependency being resolved. Application tests can render a View through the real Application and assert its output without making an HTTP request. These are parts of the existing View and [testing](Testing.md) systems; there is no second renderer or separate diagnostics service.

## Read a compiler diagnostic

A structural template error is an `App\View\Compiler\CompilerException`. For example, an unclosed directive produces a message equivalent to:

```text
View "Orders.Index", line 18: Unterminated @if opened at line 18.
```

The exception exposes `view()`, `sourceLine()`, `reason()`, optional `directive()`, and `dependencyChain()` so tools and tests can inspect the source identity without parsing message text. Lines refer to the original `.squehub.php` source, with LF and CRLF supported. The compiler does not promise a source column. A multiline malformed directive is attributed to its opening line. Cached compilation retains static source metadata; a changed source body receives a new compiled identity and fresh diagnostics.

Compiler diagnostics cover recognized SqueHub constructs, including escaped/raw output, control flow, loops, layouts, sections, stacks, resources, includes, components, props, slots, form directives, security presentation, JSON output, and Fragments. Unknown directive prefixes remain ordinary template text. The compiler checks structure; it does not validate every PHP expression before that expression executes.

## Missing Views and dependencies

Direct `View::render('Missing.Page')`, `View::renderResult('Missing.Page')`, `View::response('Missing.Page')`, and `View::fragment('Missing.Page', 'body')` throw `App\View\ViewNotFoundException`. It exposes `view()` and `unsafe()`; there is no fabricated source line for a file that was not found. `unsafe()` is true for rejected broken or outside-root links, and neither source content nor a compiled fallback is emitted. Earlier v2 milestones returned error markup from direct `View::render()` for unavailable roots; Phase 14M intentionally replaced that result with an exception so HTTP rendering reaches the generic production 500 boundary. Selecting a valid but absent Fragment name on an existing root View raises `App\View\FragmentNotFoundException` with `view()` and `fragment()` accessors, likewise without inventing a source line for the selection call. An invalid public Fragment name is rejected before View lookup with `App\View\InvalidFragmentNameException`; it exposes `view()` but omits the untrusted name from its message. A missing required layout, include, or component identifies the requested logical dependency through the in-template `CompilerException`. Static dependency directives carry their caller's logical View and original source line. For example:

```text
View "Orders.Index", line 26: Component "OrderCard" was not found.
```

An absent `@includeOptional('Partials.Promo')` still emits nothing. `@includeWhen(...)` with a false condition does not resolve or execute its target. An unsafe, broken, or outside-root source cannot be treated as a successful optional include. An invalid `@fragment(...)` declaration is a source-aware compiler error; it is distinct from `InvalidFragmentNameException` raised by an invalid public selection argument. Application code can import the catchable diagnostic exceptions through `App\Plugins`; the internal HTTP-preserving View wrapper has no Plugins alias.

SqueHub uses canonical physical source identity internally for containment and cycle detection. Diagnostics display logical names, including a useful active chain for layout, include, component, and mixed component/include cycles. For example:

```text
View Pages.Index -> Component Components.Card -> Include Partials.Actions -> Component Components.Card
```

The chain is the **active** render path. Rendering `Components.Card` again after the first invocation finishes is valid. An in-root symlink alias does not evade cycle detection, even though its canonical target path is not shown in the diagnostic.

## Runtime failures and production privacy

If trusted PHP inside a template throws an ordinary runtime error, SqueHub wraps it in `App\View\ViewRenderException`. This exposes `view()`, `category()`, and `reason()`, with the original exception available through `getPrevious()`. Its public reason is generic for arbitrary PHP exceptions and does **not** map a generated-PHP line number back to an original template line.

A generic `HttpException` thrown while rendering is wrapped in the internal `App\View\ViewHttpException`: its original HTTP status and headers remain intact, its public message identifies only the logical View, and `getPrevious()` retains the cause. Managed Auth, Authorization, Validation, CSRF, rate-limit, and API errors keep their existing HTTP handling. Runtime View wrappers do not claim an original source line. Do not treat a stack-frame line in compiled cache PHP as a line in `.squehub.php`.

Development and direct-test diagnostics are intended to explain the logical problem. The debug HTTP page renders recognized logical View exception messages as escaped HTML without a generated-file path or stack trace; routine 404 handling retains its existing limited output. Production 5xx HTTP responses still use the existing generic safe error boundary, while intentional 4xx HTTP statuses retain their normal status handling. They do not disclose physical or compiled paths, cache hashes, source excerpts, props, slot content, render data, old input, CSRF tokens, Session values, Auth identities, Authorization resources, or stack traces. Detailed diagnostics are not a variable inspector.

## Render a View in an application test

Extend `App\Plugins\TestCase`, write a View fixture in its owned temporary Application, and call `view()`:

```php
use App\Plugins\TestCase;

final class OrderViewTest extends TestCase
{
    public function test_orders_render_in_sequence(): void
    {
        $this->testApplication()->write(
            'Project/Views/Orders/Index.squehub.php',
            <<<'VIEW'
<h1>Orders</h1>
@foreach ($orders as $order)
    <p>{{ $order }}</p>
@endforeach
VIEW
        );

        $this->view('Orders.Index', [
            'orders' => ['Order A', 'Order B'],
        ])
            ->assertSee('<h1>Orders</h1>')
            ->assertSeeInOrder(['Order A', 'Order B'])
            ->assertDontSee('Order C');
    }
}
```

`view()` uses the same Application-owned context, resolver, compiler, layouts, includes, components, assets, Auth, Session, and form helpers as `View::render()`. It returns a `ViewTestResult`, **not** an HTTP Response. Its `html()` contains the finalized full-page HTML. The public, transport-neutral `View::renderResult($view, $data)` is the underlying full-render capture operation; application tests normally use `$this->view(...)` for assertions. Use the existing `$this->get(...)->assertOk()` style when the route, middleware, status, or headers are part of the behavior under test.

## Output and escaping assertions

`assertSee($needle)` and `assertDontSee($needle)` use exact, case-sensitive substring checks on the rendered HTML. They do not strip tags, normalize Unicode, or treat the needle as a regular expression. `assertSeeEscaped($value)` applies the same `ViewEscaper` contract as `{{ }}` before checking. This catches an expected escaped value without hand-writing HTML entity rules:

```php
$this->view('Orders.Index', ['label' => '<script>alert(1)</script>'])
    ->assertSeeEscaped('<script>alert(1)</script>')
    ->assertDontSee('<script>alert(1)</script>');
```

`assertSeeInOrder(['A', 'A', 'B'])` searches forward after each match, so the same occurrence cannot satisfy both `A` entries. It is useful for loop order, layout composition, and rendered asset order. Assertion failures identify the logical View or Fragment and give bounded diagnostic output; they do not dump a whole large document. Application test output may still contain expected or rendered snippets, so keep sensitive fixture values out of shared CI logs.

## Assert finalized asset stacks

Both full View and Fragment test results expose `stack($name)`, `hasStack($name)`, and `stacks()`. These are the same finalized stack values produced by the real asset renderer, including custom names. A valid undeclared stack returns `''`; invalid stack names retain the normal asset validation error. Test participation without scraping layout markup:

```php
$result = $this->view('Orders.Index', ['orders' => []]);

$result
    ->assertStackContains('scripts', '/assets/orders.js')
    ->assertStackMissing('scripts', '/assets/admin.js');
```

Direct resources, `once` keys, component ownership, conditionally reached blocks, and prepend ordering remain the production rules. A View test does not collect assets from a branch that did not render.

## Auth, Session, forms, and validation

The existing `TestCase::actingAs()` and `guest()` use the real configured stateful Auth guard. They do not make a View directive an authorization boundary:

```php
$this->actingAs($user);

$this->view('Dashboard.Index')
    ->assertSee('Dashboard')
    ->assertDontSee('Sign in');
```

Named guards use `actingAs($user, 'admin')` when that guard is configured. Configure real Authorization abilities or policies before testing `@can`/`@cannot`; do not fake an allow result inside the View test helper. The existing `session()` helper accesses the fixture's real Session store.

For a direct browser-form View test, establish errors and old input through the test Session, then assert the actual rendered result:

```php
$this->withErrors(['email' => ['Invalid email.']]);
$this->withOldInput(['email' => 'invalid@example.test']);

$this->view('Account.Form')
    ->assertSeeEscaped('Invalid email.')
    ->assertSee('invalid@example.test')
    ->assertHasCsrfField()
    ->assertHasMethodField('PATCH');
```

`assertHasCsrfField()` checks the configured hidden field name and the current nonempty Session-bound token against the rendered field. It does not print the token in ordinary assertion failure output. `assertHasMethodField('PATCH')` checks the supported hidden method-field contract. These assertions test output only; the existing HTTP client and global CSRF middleware still test request enforcement. `withErrors()` and `withOldInput()` use the normal Session/flash data path, not a View-local substitute. Escape untrusted old input and messages in the template with `{{ }}`.

## Test an independent Fragment

Use the same assertion model for a selected [Fragment](Fragments.md):

```php
$this->fragment('Orders.Index', 'orders.list', ['orders' => $orders])
    ->assertSee('Order A')
    ->assertDontSee('Page footer')
    ->assertStackContains('scripts', '/assets/orders.js');
```

The helper calls the real `View::fragment()` path. It skips unrelated siblings and layout code, so the Fragment result may have different HTML and stack participation than a full `view()` result. Assertion failures identify both the root logical View and Fragment name. It does not infer a Fragment from an AJAX request or create an HTTP transport.

## Isolation and scope

Each test fixture owns a disposable Application and View root. Each direct `view()` or `fragment()` call establishes a fresh minimal GET Request scope for request providers, while Session and Auth stay lazy unless the template or assertion needs them. Repeated renders use current data, Auth state, errors, and asset state; they do not reuse another test's rendered HTML or stacks. Use an HTTP request helper when route middleware, CSRF enforcement, status, or headers are part of the behavior under test.

These helpers do not add snapshot testing, a fake renderer, or a component-only testing API. They accept an active Package's explicit logical identity, such as `$this->view('Commerce::Orders.Index')` or `$this->fragment('Commerce::Orders.Index', 'orders.list')`; missing namespaced dependencies retain the caller's logical View and source line where known. They use the same [compiled View lifecycle](CompiledViews.md) as ordinary requests: a source change selects new compiled output, and a damaged derived artifact is rebuilt when safe. `php squehub view:cache` and `view:clear` operate on those artifacts without changing the test assertion API. Test a [returnable View response](ViewResponses.md) through the HTTP client when status, headers, middleware, or final body output matters. See [Package and Namespaced Views](PackageViews.md) and the [roadmap](Roadmap.md).
