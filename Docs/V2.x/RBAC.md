# Roles and permissions

Phase 17B adds optional, Application-owned role and permission storage. Authentication resolves the identity, RBAC records grants, and the existing [Authorization](Authorization.md) manager makes the decision. A small application can continue using only global abilities and resource policies.

## Enable storage

Set `Config/Rbac.php`:

```php
return [
    'enabled' => true,
    'driver' => 'database', // or 'array' for one Application lifetime
    'connection' => null,   // or a configured Database connection name
];
```

The default is disabled: checks return false and mutation calls fail clearly. The database driver uses `rbac_roles`, `rbac_permissions`, `rbac_role_permissions`, and `rbac_identity_roles`. Run the explicit `Database/Migrations/2026_09_30_create_rbac_tables.php` migration through the normal migration workflow before using that driver. Bootstrap does not run it or query the RBAC tables. The array driver needs no database or Redis and loses assignments when its Application ends.

Roles and permissions are application-defined keys of at most 128 bytes: letters, numbers, dots, dashes, and underscores, beginning with a letter or underscore. SqueHub reserves no `admin`, `superadmin`, or `user` role. Keys are case sensitive. The database stores exact-key digests for lookup so a case-insensitive MySQL collation does not merge distinct keys.

## Define and assign grants

```php
use App\Plugins\Rbac;

Rbac::createRole('reporter');
Rbac::createPermission('reports.view');
Rbac::grantPermission('reporter', 'reports.view');
Rbac::assignRole($user, 'reporter');

Rbac::hasRole($user, 'reporter');             // true
Rbac::hasPermission($user, 'reports.view');   // true
```

`$user` implements `Authenticatable`. Assignments use its exact identity class and typed `authIdentifier()`, so two identity classes with the same ID do not share roles. A canonical decimal string ID and the equivalent integer ID match because database hydration may use either type; a padded string such as `"007"` remains distinct. Roles belong to the identity across its configured web and token guards. A personal access token's own abilities still narrow a token request independently.

`createRole()`, `createPermission()`, `grantPermission()`, and `assignRole()` are idempotent. Grants and assignments require their named definitions. `revokePermission()`, `removeRole()`, `deletePermission()`, and `deleteRole()` are safe to repeat; deletion removes dependent mappings through foreign keys in the database driver. `removeIdentity($user)` removes all role assignments for an identity. There are no direct identity-permission grants and no source scanning or role discovery.

When deleting an identity, call `removeIdentity($user)` in the same database transaction as the identity deletion when both use the same connection. An arbitrary application identity table cannot be a foreign-key target of this generic RBAC migration. The Auth provider denies deleted and soft-deleted identities, but cleaning assignments also prevents a later identity reusing the same key from inheriting old roles. A raw SQL identity deletion bypasses this lifecycle unless the application coordinates cleanup.

## Authorization composition

A registered RBAC permission supplies an unbound global ability of the same name:

```php
authorize()->require('reports.view');

Route::path('/reports')->get([ReportController::class, 'index'])
    ->through(['auth', \App\Plugins\RequireAbility::named('reports.view')]);
```

An explicit global ability registered in `Config/Authorization.php` or through `Gate::define()` always takes precedence, including a denial. RBAC never turns that denial into an allow. A registered permission with no role grant denies; an unknown global ability remains an Authorization configuration error. Guests deny without consulting identity grants. `@can('reports.view')` and `@cannot('reports.view')` automatically follow these same decisions. They control display only; enforce the action on its route or in its handler.

Resource subjects always use the mapped policy. A policy can deliberately combine a broad permission with an object condition:

```php
use App\Plugins\Rbac;

final class PostPolicy
{
    public function update(User $user, Post $post): bool
    {
        return Rbac::hasPermission($user, 'posts.update')
            && $post->author_id === $user->authIdentifier();
    }
}
```

This check does not select records. Continue using Model query scopes or filters for record selection. A token-only route may separately require `RequireTokenAbility`; its token ability cannot grant a denied application authorization check.

The manager does not cache resolved role sets or authorization decisions. A mutation is visible to the next check and to later Kernel requests. Authorization Diagnostics retain only aggregate allowed, denied, check, and error counts; role and permission lists are not recorded automatically. The `App\Plugins\Rbac` gateway exposes mutations and checks; repositories and identity digests remain internal.
