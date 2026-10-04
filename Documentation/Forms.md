# Forms and Validation UX

Write ordinary HTML forms. SqueHub supplies a few small helpers around its existing Request validation, Session flash data, ErrorBag, and CSRF middleware. These View features present validation state; they do not replace server-side validation or create another form framework.

## A complete edit form

An HTML browser submits `POST` even when an application route updates a resource with `PATCH`. `@method('PATCH')` emits the hidden `_method` field that selects the effective route method. Keep `@csrf`: spoofing never bypasses the CSRF check.

```php
<form method="POST" action="{{ route('account.update') }}">
    @csrf
    @method('PATCH')

    <label for="name">Name</label>
    <input
        id="name"
        name="name"
        value="{{ old('name', $user->name) }}"
        aria-invalid="{{ $errors->has('name') ? 'true' : 'false' }}"
    >
    @error('name')
        <p id="name-error" role="alert">{{ $message }}</p>
    @enderror

    <input type="hidden" name="newsletter" value="0">
    <label>
        <input
            type="checkbox"
            name="newsletter"
            value="1"
            {{ checked((string) old('newsletter', $user->newsletter ? '1' : '0') === '1') }}
        >
        Send me updates
    </label>

    <label for="country">Country</label>
    <select id="country" name="country">
        <option value="ng" {{ selected(old('country', $user->country) === 'ng') }}>Nigeria</option>
        <option value="gh" {{ selected(old('country', $user->country) === 'gh') }}>Ghana</option>
    </select>

    <button type="submit">Save</button>
</form>
```

The hidden `newsletter=0` control gives an unchecked checkbox an explicit submitted value. Without it, an unchecked checkbox is absent from the browser request. The `checked()` and `selected()` expressions deliberately use strict comparisons; the helpers do not guess how a submitted string should compare with a Model value. Application templates choose their own classes, IDs, labels, and ARIA relationships.

Conditional CSS classes use the separate `classes()` helper. For example, `class="{{ classes(['field', 'field-invalid' => $errors->has('name')]) }}"` preserves HTML escaping while selecting the error class. It does not change the boolean attribute helpers or perform validation. See [Template Utilities](TemplateUtilities.md).

A matching route validates the effective request as usual:

```php
use App\Plugins\{Request, Route};

Route::path('/account')->patch(static function (Request $request): string {
    $data = $request->validate([
        'name' => 'required|string|max:100',
        'newsletter' => 'required|boolean',
        'country' => 'required|in:ng,gh',
    ]);

    // Persist $data through your application service.
    return 'Saved';
})->named('account.update');
```

The route syntax above represents a normal `PATCH` route. The form still transports `POST`. When rendering the current request, `$request->method()` is the effective `PATCH`; `$request->transportMethod()` remains `POST`.

