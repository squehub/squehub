# Application contracts and OpenAPI export

An application contract is an explicit description of the public HTTP behavior an application chooses to document. SqueHub records that description in its own model and can export it as OpenAPI 3.2.1 JSON. OpenAPI is an output format; routes, Resources, validation, authentication, and Webhooks continue to run through their existing subsystems. Export does not call controllers or prove that their runtime output matches a declaration. The separate [API verification gate](ApiVerification.md) checks selected declarations and deliberately registered HTTP cases against actual behavior.

Contract declarations are opt-in. Merely registering a route does not publish it in a contract. This keeps browser, setup, debug, health, OAuth callback, and private infrastructure paths out of generated API descriptions unless an application deliberately declares them. The same rule applies to Webhooks: only explicitly declared outgoing event contracts appear in OpenAPI's top-level `webhooks`; incoming webhook receiver routes are ordinary `paths` when explicitly contracted.

## Describe a route

Application route files can import the two contract symbols from `App\Plugins`. `ContractSchema` is the JSON Schema declaration type; the existing `App\Plugins\Schema` remains the **database** schema API.

```php
use App\Plugins\{Contract, ContractSchema, Route};
use Project\Controllers\UserApiController;

Contract::info('Example API', '2.0.0', 'Public user endpoints');
Contract::tag('Users', 'Public user operations');
Contract::schema('User', ContractSchema::object([
    'id' => ContractSchema::integer(),
    'name' => ContractSchema::string(),
])->required(['id', 'name']));

Route::path('/api/users/{id}')
    ->get([UserApiController::class, 'show'])
    ->named('users.show')
    ->contract(Contract::operation()
        ->summary('Show a user')
        ->tags('Users')
        ->path('id', ContractSchema::integer())
        ->response(200, ContractSchema::ref('User'), 'The requested user')
        ->error(404));
```

The attached operation is a description, not another route or controller. The existing Router supplies the method, path, name, and declared API version; the operation supplies business meaning. Every `{placeholder}` needs a matching required `path()` declaration. Duplicate operation IDs, response statuses, parameter identities, or exported path/method combinations fail generation. A public operation needs either a named route or an explicit ID passed to `Contract::operation('users.show')`; generation never invents an ID from registration order. A route with multiple methods receives a stable method suffix for each exported operation. A route may have an internal contract that is excluded from public export using `->internal()`.

Phase 15B routes with trailing optional path segments, a `host()` condition, or a `fallback()` handler still work at runtime, but the current native contract, OpenAPI bridge, verifier, and generated SDK cannot represent those distinctions faithfully. Attaching an `OperationContract` to one of these routes is rejected at registration, including an `->internal()` operation. Keep public contracted operations on explicit required paths without per-route host conditions or fallbacks; document other runtime behavior separately until the contract format gains an accurate representation. A `where()` constraint on a required path parameter changes route eligibility, but it does not infer or replace that parameter's explicit schema declaration. Export never derives public server URLs from incoming Host or forwarded-host input.

