# SqueHub v2 authorization foundation

Authentication answers **who the current identity is**. Authorization answers **what that identity may do**. The Application-owned authorization manager supports explicit abilities and resource policies; optional [roles and permissions](RBAC.md) provide grants for unbound global abilities. A [personal access token](ApiTokens.md) can narrow a request with its own abilities, but it cannot grant an identity permission that this manager denies. The default `Config/Authorization.php` contains no rules, RBAC is disabled by default, and a fresh application boots without a user table or database connection.

## Check an ability

```php
if (authorize()->allows('reports.view')) {
    // Render the report.
}

if (authorize()->denies('update', $post)) {
    // Hide an editing action.
}

authorize()->require('update', $post);

$allowed = authorize()->forIdentity($otherUser)->allows('view', $report);
```

`allows()` and `denies()` return booleans. `require()` returns normally on allow and throws `AuthorizationException` on a legitimate deny. Within an enabled [API response scope](ApiResponses.md), the Kernel returns 403 `forbidden` with a fixed safe message and request ID, without exposing the policy's message, class, identity, ability implementation, or resource contents.

Outside API scope, JSON receives `{"message":"This action is not authorized."}` by default, and HTML receives an escaped Forbidden page. A policy-authored message can replace that default. An application is responsible for keeping its custom message free of sensitive identity or resource data. There is no automatic authorization redirect.

Normal checks use `auth()->user()` through the existing guard and its per-request identity cache. With no identity, a **registered** check denies without invoking a rule or policy; an unknown ability or missing policy remains a configuration error. `forIdentity($user)` creates an immutable evaluation context; `forIdentity(null)` follows guest default deny. Neither form logs in, switches the Session identity, or impersonates anyone. Decisions are evaluated on every check and are not cached.

## Global abilities

An ability without a subject uses a named explicit global rule or, when no such rule exists, a registered [RBAC permission](RBAC.md). An explicit rule takes precedence. Names are bounded to 128 bytes and contain letters, numbers, dots, dashes, or underscores; a colon or comma is not middleware syntax. Register explicit rules in `Config/Authorization.php` using class strings:

```php
return [
    'abilities' => [
        'reports.view' => \Project\Authorization\ViewReports::class,
    ],
    'policies' => [],
];
```

The rule is an ordinary Container-resolved class with a public, non-static `check()` method. Its constructor can receive services. The identity is the method's sole argument:

```php
final class ViewReports
{
    public function check(User $user): bool
    {
        return $user->can_view_reports;
    }
}
```

Applications and package providers may also register rules programmatically after Authorization's provider boots:

```php
use App\Plugins\Gate;

Gate::define('reports.view', ViewReports::class);
Gate::define('billing.export', fn (User $user): bool => $user->may_export);
```

Provider `boot()` methods in this version have no injected parameters. `Gate` forwards to the current Application's authorization manager; a provider handling multiple Applications can instead resolve that manager from its own Application container. Runtime callables belong in providers, while cacheable configuration uses class names. Duplicate ability names fail. Rule classes are structurally validated at boot but instantiated only when checked; Authorization does not retain the instance beyond the Container's own lifetime.

## Resource policies

Map an exact resource class to a policy class:

```php
return [
    'abilities' => [],
    'policies' => [
        \Project\Models\Post::class => \Project\Policies\PostPolicy::class,
    ],
];
```

Programmatic registration is also available: `$authorization->policy(Post::class, PostPolicy::class)`. A policy is Container-resolved lazily and needs no base class or marker interface. An object check passes the identity and object; a class-level check passes only the identity:

```php
final class PostPolicy
{
    public function create(User $user): bool
    {
        return $user->may_publish;
    }

    public function update(User $user, Post $post): bool
    {
        return $post->user_id === $user->id;
    }

    public function delete(User $user, Post $post): \App\Plugins\AuthorizationDecision
    {
        return $post->locked
            ? \App\Plugins\AuthorizationDecision::deny('This record cannot be deleted.')
            : \App\Plugins\AuthorizationDecision::allow();
    }
}

authorize()->require('create', Post::class);
authorize()->require('update', $post);
```

