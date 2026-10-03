# SqueHub v2 status

These guides describe the SqueHub v2.0.0 source and its supported APIs. For installation, select an exact v2 ref or Composer version and verify the resolved package before following the [installation guide](Installation.md). An unversioned `composer create-project squehub/squehub` command alone does not establish which generation was installed.

## What works in v2

The current source includes the application container and routing, HTTP responses, Views and optional frontend profiles, database and ORM, authentication and authorization, APIs and contract tools, Queue and Scheduler, Packages and Kits, and deployment diagnostics. The guides describe each supported API and its limits. A feature being implemented does not mean it has been qualified for every PHP version, database, host, or provider.

| Build an application | Read these guides |
| --- | --- |
| First route, controller, View, and form | [First application](FirstApplication.md), [routing](Routing.md), [Views](Views.md), [validation](Validation.md) |
| Persistent data | [Database](Database.md), [migrations](Migrations.md), [models](Models.md) |
| Identity and secure endpoints | [Authentication](Authentication.md), [authorization](Authorization.md), [security](Security.md) |
| APIs and integrations | [API development](ApiDevelopment.md), [Application Contract](ApplicationContract.md), [API verification](ApiVerification.md), [client generation](SdkGeneration.md) |
| Background work and operations | [Queue](Queue.md), [Scheduler](Scheduler.md), [Health and Doctor](Health.md), [deployment](Deployment.md) |

## Compatibility and verification

The versioned documentation portal has been checked locally on Windows/XAMPP for catalog links and documentation routes. Recheck the generated catalog after each content build, and verify public routes separately when deploying the site. A portal check does not execute every example in every guide.

Recorded qualification includes Windows PHP 8.2/SQLite, native case-sensitive Linux PHP 8.5/SQLite, and isolated MySQL and Redis checks. Those results apply to the source revisions and environments that were tested; an installation should be verified against its exact ref and selected services. See [testing](Testing.md) and [deployment](Deployment.md) for the application checks and their limits.

## Packages and Kits

The v2 [Package](Packages.md) and [Kit](Kits.md) **lifecycle APIs are implemented in the v2 source**. They use explicit sources, reviewed plans, installed-disabled state, and deliberate enable; a central installation registry is not required. A Package installer accepts a local directory or credential-free HTTPS Git checkout with an exact capitalized source basename; a Kit installer accepts a local directory. Neither consumes a release ZIP URL directly. A website catalog can help with discovery without changing the CLI source contract. The official SqueHub catalog currently lists **no released Packages or Kits**. The Media Package and Application Starter Kit are planned concepts; neither has an installable release or a supported installation command. A catalog concept is not evidence that its proposed API exists in core.

For an existing v1 application, use [Upgrade from v1.x](UpgradeFromV1.md). The [v1.x documentation archive](/docs/v1.x) continues to describe that published generation.
