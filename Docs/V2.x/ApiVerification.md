# API contract verification

The [Application Contract](ApplicationContract.md) describes selected public API operations. Verification compares that SqueHub-native declaration with route metadata and, for cases an application explicitly registers, with real HTTP responses. OpenAPI is an export target; the verifier does not parse generated OpenAPI back into framework state.

The same native contract also feeds [client SDK generation](SdkGeneration.md). A fresh generated client proves source and contract agreement; it does not prove the server returns that shape. Keep explicit verification cases for meaningful runtime branches.

Verification has two distinct results. **Declaration checks** inspect route and contract alignment without invoking handlers. **Executable cases** send a constructed Request through the existing Kernel, Router, middleware, controller, ExceptionHandler, and Response path, then compare the response to its operation's declared status, media type, headers, and JSON schema. An operation with no case is declared and statically checked; its runtime behavior has not been verified.

## Declare an executable case

Place explicit registrations in `Project/Api/Verification.php` for CLI verification. The file is optional and is loaded only by `contract:verify` when executable cases are allowed. It is not scanned or run during ordinary web requests, application boot, `contract:export`, Doctor, or static verification.

```php
<?php

use App\Plugins\Contract;

Contract::verify('users.show.success')
    ->operation('users.show')
    ->route(['id' => 1])
    ->expectStatus(200);

Contract::verify('users.show.missing')
    ->operation('users.show')
    ->route(['id' => 999999])
    ->expectStatus(404);
```

Each case name must be stable and unique. `operation()` names a public contracted operation; `route()` supplies its path parameters. Cases may supply query values, headers, cookies, a JSON request body, and a selected expected status with `query()`, `headers()`, `cookies()`, `json()`, and `expectStatus()`. The case supplies **input and branch selection**; the operation contract supplies the response schema. Do not duplicate the same schema in a test case.

An operation must declare each status a case expects. A runtime status not declared by the operation fails verification unless the operation declares an applicable `default` response. JSON responses are parsed strictly. Invalid JSON, valid JSON with the wrong shape, an absent required field, an extra field forbidden by `additionalProperties: false`, a wrong content type, and a missing declared response header produce distinct findings where applicable. Legal media-type parameters such as `charset=UTF-8` do not change the base media type.

The verifier conservatively gates `POST`, `PUT`, `PATCH`, and `DELETE` cases as mutations, even when the case has no marker. An HTTP method alone cannot prove a handler safe: a `GET`, `HEAD`, or `OPTIONS` handler might also change state. Explicitly add `mutation()` to any such case that can change application state or reach an external side effect. Fixture setup and cleanup can be attached to an individual case; cleanup runs after success or failure. Use disposable SQLite data and array/fake services deliberately. Never point a verification fixture at an ordinary application database, live Mail transport, Queue worker, OAuth provider, or external Webhook endpoint.

## Run the gate

```bash
php squehub contract:verify
php squehub contract:verify --static
php squehub contract:verify --format=json
php squehub contract:verify --operation=users.show
php squehub contract:verify --strict
php squehub contract:verify --mutations
```

The default in development or testing checks declarations and runs registered `GET`, `HEAD`, and `OPTIONS` cases that are not marked as mutations. `--mutations` deliberately includes cases using `POST`, `PUT`, `PATCH`, or `DELETE` and cases explicitly marked with `mutation()`. `--static` checks declarations without loading the case file or executing handlers. Production and other unrecognized/non-development environments run static checks only; they do not load case registrations. `--mutations` is refused there. The command has no production override for executable cases.

`--operation=<id>` narrows verification to one declared operation. `--strict` fails on warnings and uncovered public operations. The optional `contract.verification.require_case_per_operation` and `contract.verification.fail_on_warning` settings in `Config/Contract.php` enable those policies without the CLI switch. Static verification intentionally does not load `Project/Api/Verification.php`, so a strict static run can report operations as uncovered even if that file defines cases for an executable run. Default policy still reports coverage gaps without making every small application provide a case for every route.

Text output is concise for local use. `--format=json` writes only the versioned report to stdout, suitable for CI and shell redirection. Diagnostic or command errors use stderr. Exit status is `0` only when the report passes; a finding classified as an error or a command failure returns nonzero. A generic CI step can therefore use:

```bash
php squehub contract:verify --format=json > api-verification.json
```

Review where the artifact is stored before sharing it. Reports omit request and response bodies, Authorization values, cookies, session IDs, tokens, and private configuration. They record stable finding codes, severity, operation and case names, and safe structural paths such as `$.data.id`. A schema mismatch describes an expected and actual **type**, not the offending value.

## What the report means

The JSON artifact has its own format version, separate from `squehub_contract`:

```json
{
  "squehub_verification": "1",
  "passed": true,
  "summary": {},
  "findings": []
}
```

The summary distinguishes public operations, operations with cases, operations without cases, cases executed, and outcomes. Coverage counts say what was exercised; they are not a claim that all inputs or application states were tested. Findings use stable codes such as `status_undeclared`, `content_type_mismatch`, `response_schema_mismatch`, and `header_missing`. Identical fixtures and application state produce deterministically ordered machine reports; random request IDs and wall-clock duration are omitted.

## API platform coverage

Use real Kernel cases for [API Resources](ApiResources.md), pagination, managed [API errors](ApiResponses.md), Validation 422 responses, [PAT](ApiTokens.md) and Session guards, Authorization, [API versions](ApiVersioning.md), [CORS](Cors.md), and Rate Limiting. A missing or invalid PAT should exercise the real guard and `WWW-Authenticate` behavior; a validation case should exercise Request validation rather than constructing sample error JSON. Declaration checks can compare known token middleware and abilities with the contract; arbitrary custom middleware cannot be proven equivalent by inspecting its class name.

Phase 12E [OIDC](OAuth.md) and Phase 12F [Webhooks](Webhooks.md) retain their deterministic fake-provider, signature, replay, and loopback regression tests as part of the wider API gate. The contract verifier does not sign Webhooks, perform a live provider login, fetch remote discovery data, or send to external peers. An outgoing Webhook contract is a declaration of the event envelope; a receiver is an ordinary contracted route if deliberately included.

SqueHub validates declared JSON response schemas with the Opis JSON Schema 2.6 validator and Draft 2020-12 semantics. Validation covers the Phase 12G `ContractSchema` vocabulary, including named and recursive local component references, object properties and `additionalProperties`, arrays, enum, combinators, nullability, and declared constraints. External schema resolution is disabled; verification does not fetch remote `$ref` URLs. A closed object schema can detect a response field that the declaration forbids. It cannot discover that an application mistakenly declared a sensitive field public. A passing case proves that **the exercised response in this environment** matched the declared contract. It does not prove all input combinations, database drivers, providers, deployments, or absence of every security defect.

See [Application contracts](ApplicationContract.md), [CLI](Cli.md), [API development](ApiDevelopment.md), [v2 status](Status.md).
