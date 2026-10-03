# Build a small application

This walkthrough uses the current path-first routing API and `App\Plugins` imports. It assumes [Installation](Installation.md) is complete. Application PHP files belong under `Project/`; there is no requirement to edit framework classes.

## 1. Add a route without a controller

In `Project/Routes/Web.php`:

```php
<?php

use App\Plugins\Route;

Route::path('/welcome')->get(function (): void {
    echo 'Welcome';
});
```

`Route` is also available as a short global name in an unnamespaced route file. An explicit import makes dependencies clear to editors and readers. A closure may echo output or return a string. Run `php squehub route:list`, then visit the URL printed by `php squehub start` with `/welcome` appended. You can use [SqueHub Dev](Dev.md) instead for Doctor preflight plus the same local server. Put ordinary application routes in `Project/Routes/`; the loader reads nested route files too.

## 2. Add a controller and a view

Create `Project/Controllers/WelcomeController.php`:

```php
<?php

namespace Project\Controllers;

use App\Plugins\View;

final class WelcomeController
{
    public function show(): void
    {
        View::render('Welcome.Show', ['name' => 'SqueHub']);
    }
}
```

Create `Project/Views/Welcome/Show.squehub.php`:

```html
<h1>Welcome, {{ $name }}</h1>
```

Register it in `Project/Routes/Web.php`:

```php
use Project\Controllers\WelcomeController;

Route::path('/welcome')->get([WelcomeController::class, 'show'])->named('welcome');
```

Replace the earlier `/welcome` closure rather than registering the same method and path twice. Controllers are ordinary PHP classes resolved through the container. `View::render()` writes output, and the HTTP dispatcher captures it. `{{ }}` escapes HTML. See [Routing](Routing.md), [HTTP](Http.md), and [Views](Views.md).

## 3. Add a form with validation

In a `.squehub.php` template:

```html
<form method="POST" action="/contact">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" value="{{ old('email', '') }}">
    @php $emailError = $errors->first('email'); @endphp
    @if ($emailError)
        <p>{{ $emailError }}</p>
    @endif
    <button type="submit">Send</button>
</form>
```

In a controller method:

```php
use App\Plugins\Request;

public function submit(Request $request): \App\Plugins\Response
{
    $data = $request->validate([
        'email' => 'required|email',
    ]);

    // Handle the validated address with an application service.
    return response()->redirect('/welcome');
}
```

Register `Route::path('/contact')->post([ContactController::class, 'submit']);`. CSRF runs before route middleware and controller code. For browser validation failures, SqueHub can redirect back to a safe internal page and flash `$errors` and `old()` input. JSON requests receive structured 422 errors. See [Forms](Forms.md), [Validation](Validation.md), and [CSRF](Csrf.md).

## 4. Add database state when needed

Configure a disposable or intended database in `.env`; then write a migration in `Database/Migrations/`, run `php squehub migrate`, and create an application model in `Project/Models/`. The [Migrations](Migrations.md) guide has the exact class naming and command contract. Modern Models extend `App\Plugins\Model`:

```php
<?php

namespace Project\Models;

use App\Plugins\Model;

final class Note extends Model
{
    protected string $table = 'notes';
    protected array $fillable = ['title'];
}
```

After a matching `notes` table exists:

```php
$note = Note::create(['title' => 'My first note']);
$notes = Note::query()->sort('id', 'desc')->all();
```

`$notes` is a `ModelCollection`; raw `db('notes')->all()` returns associative row arrays. Model mass assignment is guarded by default, so explicitly name allowed fields. Database connections open on first database use. See [Database](Database.md), [Models](Models.md), and [Schema](Schema.md).

## Next steps

Use [Plugins](Plugins.md) for short application imports, [Middleware](Middleware.md) for request checks, [Authentication](Authentication.md) and [Authorization](Authorization.md) when identity matters, and [Feature status](FeatureStatus.md) to distinguish available APIs from future plans.