`APP_BASE_PATH` is deployment context, not a change to a contracted operation. An application route `/api/users` stays `/api/users` in the native and OpenAPI operation paths whether deployed at `/` or publicly at `/app/api/users`. Static export and verification do not inspect an incoming request or bake a development mount into those paths. When publishing a deployment-specific contract, an application may deliberately declare a reviewed HTTPS server URL containing its public mount, such as `Contract::server('https://example.com/app')`; this is explicit metadata, not an automatically inferred Host or mount. Generated clients still accept their own runtime base URL. See [mounted requests](Http.md#url-base-path-and-mounted-requests) and [SDK generation](SdkGeneration.md).

`->tags('Users')` attaches a tag name to an operation. `Contract::tag('Users', 'Public user operations')` is optional when a tag needs a public description. The SqueHub artifact keeps named tag descriptions separately from each operation's tag list; OpenAPI receives matching top-level tag descriptions. Duplicate tag-description registrations fail rather than changing meaning with registration order.

### JSON request and response

```php
$userInput = ContractSchema::object([
    'name' => ContractSchema::string()->minLength(1)->maxLength(100),
    'email' => ContractSchema::string()->format('email'),
])->required(['name', 'email']);

Route::path('/api/users')
    ->post([UserApiController::class, 'store'])
    ->named('users.store')
    ->contract(Contract::operation()
        ->summary('Create a user')
        ->body($userInput)
        ->response(201, ContractSchema::ref('User'), 'Created user')
        ->error(422));
```

`body()` defaults to required `application/json`. `response()` accepts an HTTP status, optional schema, description, content type, and declared header schemas. `query()`, `header()`, and `cookie()` describe non-path parameters; none are inferred from controller source. HTTP header parameter names are case-insensitive, so duplicate spellings with different casing are rejected. Only documented operations appear in the public export. The `error(422)` helper reuses SqueHub's actual API error envelope and validation details; add it only when the operation can return that error. Runtime `Request::validate()` continues to perform validation independently. Validation rules are not automatically translated into contract schemas; add explicit [verification cases](ApiVerification.md) for behavior that should be checked.

### Resource, pagination, and authentication

An `ApiResource` can optionally declare its public shape, without changing its existing `toArray()` or response API:

```php
use App\Plugins\{ApiResource, ContractSchema};

final class UserResource extends ApiResource
{
    public static function contractSchema(): ?ContractSchema
    {
        return ContractSchema::object([
            'id' => ContractSchema::integer(),
            'name' => ContractSchema::string(),
        ])->required(['id', 'name']);
    }

    public function toArray(): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
        ];
    }
}
```

Register `Contract::resource('User', UserResource::class)` in an application bootstrap/provider, then refer to `ContractSchema::ref('User')` from route operations. A Resource with no `contractSchema()` keeps working at runtime; it needs an explicit schema declaration before contract export can describe its fields. The compiler does not construct a fake Model or run `toArray()` to guess them.

```php
Route::path('/api/users')
    ->get([UserApiController::class, 'index'])
    ->named('users.index')
    ->contract(Contract::operation()
        ->query('page', ContractSchema::integer()->minimum(1))
        ->paginatedResponse(200, ContractSchema::ref('User'))
        ->pat(['users.read'])
        ->error(401)
        ->error(403));
```

The paginated response uses ResourceCollection's real `data` and `meta` envelope, including `page`, `per_page`, `total`, `pages`, `from`, `to`, `has_next`, and `has_previous`. `pat()` describes a SqueHub personal access token and optional logical token abilities; it does **not** attach authentication middleware. Attach `RequireToken` and, where applicable, `RequireTokenAbility` through the ordinary route `->through()` pipeline. `session()` describes a separate session-authenticated operation. `authorizationAbilities([...])` can document logical application abilities, but cannot grant them. OIDC login configuration is not automatically an API security scheme.

### Outgoing Webhook event

```php
Contract::webhook('order.paid', ContractSchema::object([
    'order_id' => ContractSchema::string(),
])->required(['order_id']), 'Sent after payment is recorded');
```

This declares the **data** schema for a Phase 12F SqueHub-profile outgoing event. OpenAPI export wraps it in the existing event envelope and places it under top-level `webhooks`, with the actual SqueHub signature headers. It does not send an event or configure an endpoint. An incoming handler such as `Route::path('/hooks/billing')->post(...)` remains an ordinary route under `paths` when contracted. A declaration does not change CSRF exclusions, HMAC verification, Queue behavior, retries, or receipt claims.

## Export from the CLI

```bash
php squehub contract:export --format=openapi > openapi.json
php squehub contract:export --format=squehub > squehub-contract.json
php squehub contract:export --format=openapi --api-version=v1 --pretty
```

`--format=openapi` is the default. The supported formats are `openapi` and `squehub`. `--api-version` filters explicitly versioned operations using SqueHub's route version metadata; `--pretty` adds indentation. The command writes the JSON artifact alone to standard output, so shell redirection works. Errors go to standard error with a nonzero exit code. The command deliberately has no `--output` option; choose and review the redirection destination yourself, especially if a contract contains business descriptions or examples.

Export loads application and enabled Package route declarations through the same route loader as `route:list`. It does not handle a web request, query a database, send a webhook, discover an OIDC provider, issue a token, or start a Queue worker. Avoid side effects in route declaration files so all read-only CLI inspection remains safe. No `/openapi.json`, `/swagger`, or public documentation route is installed automatically.

## What the contract can describe

Operations can declare path, query, header, and cookie parameters; request bodies and content types; status-specific responses and headers; tags, summaries, descriptions, examples, and deprecation; and external security requirements. SqueHub can reuse named schemas, API Resource schemas, the existing API error envelope, and Page metadata. Route paths, methods, names, and API version metadata come from the real Router, while business request and response shapes require explicit declarations. SqueHub does not parse controller PHP or guess response fields from a Model.

The SqueHub schema vocabulary uses JSON Schema Draft 2020-12 meaning. An optional property may be absent; a nullable property may be present with `null`. Those are different contracts. A nullable string is represented by a type union such as `{"type":["string","null"]}`, not OpenAPI 3.0's `nullable: true`. Named schemas may reference one another, including deliberate recursive references. Unresolved references, duplicate names, and contradictory declarations fail export rather than silently producing misleading documentation.

Useful `ContractSchema` declarations include `string()`, `integer()`, `number()`, `boolean()`, `null()`, `object([...])`, `array($items)`, `enum([...])`, `oneOf([...])`, `anyOf([...])`, `allOf([...])`, and `ref('Name')`. Schema values are immutable: methods such as `required([...])`, `nullable()`, `description(...)`, `format(...)`, `minimum(...)`, `minLength(...)`, `examples([...])`, `readOnly()`, and `writeOnly()` return new declarations. Attach `required([...])` to an object only for properties that must be present. A property omitted from `required` is optional regardless of whether its own schema accepts `null`.

The normalized SqueHub artifact has this top-level shape:

```json
{
  "squehub_contract": "1",
  "application": {},
  "tags": {},
  "schemas": {},
  "operations": [],
  "webhooks": []
}
```

`application` contains public title, version, optional description, and explicit safe server URLs. `Contract::info($title, $version, $description)` supplies metadata; `Contract::server($url)` can add a reviewed server URL. The configured fallback comes from `Config/Contract.php`. SqueHub does not derive public servers from an incoming `Host` or forwarded header. `tags` contains optional named descriptions; `operations` carry the Router's method/path/name/version plus declared request, response, and security meaning. Named schemas appear once in `schemas` and remain `$ref` values inside operations, including recursive structures. No controller name, middleware class, `.env` value, or runtime secret is part of this public artifact.

The SqueHub JSON artifact uses an independent `squehub_contract` format version of `"1"`. OpenAPI export declares `openapi: 3.2.1` and `jsonSchemaDialect: https://spec.openapis.org/oas/3.2/dialect/2026-02-26`, with JSON Schema 2020-12 semantics. Both [API verification](ApiVerification.md) and [client SDK generation](SdkGeneration.md) consume the native contract; neither parses the OpenAPI output back into framework state.

The optional local [Agent/MCP context](AgentAndAI.md) exposes a bounded read-only projection of operation flags from routes already registered in memory or an existing validated route-cache artifact. On a fresh uncached Application it can report a partial result rather than loading route-source PHP. If the `read_routes` capability is denied, the Application Contract resource reports `state: denied` with no route-derived operations, even when the wider contract summary is allowed. It does not run controllers, infer schemas from source, publish the complete OpenAPI artifact, or authorize a caller. Keep contract descriptions and examples free of secrets before allowing an AI host to read them.

Specifically, `squehub://application/contract` returns `framework_version`, `api_version_strategy`, bounded `operations` (`methods`, `path`, `route_name`, `api_version`), route count/truncation signals, frontend adapter/development labels, and `deployment.state: not_probed`. It never includes declared request/response schemas, example bodies, security schemes, or Webhook definitions from `contract:export`. The route count describes the bounded inspected route set, so check `routes_truncated` before treating it as a total. See [MCP tools and resources](AgentMcpTools.md) for response states and [CLI](Cli.md) for the complete export command.

Phase 25 Agent/Contract projection and denial behavior passed Windows and user-run native Linux qualification, including an official MCP SDK client STDIO test on Linux. This is the bounded local projection, not an authorization or full Contract export; see [Phase 25 results](ReleaseReadiness.md#phase-25-agent-and-ai-integration--complete).

## Security and privacy boundaries

The contract describes client-facing requirements, not implementation middleware classes. A SqueHub personal access token is HTTP Bearer authentication, **not** an OAuth access token. Token ability names can be documented as SqueHub metadata without mislabeling them as OAuth scopes. Session-authenticated operations have their own representation. Configured OIDC login providers do not automatically become API Bearer schemes. A contract cannot enforce authorization on the server; existing Auth and Authorization still do that work.

Do not put real Authorization values, cookies, PATs, OAuth client secrets, webhook signing keys, database passwords, or application keys in descriptions, examples, server URLs, or vendor extensions. Generated artifacts may disclose route and data shapes even when they contain no credentials; review access and publication deliberately. Contract generation is not a substitute for runtime validation, CSRF, CORS, rate limiting, or Webhook signature checks.

## Determinism and limits

SqueHub sorts exported declarations into stable output, so equivalent application registrations produce byte-stable JSON. That makes reviewed diffs and CI checks practical. OpenAPI generation performs SqueHub declaration and structural checks, but does not certify every rule of the full OpenAPI specification or JSON Schema language. Runtime response verification and client generation are separate explicit steps; breaking-change gates remain future work. There is no required Node process, Redis service, or database connection for export or SDK generation.

See [API verification](ApiVerification.md), [Routing](Routing.md), [API resources](ApiResources.md), [API responses](ApiResponses.md), [API versioning](ApiVersioning.md), [API tokens](ApiTokens.md), [Webhooks](Webhooks.md), and the [feature status](FeatureStatus.md) for the behavior each contract describes.
