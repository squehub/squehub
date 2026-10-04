# Contract-driven client generation

SqueHub generates standalone TypeScript, JavaScript, and PHP API clients from the **explicit SqueHub Application Contract**. Generation does not inspect controllers, infer Model fields, execute routes, or parse an OpenAPI export back into framework state. Declare an operation and its request, response, security, and version meaning first; see [Application contracts](ApplicationContract.md). Use the separate [verification gate](ApiVerification.md) to exercise selected declarations against real HTTP behavior.

The clients are source code for a consuming application. They do not require the SqueHub PHP framework at runtime. Generated code uses the public HTTP contract; the server still owns validation, authentication, authorization, CSRF, CORS, and actual route behavior.

## Generate and check

Run from the SqueHub application root:

```bash
php squehub sdk:generate --language=typescript --output=Generated/TypeScript
php squehub sdk:generate --language=javascript --output=Generated/JavaScript
php squehub sdk:generate --language=php --output=Generated/Php
```

`--language` accepts `typescript`, `javascript`, or `php`. `--output` is a relative directory inside the current application, outside framework and application source. Neither Node nor a TypeScript compiler is needed to **generate** any language. The consuming TypeScript project needs its usual compiler; JavaScript uses native ESM and a Fetch-compatible runtime; the PHP client targets PHP 8.2 with cURL by default and can take a custom transport.

When more than one API version is declared, select one explicitly:

```bash
php squehub sdk:generate --language=typescript --output=Generated/ApiV2 --api-version=v2
```

The option selects operations with matching route API-version metadata. URI versions remain in their declared paths. For a header-versioned route, the generated call sends its declared `X-API-Version` value. The generator does not invent a separate version negotiation scheme. An empty public contract, ambiguous mixed versions, invalid symbol collision, or an unsupported HTTP/schema serialization fails with a nonzero exit instead of a misleading partial client.

Check a generated tree in CI without changing any file:

```bash
php squehub sdk:generate --language=typescript --output=Generated/TypeScript --check
```

Exit status 0 means every generated file and the manifest matches the current contract; a missing or stale tree exits nonzero. This is a source freshness check. It does not replace `contract:verify`, API integration tests, a consumer language compiler, or release qualification.

## Naming and generated files

Every public contracted operation needs a stable operation ID, such as `users.show`; the ID maps to `client.users.show(...)` in TypeScript/JavaScript and `$client->usersShow(...)` in PHP. The manifest records the exact mapping. Invalid or colliding names fail generation. Declare names deliberately because changing an operation ID changes the generated source API.

TypeScript output contains `client.ts`, `types.ts`, `errors.ts`, `runtime.ts`, and `index.ts`. JavaScript produces corresponding `.js` files with JSDoc plus `package.json` marking ESM. PHP output contains a small standalone package with `composer.json`, `autoload.php`, `src/Client.php`, transport and exception classes, and generated Model types where the schema permits them. All source files carry a generated-file marker.

Named schemas become generated types. The generator preserves references, including recursive references, and distinguishes an optional property from a required nullable property. Request-facing types omit `readOnly` fields; response-facing types omit `writeOnly` fields. Enums are represented without changing string values into integers or vice versa. A JSON Schema composition that cannot be represented faithfully fails or uses a documented broad representation; the generator does not claim to enforce the whole JSON Schema language in a client.

Only explicitly contracted request parameters and JSON bodies appear in generated calls. Path, query, and header arguments are separate. Query parameters are presently scalar because the native contract does not define a list/repeated query serialization style; cookie parameters and non-JSON bodies are not generated. A path value is encoded as one segment and cannot contain a slash or dot segment. Optional arguments may be omitted; explicit `null` is accepted only where both the schema and HTTP representation define it.

### TypeScript

```ts
import { SqueHubClient, SqueHubApiError } from './Generated/TypeScript/index';

const api = new SqueHubClient({
    baseUrl: 'https://api.example.com',
    token: () => currentPersonalAccessToken,
});

try {
    const user = await api.users.show({ path: { id: 7 } });
    console.log(user);
} catch (error) {
    if (error instanceof SqueHubApiError) {
        console.error(error.status, error.code, error.requestId);
    }
}
```

`users.show` exists only if `users.show` is a contracted operation. TypeScript exports per-operation argument and response types, named schema types, and a reusable `SqueHubPage<T>` when pagination appears in the contract. A JSON operation may accept `{ path, query, headers, body }` according to its declaration. The client's `fetch` option can inject a compatible Fetch implementation; the generated runtime has no Axios, React, Vue, Vite, or Node HTTP dependency.

### JavaScript

```js
import { SqueHubClient } from './Generated/JavaScript/index.js';

const api = new SqueHubClient({
    baseUrl: 'https://api.example.com',
    token: () => currentPersonalAccessToken,
});

const user = await api.users.show({ path: { id: 7 } });
```

JavaScript uses the same runtime call structure as TypeScript and ships JSDoc for editor assistance. Use a modern ESM environment with `fetch`, or supply a Fetch-compatible implementation through the client options.

### PHP

```php
require __DIR__ . '/Generated/Php/autoload.php';

$client = new \SqueHubGenerated\Client(
    baseUrl: 'https://api.example.com',
    tokenProvider: static fn (): string => $currentPersonalAccessToken,
);

$user = $client->usersShow(['path' => ['id' => 7]]);
```

