# Controllers

A controller is an ordinary PHP class. It does not need a framework superclass. Put application controllers under `Project/Controllers/`, and use Composer's `Project\` namespace. Route handler classes are resolved through the Application container, so constructor dependencies can be injected.

`php squehub make:controller ProfileController` creates a minimal class; use `--preview` to inspect its target before writing. Nested names and existing-Package targets are described in [Generators](Generators.md).

```php
<?php

namespace Project\Controllers;

use App\Plugins\Request;
use App\Plugins\Response;
use App\Plugins\View;
use Project\Services\ProfileService;

final class ProfileController
{
    public function __construct(private ProfileService $profiles)
    {
    }

    public function show(Request $request, string $id): void
    {
        View::render('Profiles.Show', [
            'profile' => $this->profiles->find($id),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        $this->profiles->update($id, $data);

        return response()->redirect('/profiles/' . rawurlencode($id));
    }
}
```

```php
use App\Plugins\Route;
use Project\Controllers\ProfileController;

Route::path('/profiles/{id}')->get([ProfileController::class, 'show']);
Route::path('/profiles/{id}')->post([ProfileController::class, 'update']);
```

Action methods may request the current `Request` and named route parameters. Constructor dependencies use Container bindings or automatic concrete-class construction; action methods do **not** receive arbitrary service injection. A required `{id}` is a single decoded path segment. Use `$request->route('id')` when the parameter is not in the method signature. Route values are untrusted input; validate and authorize them before database writes.

An action can return `Response`, an array for JSON, a scalar body, or `null` after echoing a view. `View::render()` emits output; it does not produce a returnable Response. To return a full page, an action may declare `: Response` and `return View::response('Profiles.Show', ['profile' => $profile]);`. The View is rendered once without echoing, and the normal dispatcher and middleware handle the Response. See [Returnable View Responses](ViewResponses.md) for status and headers, and [HTTP](Http.md) for normalization. An invokable controller can expose public `__invoke()`. `Route::resource('/users', UserController::class)` maps `index`, `show`, `store`, `update`, and `destroy`; see [Routing](Routing.md) for its exact methods and names.

Legacy `Controller@method` strings are still accepted through the compatibility route bridge. New code should use class references so editors and refactoring tools can follow them. A route-level [middleware](Middleware.md) can reject a request before a controller runs. Controllers should remain small: let dedicated services own business work and use `App\Plugins` for concise framework imports.
