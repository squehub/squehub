# Application models

Put your application model classes here under the `Project\Models` namespace. New models should extend `App\Plugins\Model`, which uses the modern ORM; `App\Core\Model` remains the separate legacy compatibility API with different return contracts.

```php
<?php

declare(strict_types=1);

namespace Project\Models;

use App\Plugins\Model;

final class User extends Model
{
    protected string $table = 'users';
    protected array $fillable = ['name', 'email'];
}
```

The table and its columns must exist before this model is queried. See [Models](../../Docs/V2.x/Models.md) for queries, persistence, casts, timestamps, relationships, scopes, and soft deletes.
