# SqueHub v2 status

SqueHub v2.0.0 is an **unreleased development version**. These guides describe the current v2 source snapshot, not an installable stable Composer release. The published `composer create-project squehub/squehub` flow still targets the earlier generation. Start with [installation](Installation.md) only when you have a complete, authorized v2 source snapshot.

## What works in the development source

The current source includes the application container and routing, HTTP responses, Views and optional frontend profiles, database and ORM, authentication and authorization, APIs and contract tools, Queue and Scheduler, Packages and Kits, and deployment diagnostics. The guides describe each supported API and its limits. A feature being implemented does not mean it has been qualified for every PHP version, database, host, or provider.

| Build an application | Read these guides |
| --- | --- |
| First route, controller, View, and form | [First application](FirstApplication.md), [routing](Routing.md), [Views](Views.md), [validation](Validation.md) |
| Persistent data | [Database](Database.md), [migrations](Migrations.md), [models](Models.md) |
| Identity and secure endpoints | [Authentication](Authentication.md), [authorization](Authorization.md), [security](Security.md) |
| APIs and integrations | [API development](ApiDevelopment.md), [Application Contract](ApplicationContract.md), [API verification](ApiVerification.md), [client generation](SdkGeneration.md) |
| Background work and operations | [Queue](Queue.md), [Scheduler](Scheduler.md), [Health and Doctor](Health.md), [deployment](Deployment.md) |

## Verification and release boundary

As of **3 October 2026**, the versioned documentation portal has been verified locally on Windows/XAMPP for catalog links and documentation routes. Recheck the generated catalog after each content build. Those checks cover the local portal, not public `squehub.com` deployment or every example in every guide.

The Phase 26 framework qualification passed a Windows PHP 8.2.12/SQLite suite of **2,955 tests and 22,755 assertions**, and a user-run native Linux PHP 8.5.4/SQLite suite of **2,955 tests and 23,004 assertions** after backend cleanup. Guarded, disposable Linux runs also passed representative MySQL 8.4.11/InnoDB and Redis 8.0.5/PhpRedis checks. These are bounded results for their stated snapshots and environments. A reproducible clean source artifact, published v2 Composer installation, production documentation deployment, and final release approval remain open. See [testing](Testing.md) and [deployment](Deployment.md) for how to qualify your own application.

## Packages and Kits

The v2 [Package](Packages.md) and [Kit](Kits.md) **lifecycle APIs are implemented in the development source**. They let an application manage its own reviewed extensions and solution templates. The official SqueHub catalog currently lists **no released Packages or Kits**. The Media Package and Application Starter Kit are planned concepts; neither has an installable release or a supported installation command. A catalog concept is not evidence that its proposed API exists in core.

For an existing v1 application, use [Upgrade from v1.x](UpgradeFromV1.md). The [v1.x documentation archive](/docs/v1.x) continues to describe that published generation.
