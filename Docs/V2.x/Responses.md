# HTTP Responses

SqueHub sends ordinary text, JSON, redirects, Views, bytes, local files, and produced chunks through the existing HTTP `Response` boundary. A controller returns a response; middleware may add headers or cookies; the web entry point sends it. At the Phase 15C checkpoint, Windows qualification passed 2,332 tests and 16,209 assertions with 62 skips; user-run Linux/WSL qualification passed 2,332 tests and 16,413 assertions with 17 skips. Those results apply to that source revision and its tested environments.

Application code can use the `App\Plugins` gateway and the global `response()` helper:

```php
use App\Plugins\Response;

// Choose the response that fits the route:
return new Response('Ready', 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
```

```php
return response()->json(['ready' => true]);
```

```php
return response()->redirect('/dashboard');
```

```php
return redirect('/dashboard'); // Strict application-root path; returnable RedirectResponse.
```

```php
use App\Plugins\View;

return View::response('Home.Welcome', ['name' => 'Ada']);
```

`Response::withHeader()`, `withoutHeader()`, and `withContent()` return modified copies. Header names, values, and status codes are validated. Single-value header names are replaced case-insensitively. A returned response remains available to the normal route middleware, API/CORS handling, diagnostics, and exception boundary. `JsonResponse` retains strict JSON encoding; `RedirectResponse` retains its existing redirect behavior. See [HTTP](Http.md), [Views](Views.md), and [API development](ApiDevelopment.md).

