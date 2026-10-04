# Build a small application

This walkthrough uses the current path-first routing API and `App\Plugins` imports. It assumes [Installation](Installation.md) is complete and the local server is running. Application PHP files belong under `Project/`; there is no requirement to edit framework classes. Steps 1–3 build a working route, page, and validation form. The database step is optional.

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

Replace the `/welcome` closure in `Project/Routes/Web.php` with the controller route (keep the existing `use App\Plugins\Route;` import):

```php
use Project\Controllers\WelcomeController;

Route::path('/welcome')->get([WelcomeController::class, 'show'])->named('welcome');
```

Controllers are ordinary PHP classes resolved through the container. `View::render()` writes output, and the HTTP dispatcher captures it. `{{ }}` escapes HTML. Refresh `/welcome` to see the template. See [Routing](Routing.md), [HTTP](Http.md), and [Views](Views.md).

## 3. Add a form with validation

Create `Project/Views/Contact/Create.squehub.php`:

```html
<h1>Contact</h1>
<form method="POST" action="{{ route('contact.store') }}">
    @csrf
    <label for="email">Email</label>
    <input id="email" name="email" type="email" value="{{ old('email', '') }}"
           aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}">
    @error('email')
        <p id="email-error" role="alert">{{ $message }}</p>
    @enderror
    <button type="submit">Continue</button>
</form>
```

Create `Project/Controllers/ContactController.php`:

```php
<?php

namespace Project\Controllers;

use App\Plugins\Request;
use App\Plugins\Response;
use App\Plugins\View;

final class ContactController
{
    public function create(): void
    {
        View::render('Contact.Create');
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'email' => 'required|email',
        ]);

        // Pass $data['email'] to your application service when adding delivery.
        return response()->redirect(route('welcome'), 303);
    }
}
```

Add both routes to `Project/Routes/Web.php` and import `ContactController` at the top:

```php
use Project\Controllers\ContactController;

Route::path('/contact')->get([ContactController::class, 'create'])->named('contact.create');
Route::path('/contact')->post([ContactController::class, 'store'])->named('contact.store');
```

Visit `/contact`, submit a valid address, and expect a 303 redirect to `/welcome`. The example validates input but does not store or send it; add that operation at the marked application-service line before using the form for real contact requests. Submit an empty field to see the server-side error after SqueHub redirects back to the safe GET page. The named form action and redirect also work under `APP_BASE_PATH`. CSRF runs before route middleware and controller code; a missing token returns 403. JSON requests receive structured 422 validation errors. See [Forms](Forms.md), [Validation](Validation.md), and [CSRF](Csrf.md).

## 4. Add database state when needed

Configure a disposable or intended database in `.env` and check it with `php squehub doctor`. Generate a migration, then add the `title` column to its generated `up()` method:

```bash
php squehub make:migration create_notes_table
```

The generator creates a dated file under `Database/Migrations/` with a `CreateNotesTable` class, an `id` column, and a matching rollback. The resulting `up()` method should contain:

```php
public function up(PDO $pdo, Schema $schema): void
{
    $schema->create('notes', static function (Table $table): void {
        $table->id();
        $table->string('title', 190);
    });
}
```

Keep the generated imports for `PDO`, `Schema`, and `Table` and its `down()` method. Review the other pending migrations in this source before running `php squehub migrate`, which applies all pending files against the selected database. Then create `Project/Models/Note.php`. Modern Models extend `App\Plugins\Model`:

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

Use [Plugins](Plugins.md) for short application imports, [Middleware](Middleware.md) for request checks, [Authentication](Authentication.md) and [Authorization](Authorization.md) when identity matters, and [public status](Status.md) for supported capabilities and limits.
