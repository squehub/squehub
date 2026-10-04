<div align="center">
  <a href="https://www.squehub.com/">
    <img src="public/assets/img/squehub-icon.png" alt="SqueHub logo" width="96">
  </a>
  <h1>SqueHub</h1>
  <p><strong>The PHP Framework for Modern Web Builders.</strong><br>Simple to use. Powerful underneath.</p>
  <p>
    <a href="https://github.com/squehub/squehub/actions/workflows/ci.yml"><img src="https://github.com/squehub/squehub/actions/workflows/ci.yml/badge.svg" alt="CI status"></a>
    <a href="https://www.php.net/"><img src="https://img.shields.io/badge/PHP-8.2%2B-777bb4" alt="PHP 8.2 or newer"></a>
    <a href="https://www.squehub.com/docs/v2.x"><img src="https://img.shields.io/badge/docs-v2.x-247899" alt="Documentation"></a>
    <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-green" alt="MIT license"></a>
  </p>
</div>

SqueHub brings routing, Views, data, security, background work, and developer tools into one PHP application framework. Your application lives in `Project/`; framework internals live in `App/`.

This README describes SqueHub v2.0.0. Select a versioned v2 release when installing from Composer or GitHub; an unversioned install may select a different generation.

## Build with SqueHub

- Define routes and middleware, validate requests, and return HTML or API responses.
- Render SqueHub Views with layouts, components, forms, and escaped output.
- Use migrations, Seeders, Factories, the ORM, authentication, authorization, sessions, and CSRF protection.
- Defer work through Queue and Scheduler; use Mail, Notifications, Storage, Cache, and Events as needed.
- Organize applications with Packages and Kits. Optional frontend profiles support Vite, React, and Vue; PHP Views require no Node.js build.

The [official v2 documentation](https://www.squehub.com/docs/v2.x) describes the public APIs, examples, and feature-specific limits.

The same public guides are available in this source checkout at [Documentation/README.md](Documentation/README.md).

## Requirements

- PHP 8.2 or newer within the Composer `^8.2` constraint, and Composer.
- A PDO driver for the database you choose, such as `pdo_sqlite` or `pdo_mysql`.
- The extensions and external services required by the features you enable. Run `php squehub doctor` after configuration.

## Installation

For a published v2 release on Packagist, create a project with a version constraint:

```bash
composer create-project squehub/squehub my-app "^2.0"
cd my-app
```

To work from a complete v2 source checkout instead, run:

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

The server prints its local URL. For Apache or Nginx, use `public/` as the document root. See the [official installation guide](https://www.squehub.com/docs/v2.x/installation) for setup options and service requirements.

## Your first route

Add this to `Project/Routes/Web.php`:

```php
<?php

use App\Plugins\Route;

Route::path('/hello')->get(static fn (): string => 'Hello, SqueHub!');
```

Run `php squehub route:list`, then visit `/hello` on the local server. The starter homepage is in `Project/Views/Home/Welcome.squehub.php`, with its stylesheet in `public/assets/css/welcome.css`. Continue with the [first application guide](https://www.squehub.com/docs/v2.x/first-application).

## Project layout

```text
App/          Framework source
Config/       Application configuration
Database/     Migrations, Seeders, and Factories
Project/      Application routes, controllers, models, Views, Packages, and Kits
public/       Web document root and public assets
Storage/      Private runtime data
Documentation/ Public framework guides in this source checkout
squehub       CLI entry point
```

Run `php squehub help` for the available commands. Database-changing commands should run only after you select and review the intended database.

## Documentation and testing

- [v2 documentation](https://www.squehub.com/docs/v2.x) for current APIs and guides.
- [Upgrade from v1.x](https://www.squehub.com/docs/v2.x/upgrade-from-v1) for migration decisions and breaking changes.
- [v1.x documentation](https://www.squehub.com/docs/v1.x) for historical applications.

From a complete source checkout, run:

```bash
composer validate --strict
composer test
```

## Community and project policy

Use [GitHub Issues](https://github.com/squehub/squehub/issues) for bugs and feature discussions, or visit the [SqueHub community](https://www.squehub.com/community). See [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change. Report vulnerabilities privately according to [SECURITY.md](SECURITY.md); do not post exploit details in a public issue. SqueHub uses the [MIT license](LICENSE).