With `APP_BASE_PATH=/app`, `redirect('/dashboard')` sets `/app/dashboard` from a strict application-root path and rejects external inputs. An input of `/app/foo` means the application's `/app/foo` route and becomes `/app/app/foo`. `response()->redirect('/dashboard')` also responds with `/app/dashboard` through the normal HTTP boundary; an already public `/app/dashboard` remains unchanged. This factory's raw-string rule is ambiguous when the **application** route is itself `/app/foo`: passing `/app/foo` directly is treated as already mounted, whereas a named route for that declaration generates `/app/app/foo`. Use `response()->redirect(route('app.foo'))` for that case. The factory can carry an application-chosen external destination unchanged; validate such a destination before redirecting. `redirectBack($request, '/fallback')` uses a prior safe browser path or same-origin Referer within the mount, otherwise the local fallback; it defaults to 303. The zero-argument `redirect()` remains the v1 immediate header/exit object and is not the returnable API. See [Global helpers](Helpers.md#responses-and-redirects). The mount does not alter an explicitly chosen Cookie Path.

| Factory call | Result | Body source |
| --- | --- | --- |
| `response($text)` | Ordinary `Response` | In-memory string |
| `response()->json($data)` | `JsonResponse` | Strictly encoded JSON |
| `response()->redirect($url)` | `RedirectResponse` | Existing redirect contract |
| `response()->binary($bytes, $contentType)` | Ordinary `Response` | Exact in-memory bytes |
| `response()->download($path, ...)` | Local-file response | Bounded chunks, attachment |
| `response()->file($path, ...)` | Local-file response | Bounded chunks, inline |
| `response()->stream($producer, ...)` | Stream response | Deferred iterable chunks |

`Response::content()` is meaningful for in-memory responses. Deferred file and producer responses expose no body bytes through `content()` before emission; `hasDeferredBody()` identifies that distinction. Use `withCookie()` and ordinary header methods on the returned response as needed. The first `send()` emits the response; a repeated `send()` on the same object does not emit it again.

## Cookies

A typed `Cookie` carries an opaque string value and validated attributes. SqueHub percent-encodes that value with `rawurlencode()` in the emitted cookie field; it does not serialize arrays or PHP objects. Attach cookies to a response with the same copy-returning style as headers:

```php
use App\Plugins\Cookie;

return response('Signed in')->withCookie(new Cookie(
    name: 'display_mode',
    value: 'compact',
    path: '/',
    secure: true,
    httpOnly: true,
    sameSite: 'Lax',
));
```

`Cookie` supports `expires: ?DateTimeImmutable`, `maxAge: ?int`, `path: string`, `domain: ?string`, `secure: bool`, `httpOnly: bool`, and `sameSite: string`. The defaults are `path: '/'`, no Domain/Expires/Max-Age, `secure: false`, `httpOnly: true`, and `sameSite: 'Lax'`. Use `secure: true` over HTTPS in production. An explicit Domain changes which hosts receive the cookie; keep it unset unless needed. SameSite affects cross-site browser sending and does not replace CSRF protection. `SameSite=None` requires Secure. Prefixes such as `__Secure-` and `__Host-` have additional browser constraints, which the typed cookie validates.

An HTTP mount such as `APP_BASE_PATH=/app` does not alter an explicit `Cookie` Path or the default `/`. Choose `path: '/app'` deliberately when an application cookie should stay inside that mount. A `__Host-` cookie is a special exception: browser rules require Path `/`, so do not set `/app` for that prefix. Native Session cookies instead use the independently configured `SESSION_PATH`; see [Sessions](Sessions.md).

`SameSite` accepts `Lax`, `Strict`, or `None` case-insensitively and emits the canonical spelling. `__Secure-` requires Secure; `__Host-` requires Secure, Path `/`, and no Domain. Expiry is emitted as a GMT HTTP date. `maxAge` is a non-negative integer; zero is appropriate for deletion. A name is limited to 256 bytes, a value to 4,096 bytes, Path to 1,024 bytes, and Domain to 253 bytes. These are framework bounds, not a promise that every browser accepts every value at those limits.

Name, value, Path, Domain, and attributes are validated before a response is emitted. Invalid controls, line breaks, and cookie syntax fail rather than becoming headers. Distinct cookies are emitted as separate `Set-Cookie` fields, never joined with commas. `withCookie()` works on ordinary, JSON, redirect, View, binary, file, and streaming responses. Use `Cookie::forget('display_mode', path: '/')` with the same name and path/domain scope to expire a cookie; it emits `Max-Age=0` and a past expiry through the same validated cookie path. If the original cookie uses `Secure` or `SameSite=None`, supply compatible flags when forgetting it. PHP's native session cookie remains managed by the configured Session driver.

Cookie values and attributes are HTTP metadata. Do not place passwords, API tokens, or private records directly in them. Use an application-owned protected session or a deliberate cryptographic scheme for sensitive state.

## Binary bytes

`binary()` stores bytes already in memory. PHP strings are byte sequences; SqueHub does not HTML-escape, JSON-encode, or transcode them:

```php
return response()->binary($pngBytes, 'image/png');
```

The default content type is `application/octet-stream`. Binary bytes are already in memory, and this method does not calculate or add `Content-Length`. Use this for bounded payloads. For a large local file, use `download()` or `file()` so construction does not read the whole file into memory.

## Local files and Content-Disposition

Use `download()` for an attachment and `file()` to request inline display:

```php
use App\Plugins\{Request, Response};

final class ReportController
{
    public function download(Request $request): Response
    {
        $path = $this->authorizedReportPath($request);

        return response()->download(
            $path,
            filename: 'report.pdf',
            contentType: 'application/pdf',
            request: $request,
        );
    }
}
```

`download(string $path, ?string $filename = null, ?string $contentType = null, ?Request $request = null)` and `file()` accept a local readable regular file selected by the application. The physical path is never the intended public filename. If no filename is provided, the safe basename is used. Supply a client filename and content type when the application knows them; otherwise the response uses `application/octet-stream`. `Content-Disposition` quotes an ASCII fallback safely and provides an encoded `filename*` for non-ASCII names. `download()` uses `attachment`; `file()` uses `inline`. The framework owns file `Content-Length`, `Content-Range`, `Content-Disposition`, and `Accept-Ranges` metadata; callers cannot replace those headers on a file response or swap in a string body.

**Authorization and path selection belong to the application.** Do not pass an unchecked path built from a URL segment or form field. Confirm that the current user may access the selected file, and contain untrusted path selections within the intended application directory. The response API neither fetches remote URLs nor grants access to files. Physical server paths and file contents should not appear in public errors. A missing or unreadable file found during response construction raises a safe file-response exception inside the normal Kernel path. A changed or unopenable file detected later during `send()` also raises a safe exception, but emission happens after the Kernel has returned, so the Kernel cannot normalize that late failure. File handles are closed after emission, including error paths.

The response reads local files in bounded chunks when sent. A zero-byte file is valid. It does not call `file_get_contents()` on an entire large file. This works with ordinary PHP and Apache/shared hosting; it does not require a worker, Redis, or an asynchronous server. A file may change between response construction and sending, so the framework detects a bounded failure rather than promising an immutable snapshot.

### Single byte ranges

To opt into a request's `Range` header, pass that `Request` explicitly to `download()` or `file()`. Without the Request argument, the response sends the whole file regardless of a client-supplied Range header. On a regular local file the supported forms are one range: `bytes=start-end`, `bytes=start-`, and `bytes=-suffixLength`. A satisfiable request returns `206 Partial Content` with the selected `Content-Range` and `Content-Length`; an invalid, repeated, or unsatisfiable range returns an empty `416 Range Not Satisfiable` response with `Content-Range: bytes */<full-size>` and `Content-Length: 0`. File responses advertise byte-range support with `Accept-Ranges: bytes`. A selected range is read in bounded chunks rather than allocated as one large string.

Multipart ranges, arbitrary producer streams, ETag negotiation, and a general HTTP caching engine are outside this contract. `If-Range` is not evaluated as a conditional range validator; when supplied, the file response sends the complete representation with status 200. Do not treat an arbitrary stream as seekable. Applications requiring conditional delivery can add an explicit higher-level policy later.

## Produced streams

`stream()` accepts a callable that returns an iterable of string chunks. The callable is invoked only when the response body is sent:

```php
return response()->stream(
    static function (): iterable {
        yield "first line\n";
        yield "second line\n";
    },
    headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
);
```

Its signature is `stream(callable $producer, int $status = 200, array $headers = [])`. The producer does not run during route registration, response construction, static contract export, or `route:list`. Every yielded chunk must be a string; invalid chunks cause a response error. `Content-Length` and `Transfer-Encoding` are not accepted on this response because SqueHub cannot derive a correct stream length or control the server's framing. The framework does not buffer all chunks; PHP and the web server own transfer framing.

The producer executes in `Response::send()` after the Kernel has returned. An exception from the producer is therefore outside the Kernel's normal `ExceptionHandler` normalization, even if it happens before the first chunk. Once body bytes have been emitted, the response is partial and cannot be replaced by a complete error response. Keep producers reliable and avoid disclosing secrets in their own output. Resource cleanup remains the producer's responsibility for handles it opens.

## HEAD and bodyless statuses

The existing sender suppresses body bytes for a reached HEAD response and for statuses `204`, `205`, and `304`. For a file response, headers may still describe the selected representation; HEAD does not read and transmit the file body. For a stream response, the deferred producer is not executed solely to produce a suppressed body. Body suppression is a sender concern; the Router separately falls back from HEAD to GET when no explicit HEAD route matches, as described in [Routing](Routing.md).

## API and testing boundaries

Binary, local-file, and stream responses are explicit HTTP responses. They are not wrapped in `ApiResource` JSON, and they still pass through route middleware and applicable CORS policy. Existing API error envelopes apply to failures handled before emission. JSON resource responses keep their established shape.

`Response::content()` reports the in-memory content of an ordinary or binary response. A file or producer stream should be tested through controlled body emission or a real HTTP request; inspecting its metadata must not execute the producer or read a large file. Verify status, `Content-Type`, `Content-Disposition`, `Content-Length`, cookie fields, and body bytes separately. A file download test should use a disposable local file; do not depend on a production path.

SqueHub's HTTP server emits through the existing `Response::send()` path. File and stream delivery do not require a second kernel or dedicated worker. The user-run Linux/WSL release-gate copy passed the Phase 15C suite on a case-sensitive filesystem. v2.0.0 publication and clean committed-checkout reproduction remain separate release gates.
