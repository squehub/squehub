# SqueHub

**The PHP Framework for Modern Web Builders.** Simple to use. Powerful underneath.

SqueHub brings routing, Views, data, security, background work, and developer tools into one PHP application framework. Your application lives in `Project/`; framework internals live in `App/`.

> **Current source: v2.0.0 release-candidate preparation, not yet published.** The [public repository](https://github.com/squehub/squehub) and `composer create-project squehub/squehub` currently resolve to the published v1 generation. Use a complete v2 source snapshot for the steps below until the v2 release artifact and Composer installation are verified.

## Build with SqueHub

- Define routes and middleware, validate requests, and return HTML or API responses.
- Render SqueHub Views with layouts, components, forms, and escaped output.
- Use migrations, Seeders, Factories, the ORM, authentication, authorization, sessions, and CSRF protection.
- Defer work through Queue and Scheduler; use Mail, Notifications, Storage, Cache, and Events as needed.
- Organize applications with Packages and Kits. Optional frontend profiles support Vite, React, and Vue; PHP Views require no Node.js build.

The [v2 documentation](Docs/V2.x/README.md) describes the public APIs and feature-specific limits.

## Requirements

- PHP 8.2 or newer within the Composer `^8.2` constraint, and Composer.
- A PDO driver for the database you choose, such as `pdo_sqlite` or `pdo_mysql`.
- The extensions and external services required by the features you enable. Run `php squehub doctor` after configuration.

## Install from the v2 source

From a **complete v2 source snapshot**:

```bash
composer install
```

Copy the environment template. On Linux or macOS:

```bash
cp .example.env .env
```

On Windows PowerShell:

```powershell
Copy-Item .example.env .env
```

Generate an application key:

```bash
php squehub key:generate
```

Paste the complete printed `base64:` value into `APP_KEY` in `.env`. The command does **not** edit `.env`. Review the remaining environment and database settings, and keep `.env` private.

```bash
php squehub doctor
php squehub start
```

The server prints its local URL. For Apache or Nginx, use `public/` as the document root. See the [installation guide](Docs/V2.x/Installation.md) for setup options and service requirements.

## Your first route

Add this to `Project/Routes/Web.php`:

```php
<?php

use App\Plugins\Route;

Route::path('/hello')->get(static fn (): string => 'Hello, SqueHub!');
```

Run `php squehub route:list`, then visit `/hello` on the local server. The starter homepage is in `Project/Views/Home/Welcome.squehub.php`, with its stylesheet in `public/assets/css/welcome.css`. Continue with the [first application guide](Docs/V2.x/FirstApplication.md).

## Project layout

```text
App/          Framework source
Config/       Application configuration
Database/     Migrations, Seeders, and Factories
Project/      Application routes, controllers, models, Views, Packages, and Kits
public/       Web document root and public assets
Storage/      Private runtime data
Tests/        Framework test suite
squehub       CLI entry point
```

Run `php squehub help` for the available commands. Database-changing commands should run only after you select and review the intended database.

## Documentation and testing

- [v2 documentation](Docs/V2.x/README.md) for this source snapshot.
- [Upgrade from v1.x](Docs/V2.x/UpgradeFromV1.md) for migration decisions and breaking changes.
- [Published v1.x documentation](https://www.squehub.com/docs/v1.x) for the currently released generation.

From the complete development source, run:

```bash
composer validate --strict
composer test
```

## Community and project policy

Use [GitHub Issues](https://github.com/squehub/squehub/issues) for bugs and feature discussions, or visit the [SqueHub community](https://www.squehub.com/community). See [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Report vulnerabilities privately according to [SECURITY.md](SECURITY.md); do not post exploit details in a public issue. SqueHub uses the [MIT license](LICENSE).