The form action uses the named route rather than a literal `/account` URL. At root it renders `/account`; with `APP_BASE_PATH=/app` it renders `/app/account`, while the route declaration remains `/account`. `@csrf` and `@method` remain unchanged. SqueHub does not rewrite arbitrary literal form actions in a compiled template; use `route()` when the action belongs to the application. See [Routing](Routing.md#names-and-urls) and [mounted requests](Http.md#url-base-path-and-mounted-requests).

## Failed submission and old input

The established browser flow is:

```text
GET edit form → POST form → CSRF → route and validation
                                        ↓ failure
                                303 redirect back
                                        ↓
                         one subsequent GET with old input and errors
```

`Request::validate()` remains the authority. An unsafe HTML form request with a safe previous internal GET destination receives a `303 See Other` response on validation failure. SqueHub flashes field errors and filtered body input for the next request. If there is no safe destination, it returns an escaped HTML 422 page without flashing. JSON and API validation responses remain 422 rather than redirecting. A missing or invalid CSRF token returns 403 before the controller runs and flashes nothing.

For a form posted to `/app/account`, the safe redirect-back destination stays inside `/app`; it is not emitted as `/account` or doubled to `/app/app/account`. Flash errors and old input are available to the following mounted GET under the usual Session rules. The Session cookie itself keeps its configured `SESSION_PATH` (default `/`); set `SESSION_PATH=/app` deliberately when the browser cookie should be scoped to this mount. See [Sessions](Sessions.md) and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

`old('name', $fallback)` reads that one-request flash data; it does not query a Model. An existing flashed key wins over the fallback even when its value is `null`, `false`, `0`, `''`, or `[]`. A missing key returns the fallback, or `null` when no fallback was supplied. Dot paths such as `old('profile.name')` follow the existing nested input structure. `old()` returns data without HTML escaping, so place text values in escaped `{{ ... }}` output. Do not use raw `{!! ... !!}` for submitted text.

Passwords, tokens, secrets, credential-like keys, uploads, and nested sensitive values are omitted from flashed old input. The CSRF field and `_method` are form metadata, not values to repopulate with `old()`. Never repopulate a password field after validation failure. Error and old-input reads do not consume the flash value; Session expiry removes it after the next request.

The previous destination is a recorded internal path from a successful browser GET/HEAD response. It has no query or fragment. If necessary, a same-origin Referer can provide the path after strict scheme, host, and port checks. External and malformed destinations are rejected. Application code can use the same policy with `return redirectBack($request, '/fallback');`, which defaults to status 303 and requires an explicit Request from this Application's mount. See [Global helpers](Helpers.md#responses-and-redirects), [Sessions](Sessions.md), [Validation](Validation.md), and [HTTP](Http.md) for the underlying lifecycle.

An application can explicitly render a named [Fragment](Fragments.md) containing the form on a request with validation errors. The Fragment reads the same current `$errors` bag, `old()` input, CSRF field, method field, and boolean helpers as a full page. For example, place the form inside `@fragment('account.form') ... @endfragment`, then call `View::fragment('Account.Edit', 'account.form', ['user' => $user])` in a controller that deliberately chooses a partial response. This does not change the validation failure or flash lifecycle above, and it does not automatically turn an AJAX header into a Fragment response. Keep untrusted old input and error messages in escaped `{{ ... }}` output.

## Field errors and summaries

`$errors` is the framework-managed, read-only ErrorBag available in ordinary Views, partials, and components. The bag provides `any()`, `has($field)`, `first($field)`, `get($field)`, and `all()`. `first()` returns the first message or `null`; `get()` returns that field's ordered message list. `all()` is a **field-indexed map** of lists, in validation order. Repeated reads do not consume messages. `errors()` resolves the same current bag from PHP application code.

`@error('name') ... @enderror` renders only when the field has a message. Inside its block, `$message` is that field's first message. The compiler restores a preexisting `$message` afterward; an absent error does not leak one. A trusted PHP field expression may select a repeated field, for example `@error('items.' . $loop->index . '.name')` inside a loop. Nested `@error` blocks are rejected. Escape messages with `{{ $message }}` because custom messages are application data, not automatically trusted HTML.

For a summary, use the same bag. SqueHub does not copy messages into a second store or force summary markup:

```php
@if ($errors->any())
    <div role="alert" aria-labelledby="error-summary-title">
        <h2 id="error-summary-title">Please correct these fields</h2>
        <ul>
            @foreach ($errors->all() as $field => $messages)
                @foreach ($messages as $message)
                    <li>{{ $message }}</li>
                @endforeach
            @endforeach
        </ul>
    </div>
@endif
```

This keeps validation order and preserves repeated or identical message strings. When the bag is empty, the wrapper does not render. `$errors->has('name')` can drive a class or `aria-invalid` value, but SqueHub does not alter arbitrary HTML attributes for you. Named error bags are not supported.

## Checked, selected, and repeated fields

`checked($condition)` and `selected($condition)` require boolean arguments. They return the fixed strings `checked` and `selected`, respectively, or `''` when false. They contain no submitted value, and normal escaped `{{ ... }}` leaves those fixed fragments intact. Pass a deliberate boolean expression rather than relying on loose conversion:

```php
<input type="radio" name="plan" value="pro" {{ checked(old('plan', $user->plan) === 'pro') }}>

<option value="ng" {{ selected(old('country', $user->country) === 'ng') }}>Nigeria</option>
```

For a multiple select, use the submitted array and strict membership rather than a separate collection helper:

```php
@foreach ($roles as $role)
    <option value="{{ $role->id }}" {{ selected(in_array((string) $role->id, old('roles', $userRoleIds), true)) }}>
        {{ $role->name }}
    </option>
@endforeach
```

Normalize `$userRoleIds` to a list of strings in application code for this example. The `roles[]` browser name arrives as an array; SqueHub does not invent a value for an absent field. Validation still determines which array entries are accepted.

## Method spoofing boundary

`@method('PUT')`, `@method('PATCH')`, and `@method('DELETE')` emit a hidden form field. Lowercase spellings normalize to uppercase. An invalid helper argument fails safely rather than emitting an arbitrary method. Plain PHP may call `method_field('PATCH')` for the same fixed markup.

Only an actual `POST` form body may override the method, and only with a scalar string `_method` equal to `PUT`, `PATCH`, or `DELETE` after normalization. Standard URL-encoded and multipart form bodies qualify; manually constructed form Requests with no Content-Type also qualify. A malformed or unsupported `_method` leaves the request as `POST`. A GET request, query string, cookie, header, JSON body, other non-form content, or repeated/ambiguous Content-Type cannot gain method override authority. The effective method is set before global CSRF, route matching, and route middleware; a spoofed unsafe method still requires the usual session token. Header-based override is not supported.

## Partial and component forms

A partial included in a page inherits the current `$errors` binding and can call `old()`, `checked()`, `selected()`, `@csrf`, `@method`, and `@error` normally. A component template has the narrower [declared prop interface](Components.md): pass ordinary values explicitly, while its framework `$errors` binding and request-aware helpers remain available. Slot content is evaluated in the caller's scope and can use the same error block. No built-in form component library or automatic Model binding is introduced.

```php
@component('Forms.Input', [
    'name' => 'email',
    'value' => old('email', $user->email),
])
@endcomponent
```

An application can define `Project/Views/Components/Forms/Input.squehub.php` for that invocation:

```php
@props(['name', 'value' => ''])

<label for="{{ $name }}">{{ $name }}</label>
<input id="{{ $name }}" name="{{ $name }}" value="{{ $value }}">
@error($name)
    <p role="alert">{{ $message }}</p>
@enderror
```

The component receives `name` and `value` because it declares them; `$errors` remains a framework binding. Its markup, label text, and accessibility relationships are application choices, not a generated SqueHub form component.

Compilation stores rendering instructions, not a visitor's token, old input, or errors. Rendering the same cached template in a later request resolves current Session and Request state. These helpers add no CLI command or JavaScript dependency.

An application may present a success notice after redirect with `@session('status') ... @endsession`, using `{{ $value }}` for escaped text. It reads the existing Session flash value while active; there is no separate form-notice store. Auth or Authorization directives may hide a form or action link, but the receiving route still needs its own middleware and server-side authorization. See [Auth, Guards, Session and Authorization](ViewSecurity.md).

Use escaped output for submitted values and messages. Do not treat templates as an authorization boundary or let untrusted users edit `.squehub.php` source. For syntax and compiler diagnostics, see [Reliable Template Compiler](TemplateCompiler.md); for HTML escaping, see [Views](Views.md); for the security boundary, see [CSRF](Csrf.md).

In an application test, `withErrors()` and `withOldInput()` establish real Session-backed form state before `$this->view('Account.Form')`. `ViewTestResult::assertSeeEscaped()` checks an escaped error or old value; `assertHasCsrfField()` and `assertHasMethodField('PATCH')` check the framework's rendered hidden fields without hard-coding a token. See [View Diagnostics and Testing](ViewDiagnosticsTesting.md) for a complete example. Use the HTTP test client to test CSRF enforcement and validation redirects.
