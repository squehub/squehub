# Request Cases: Phase 13G research decision

**Status: 13G — Research complete: deferred.** This is a decision record for the v2 development working tree. SqueHub does not provide a Request Case model, capture API, serializer, replay API, CLI command, or production endpoint. No request is recorded automatically.

The proposed feature would turn a deliberately sanitized request **shape** into a portable test artifact, then reconstruct that shape through the real Kernel in a disposable application. It would not preserve the user's original request. This research found a plausible narrow design, but the current code does not establish the privacy and execution guarantees needed to offer it as a feature.

## What was evaluated

`App\Http\Request` retains the raw URI, query, form data, cookies, uploads, headers, server values, and raw body because normal HTTP handling needs them. `Request::capture()` snapshots those inputs from PHP's request environment; it is not a safe Request Case recorder. Serializing a `Request`, its attributes, a debug snapshot, or an exception would bypass the proposed privacy boundary.

The Router exposes a matched `RouteDefinition` with a registered URI template, name, API version, and optional contract operation ID. `RouteDispatcher` attaches the definition and concrete parameters to the `Request` after a successful match. The contribution registry can identify a route's Package owner without putting request values in its ownership record. These are useful structural sources, but they are not automatically safe to publish: route registration permits literal path segments and route names that could themselves contain a token, personal identifier, or other sensitive text. A URL such as `/reset-password/super-secret-token` remains sensitive even if the whole string was registered as a route. Query **names** can also contain user data. Syntactic validation and bounded length do not establish that metadata is public.