The PHP client uses the same generated operation mapping with a lower-camel method for each operation. It has no dependency on the SqueHub application container. Its default transport uses cURL; `Transport` can be implemented for testing or another HTTP stack. Runtime token providers are called when a request needs them, so rotation does not require regenerating source.

## Responses, pagination, and errors

Generated calls select a declared `2xx` response by the **actual** status. A response with JSON decodes and returns the declared data type; a `204` or other declared bodyless success returns the language's empty result without JSON parsing. Multiple success statuses remain distinct in the generated types where the language permits it. Undeclared status, invalid JSON, or wrong media type is a protocol failure. PHP additionally checks the shape needed to hydrate generated DTOs and Page values. TypeScript and JavaScript supply types and JSDoc, but do not run a full JSON Schema validator on successful bodies; use [API verification](ApiVerification.md) and consumer tests to catch server response drift.

For a resource Page, the generated page shape follows SqueHub's existing envelope: `data` and `meta`, with `meta.page`, `per_page`, `total`, `pages`, `from`, `to`, `has_next`, and `has_previous`. This is offset pagination. Cursor pagination is not implemented.

An HTTP error that follows the [SqueHub API error envelope](ApiResponses.md) becomes `SqueHubApiError` in TypeScript/JavaScript or `ApiException` in PHP. The safe fields include status, `error.code`, `error.message`, optional `error.details`, `request_id`, and selected response headers. Invalid JSON, HTML proxy errors, unknown media types, and transport failures have distinct protocol/transport exceptions. Exceptions do not include the request body, Authorization value, session cookie, or raw response body. A generated client cannot guarantee every server response conforms; keep server-side verification and consumer tests.

## Security at runtime

Supply `baseUrl` and credentials **when constructing the client**, not through generator options or committed generated source. For a declared SqueHub personal access token operation, the runtime adds `Authorization: Bearer <token>` using its token provider. SqueHub PATs are not OAuth access tokens. The generated client does not issue, store, refresh, or revoke them; see [API tokens](ApiTokens.md).

For browser Session operations, configure Fetch credentials and a CSRF token provider:

```ts
const browserApi = new SqueHubClient({
    baseUrl: 'https://app.example.com',
    credentials: 'include',
    csrfToken: () => currentCsrfToken,
    csrfHeader: 'X-CSRF-Token',
});
```

Unsafe Session requests use the supplied CSRF header. The client neither scrapes pages for a token nor disables SqueHub's CSRF middleware. The PHP client supports explicit cookie/CSRF providers when needed; its primary server-to-server path is a PAT. OIDC browser login remains the application's separate [OIDC client flow](OAuth.md). Generated clients do not perform OIDC discovery, PKCE, or login. Browser CORS restrictions still apply; configure allowed origins and headers on the server via [CORS](Cors.md).

The native Application Contract may also declare outgoing [SqueHub Webhook](Webhooks.md) payloads. Generated types can describe those data shapes, but an API client does not become a webhook receiver, verify signatures, create subscriptions, or send webhook events.

## Determinism and safe regeneration

`squehub-sdk.json` records the generator format, language, API version, operation/schema symbols, owned files, and a SHA-256 fingerprint of the normalized contract relevant to the SDK. Equivalent declarations produce byte-stable output; timestamps, local base URLs, and credentials are not inserted. The fingerprint detects contract drift, not server behavior drift.

The generated client receives its public base URL at runtime. For a subdirectory deployment, supply the mount there, for example `baseUrl: 'https://example.com/app'` in JavaScript/TypeScript or the same URL as the PHP client's constructor argument; declared operation paths stay `/api/users`. The runtime joins those parts as `https://example.com/app/api/users`. `APP_BASE_PATH` does not make generated source or its manifest environment-specific. Keep a canonical HTTPS public origin and the mounted path in your deployment/client configuration; do not derive a security-sensitive base URL from an incoming Host header. See [Application Contracts](ApplicationContract.md) and [Deployment](Deployment.md#subdirectory-mounts-and-shared-hosting).

Regeneration reads the prior manifest and rewrites or removes only files it owns. Unknown user files in the output directory remain. A source file without the generated marker is not silently overwritten, even if a manifest names it. A manifest for another language is rejected; create a separate output directory per language. Generation refuses unsafe paths, linked directories, and protected framework/application roots. There is no recursive delete of the output directory. `--check` performs the same comparison in memory without creating or modifying output files.

Treat generated output as source code: review diffs, keep runtime credentials outside it, and run your consumer's compiler/tests. Contracts and generated types can reveal API structure, so publish them intentionally. The Application Contract's descriptions and examples are not necessary for the SDK's executable shape and are not copied into its manifest or source. Generated SDKs are not a replacement for production authorization or a promise of exactly matched server behavior.

## Verification status

Generated PHP and JavaScript clients were exercised over local HTTP/Kernel and Node/Fetch on Windows. The user also reported Linux/WSL execution and TypeScript compilation; exact later totals and tool versions were not supplied. See [current status](Status.md) for release verification limits.

## Current limits

The generator produces clients for the declared JSON HTTP subset. It does not generate multipart upload, streaming response, SSE, WebSocket, cookie parameter serialization, query arrays with unspecified wire style, arbitrary OAuth/OIDC clients, webhook receivers, or Dart. PHP client cURL execution requires the cURL extension unless a custom transport is supplied. Browser clients depend on a Fetch-compatible runtime. The generator does not install dependencies, publish a package, modify server CORS, or add a public OpenAPI route. The broader v2.0.0 release still has separate verification gates; see [v2 status](Status.md).