Object subjects use their exact class. Subclasses do not inherit a mapping automatically. Class strings must name an existing class; IDs, arrays, and arbitrary strings are not resource subjects. Policy ability names must be valid non-magic PHP method identifiers. Public inherited methods may work if they satisfy the same rules. A method must be public, non-static, and accept exactly the identity plus an object subject, or only the identity for a class subject. Constructor injection handles service dependencies.

## Decisions and failures

Rules return only `bool` or `AuthorizationDecision`. `true` and `false` become immutable allow and deny decisions. `AuthorizationDecision::allow()` has no denial message; `AuthorizationDecision::deny($message)` carries an application-authored message. Other return types are configuration errors.

An unknown global ability, missing policy or method, invalid class, duplicate mapping, bad method signature, or invalid return throws `AuthorizationConfigurationException`. These are application failures and produce normal production-safe 500 responses at the HTTP boundary. Exceptions thrown by correctly invoked application policy code propagate unchanged and are reported once by the existing top-level logger. Ordinary allows, denies, and guest denials are not logged automatically.

## Route middleware

`RequireAbility::named()` checks one **global** ability before the handler. Put `auth` first when guests should receive the authentication middleware's 401 or login behavior:

```php
use App\Plugins\RequireAbility;

Route::path('/reports')
    ->get([ReportController::class, 'index'])
    ->through(['auth', RequireAbility::named('reports.view')]);
```

Without `auth`, a guest receives authorization 403. A token-protected route can put `RequireToken::guard('api')` first, then `RequireTokenAbility::named('orders.read')`, then `RequireAbility::named('orders.view')`: token authentication, token boundary, and application permission are separate checks. Global CSRF middleware still runs before route middleware. [Explicit route Model binding](Routing.md#bind-a-route-parameter-to-a-model) runs after route middleware, so that middleware reads raw route values and can reject before a Model query. Resource checks belong in controller or service code after loading or binding the subject: `authorize()->require('update', $post)`. Resource authorization middleware is still deferred. `route:list` displays the middleware class name without dumping object internals.

## Authorization in Views

`@can('reports.view')` and `@cannot('reports.view')` use the existing non-throwing decision methods for a registered global ability. Resource checks pass a subject, such as `@can('update', $post)`; `update` must name a method on the policy mapped to the resource class. Each directive supports `@else`. An ordinary denial chooses the absent or else branch; an unknown ability, missing policy, or broken policy remains an application error. The View does not cache decisions or infer permissions from guard names.

These directives control markup only. The route above still needs `RequireAbility`, and a resource action still needs `authorize()->require('update', $post)` after loading it. Direct requests never have to render the View containing a hidden button. See [Auth, Guards, Session and Authorization](ViewSecurity.md) for exact syntax, Session presentation, and the enforcement boundary.

## Diagnostics and boundaries

`diagnostics()->snapshot()['authorization']` contains only `checks`, `allowed`, `denied`, and `errors`. Guest denials count as checks and denies; configuration or policy execution failures count as checks and errors. Counters reset for every Kernel request while registered rules remain Application-owned. No ability name, policy name, identity, subject, or decision message is retained.

AuthorizationManager directly uses AuthManager, Container, and optional Diagnostics. It does not directly require Database, Session, Cache, Events, Storage, Logger, or HTTP. Policies may deliberately depend on those services. Successful caller-owned transaction rollbacks can make in-memory domain objects stale; Authorization does not provide a Unit of Work or decision cache.

An explicit global rule takes precedence over a same-named RBAC permission, including when that rule denies. Resource subjects continue to use only their mapped policy; a policy may call `Rbac::hasPermission()` and combine it with a subject condition. `RequireAbility` and the View directives use this same manager. See [roles and permissions](RBAC.md) for storage, assignment, and lifecycle details.

Deferred work includes ABAC, policy discovery or before hooks, super-admin behavior, resource middleware, authorization events, decision caching, and audit history.

## Application-facing Plugins import

Application code may import `App\Plugins\Gate`, `App\Plugins\AuthorizationDecision`, and `App\Plugins\RequireAbility`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
