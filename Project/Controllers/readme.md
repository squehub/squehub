# Application controllers

Put your application controller classes here under the `Project\Controllers` namespace. Controllers are ordinary PHP classes; the v2 router resolves them through the Application container, so constructor dependencies can be injected. A framework base class is not required.

```php
<?php

declare(strict_types=1);

namespace Project\Controllers;

final class WelcomeController
{
    public function index(): string
    {
        return 'Welcome';
    }
}
```

In `Project/Routes/`, register it with the application-facing route API:

```php
use App\Plugins\Route;
use Project\Controllers\WelcomeController;

Route::path('/welcome')->get([WelcomeController::class, 'index']);
```

See [Routing](../../Docs/V2.x/Routing.md) for methods, route parameters, groups, and middleware.
