# Contributing to SqueHub

Thank you for helping improve SqueHub. Please keep each proposed change focused, explain the behavior it changes, and include tests and documentation when the public contract changes. Read the [community conduct guide](CODE_OF_CONDUCT.md) before participating.

## Choose the right version

Check the branch and version you are working on before opening a pull request; v1.x and v2.x APIs and directory layouts differ. The [v1 to v2 guide](https://www.squehub.com/docs/v2.x/upgrade-from-v1) records the main migration boundaries.

## Prepare a v2 development checkout

Use a **complete v2 source snapshot**, then run:

```bash
composer install
composer validate --strict
composer test
```

For manual application testing, copy `.example.env` to a private `.env`, run `php squehub key:generate`, and put the printed value in `APP_KEY`. Then run `php squehub doctor` and `php squehub start`. The key generator prints a value but does not edit `.env`. Never commit `.env`, credentials, tokens, or runtime data.

Application examples belong under `Project/`; framework changes belong under `App/`, `Bootstrap/`, and the related canonical directories. Add or update focused tests under `Tests/`. Put v2 guidance under `Docs/V2.x/`, and prefer `App\Plugins` in developer-facing examples. Keep comments useful for contracts and security boundaries, and use the existing style and PHP 8.2-compatible syntax.

Before proposing a change, run the relevant focused tests and the full suite where practical. Also run:

```bash
composer validate --strict
composer dump-autoload -o
git diff --check
```

If your change needs MySQL, Redis, SMTP, or another external service, describe the environment and results accurately. Do not run guarded live-backend tests against an application database. Explain skipped qualification paths rather than presenting them as passed.

## Propose a change

Open an issue for a significant API change or to discuss an uncertain design. For a pull request, describe the reason, user-visible behavior, tests performed, documentation updates, and compatibility or deployment risks. Keep generated and runtime files out of the change. Link any related issue.

Report vulnerabilities through [SECURITY.md](SECURITY.md) instead of a public issue or pull request containing exploit details.