The shared `SecretRedactor` masks configured credentials, selected security headers, and recognizable PATs in diagnostic text. It does not know every application secret or personal value embedded in a URL, custom header, route label, or Package metadata. Redaction can be a secondary check; omitting input before serialization must be the primary control. Existing [provenance guidance](Contributions.md#privacy-and-operational-limits) already tells developers to keep secrets out of structural identifiers.

## Threat and privacy boundary

| Source | Exposure if copied into a portable artifact | Required default for any future automatic capture |
| --- | --- | --- |
| Raw URI, concrete path, route parameters | Reset links, OAuth codes, email addresses, private record IDs, credentials, or filesystem paths | Omit concrete values. Use reviewed route metadata and required placeholders; fail closed when no safe match exists. |
| Query names and values | Personal data or credentials can appear in either | Omit both by default. A developer can supply synthetic query input in a test. |
| Headers | `Authorization`, `Proxy-Authorization`, API keys, CSRF tokens, Webhook signatures, cookies, tracing IDs, and arbitrary custom secrets | Omit all by default. Any future structural media-type option needs exact normalization and an allowlist. |
| Form, JSON, XML, raw body, and uploaded files | Passwords, personal data, token material, file bytes, original names, and temporary paths | Never automatically capture a body or upload. |
| Cookies, Session, and authentication state | Session IDs, remember-me values, flash data, original identity, and authorization context | Omit. Set up fresh test state and fixture identity deliberately. |
| Server, environment, and configuration | Host/IP details, absolute paths, `APP_KEY`, database and mail credentials, and other private settings | Omit. Never inherit runtime environment values into the artifact. |
| Route, contract, and Package labels | Developer-generated names or literal route segments may encode sensitive values; Package snapshots may be stale or conditional | Treat labels as reviewed declarations, not proof of safety or current activation. Omit source paths and unreviewed metadata. |
| Response and exception data | Response bodies, `Set-Cookie`, trace arguments, absolute paths, and secret-bearing exception text | Do not persist them as Request Case evidence. |

An explicitly invoked capture operation would still need to start from **nothing** and add only approved fields. Copying all fields and then filtering known sensitive names would leave unknown credentials and personal data exposed. An incomplete artifact with required placeholders is preferable to retaining original values. An unmatched request, a request stopped by global middleware, or metadata that cannot be classified safely must produce no automatic artifact.

There is also a freshness problem in a simple `fromRequest()` design. `RouteDispatcher` sets `route` and `route.params` on a successful match, while `Kernel::handle()` does not clear those attributes when a `Request` object is reused. A later rejected or unmatched handling attempt could expose the previous match through those attributes. A future capture service must obtain an application-bound, fresh canonical match or a verifiable per-attempt match marker; reading `Request::attribute('route')` alone is insufficient.

## Replay and reproducibility findings

The [testing environment](Testing.md) already supplies a temporary application root, SQLite in memory by default, and array Session, Cache, Storage, and Logging. `TestClient` sends constructed requests through the real Kernel. This is the right foundation for a future replay implementation, but its existence is not a proof that arbitrary application execution is isolated.

In particular, `TestApplication` does not register the outbound HTTP provider, but the container can auto-resolve unregistered concrete classes. An `HttpClient` created that way uses `CurlTransport` unless a fake is installed. Its explicit fake facility rejects unmatched requests, but that deny behavior is not enforced for a new `TestApplication`. A replay profile would have to block framework-managed outbound HTTP before any route, middleware, or controller runs. Mail, Notifications, and Queue work likewise need demonstrated non-delivery or an explicit failure path; synchronous Queue execution can run application job code. Package boot and route loading execute trusted Package PHP. A read-only artifact inspector must not bootstrap an application or Package to discover provenance.

**SqueHub cannot sandbox arbitrary trusted application PHP.** A controller, middleware, Package hook, or job can call PHP filesystem, process, or network functions directly outside framework services. A disposable framework application does not prevent those calls. GET, HEAD, and OPTIONS are conservative method choices, not proof of side-effect freedom; POST, PUT, PATCH, and DELETE would additionally need explicit mutation opt-in. No future documentation should call replay a security sandbox or promise that it cannot affect resources outside framework-managed test state.

A disposable research probe confirmed this boundary: a GET route in `TestApplication` used a direct PHP file write to create a harmless marker outside the temporary application root. The marker and test fixture were removed after the probe. This demonstrates that a framework-owned temporary root does not confine trusted application PHP, even for GET.

The only defensible reproducibility target is reconstructing the same **sanitized request shape** against a specified disposable fixture. Identical responses are not guaranteed: time, randomness, fixture data, external dependencies, environment, and application or Package code can change them. A fingerprint of normalized safe metadata would identify the artifact, not prove two application states equivalent.

## Prerequisites for reconsidering 13G

1. Define a small, versioned, strictly validated, size-bounded non-executable format. Reject unknown fields, raw body/cookie/credential fields, unsafe paths, and incomplete cases at replay time. Normalize and serialize equivalent cases identically across Windows and Linux.
2. Establish an explicit route-metadata trust policy. Capture only a fresh match from the correct Application; omit all original route and query values. Require developer-reviewed structural labels or decline capture when route literals, names, contract IDs, or provenance cannot be considered safe. Static inspection must parse the artifact without booting application or Package PHP.
3. Make the disposable replay profile fail closed for framework-managed outbound HTTP, Mail, Notifications, and Queue effects. Preserve temporary database, Storage, Cache, Session, and Package state; never reuse the developer's live connections or identity. Require explicit opt-in for mutation-capable methods.
4. Prove the boundaries with planted-secret tests across path, query, headers, body, uploads, cookies, Session, PAT, OAuth/OIDC, CSRF, Webhook, and configuration values. Test stale matches, unmatched routes, active and disabled Package routes, external service attempts, disposal, multi-Application isolation, deterministic serialization, and Windows/Linux portability. State the arbitrary PHP limitation in the API and documentation.

These are specific missing prerequisites, so the phase is **deferred** rather than presented as a partial implementation. A prototype that only serializes a route template would not meet the replay and privacy gates. No production traffic recorder, failure recorder, background surveillance, or general `request:case:run` command should be inferred from this decision.

## What to use now

Write [explicit integration tests](Testing.md) with synthetic route parameters, query values, bodies, and fixture identity in `App\Plugins\TestCase`. The tests run through the normal Kernel with developer-controlled inputs and cleanup. For declared APIs, use [contract verification cases](ApiVerification.md) and [Application Contract](ApplicationContract.md) metadata; their scope and mutation policy are documented separately. Manually authored sanitized fixtures can be kept with application tests after reviewing what they contain. These workflows do not require copying a real user's request, credential, Session, or response into source control.

If 13G is revisited, Package ownership can be represented as a reviewed owner type and name, and a public contract operation ID may be referenced without embedding its schema. Neither provenance nor a contract declaration validates arbitrary request values or authorizes replay. No Kit capability is part of this decision.
