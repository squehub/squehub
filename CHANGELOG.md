# Changelog

This file summarizes developer-facing changes. The [v1 to v2 upgrade guide](Docs/V2.x/UpgradeFromV1.md) covers migration details.

## 2.0.0 — unreleased

### Application foundation

- PHP 8.2+ application runtime with container-backed services, HTTP requests and responses, route and middleware registration, validation, sessions, CSRF, and browser forms.
- SqueHub Views with layouts, components, sections, partials, safe output, fragments, compiled artifacts, and returnable View responses.
- Database connections, Schema, Migrations, modern Models and relationships, Seeders, Factories, pagination, scopes, and optional soft deletion.
- Authentication, authorization, account security, API resources and contracts, personal access tokens, optional OIDC integration, rate limiting, and signed URLs.

### Services and developer tools

- Cache, Redis integration, Storage, Mail, Notifications, HTTP Client, cryptography, Events, Queue, Scheduler, broadcasting, and webhooks with their documented drivers and limits.
- Packages and Kits with explicit activation and reviewable changes; CLI generators, SqueHub Setup, SqueHub Dev, diagnostics, Health and Doctor, and local read-only SqueHub Studio.
- Optional frontend profiles for Vite, React, and Vue; typed application data, reliability primitives, deployment and project-bundle tools, and bounded local Agent/MCP integration.
- Versioned v2 guides and an upgrade path from v1.x. Publication and clean-install proof remain release gates.

### Breaking changes from v1.x

- Minimum PHP version is 8.2. Application source belongs under canonical `Project/`, with routes under `Project/Routes/`; `App/Routes/` is no longer a route source.
- New applications use `Route::path(...)` and the documented `App\Plugins` developer-facing symbols. Review legacy router and helper usage against the upgrade guide.
- Application Views resolve from `Project/Views/`, `Project/PackagesViews/`, and activated Package View namespaces. The former root `Views/` fallback is discontinued.
- `Seeder` replaces the v1 Dumper API and commands. Migration rollback and explicit reversible Seeder rollback are separate operations.
- Packages and Kits have explicit lifecycle and activation rules. Existing v1 Package layout and automatic loading need review before migration.
- Copy `.example.env` to `.env`, generate a private `APP_KEY`, and review configuration and CLI command changes before serving or migrating an application.

See [Installation](Docs/V2.x/Installation.md), [Upgrade from v1.x](Docs/V2.x/UpgradeFromV1.md), and [Feature status](Docs/V2.x/FeatureStatus.md) for exact supported contracts and qualification boundaries.
