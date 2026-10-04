# SqueHub v2 container foundation

The Application owns one container for its lifetime. Core service providers register HTTP, routing, database, and other services through it; modern route controllers are constructed by it. Normal application code can receive dependencies in a controller constructor without calling `make()` itself. The historical `App\Core\Service` registry remains a separate compatibility path.

```php
use App\Container\Container;

$container = new Container();
$container->bind(UserRepositoryInterface::class, DatabaseUserRepository::class);
$container->singleton(Logger::class);
$container->instance(Config::class, $config);
$container->alias(Config::class, 'config');

$service = $container->make(UserService::class);
```

`bind()` creates a new object when resolved. `singleton()` retains its first result. `instance()` returns the exact object supplied. Bindings may use a class name or a closure that receives the container and returns an object. `alias($target, $name)` gives an existing service another name. `get()` is the PSR Container entry point and delegates to `make()`.

Concrete classes with resolvable constructor dependencies are built automatically. Interfaces need explicit bindings. Required scalar parameters need a factory; the container does not read `.env` or guess values. Optional defaults and nullable unresolved dependencies are respected. Circular dependencies and aliases raise container exceptions with the resolution path.

`has()` reports explicit bindings and instances, including aliases to them. It does not report an unregistered concrete class merely because `make()` can construct it. A registered service may still fail during construction; in that case resolution throws a container exception.

The legacy `App\Core\Service` static registry remains independent for compatibility. A future compatibility adapter can connect it to the Application container after its null-on-missing behavior has a migration path.

## Use constructor injection in application code

```php
namespace Project\Controllers;

use Project\Services\UserService;

final class UserController
{
    public function __construct(private UserService $users)
    {
    }

    public function index(): array
    {
        return ['users' => $this->users->all()];
    }
}
```

The controller and `UserService` must be real application classes. A concrete dependency with resolvable constructor arguments is built automatically. If `UserService` depends on an interface, bind that interface to an implementation before boot. Route action methods receive `Request` and named route parameters, not arbitrary container services; put general dependencies in the constructor. See [Controllers](Controllers.md).

## Register services through a provider

```php
namespace Project\Providers;

use App\Plugins\ServiceProvider;
use Project\Contracts\UserRepository;
use Project\Repositories\DatabaseUserRepository;

final class UsersProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->container()->bind(
            UserRepository::class,
            DatabaseUserRepository::class
        );
    }

    public function boot(): void
    {
        // Register behavior that needs other providers' bindings here.
    }
}
```

Register `UsersProvider::class` with the Application **before** calling `bootstrap()`. Its `register()` runs alongside other providers' registration; all providers' `boot()` methods run afterward. Registration is not automatic merely because a class is in `Project/Providers/`. A provider cannot be added after bootstrap begins. A failed bootstrap propagates its error and that Application cannot be retried. See [Application](Application.md).

Use `bind()` for a new instance per resolution, `singleton()` for one retained instance, and `instance()` for an existing object. Do not make stateful request data a process-long singleton accidentally. `get()` and `make()` may throw; `has()` is an explicit-registration check, not proof that a service can be constructed. A circular constructor dependency is an application design error. A required scalar such as an endpoint or API key should come from a deliberate configuration factory, not implicit `.env` lookup.

Run `composer test` for the framework suite. Some integration tests use isolated SQLite and temporary project roots; the default suite does not use the ordinary application database.

## Application-facing Plugins import

Application code may import `App\Plugins\ServiceProvider`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
