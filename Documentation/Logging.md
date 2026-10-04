# SqueHub v2 logging foundation

`logger()` resolves the one Application-owned `App\Logging\Logger`. It works in web and CLI code after the Application boots; a session and database connection are not required. The logger implements the installed PSR-3 `LoggerInterface` while retaining SqueHub's small helper API.

```php
logger()->info('Profile updated', ['user_id' => $user->id]);
logger()->warning('Callback missing an expected reference');
logger()->error('Payment failed', ['exception' => $exception]);
```

The supported levels, from least to most severe, are `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, and `emergency`. `logger()->log($level, $message, $context)` accepts the same levels. An unknown level throws. `Config/Logging.php` sets the `LOG_LEVEL` threshold, defaulting to `info`; records below it are skipped.

The default `file` driver appends one JSON object per line to `Storage/Logs/squehub.log`. The directory is created on first write and is ignored by Git. `path` may be set to a host-native absolute path in configuration; it is never taken from a request. Drive-qualified and UNC paths are accepted on Windows, while POSIX hosts require a leading `/`. A Windows path is not treated as a usable Linux path. Each record contains a UTC ISO-8601 timestamp, uppercase level, message, and context. Appending uses a file lock. Deployments should provide appropriate file ownership and external log rotation; the first driver has no built-in retention. `driver => 'array'` selects the in-memory `ArrayLogger`, whose `records()` method supports tests.

## Configure and use the logger

`Config/Logging.php` selects the `file` or `array` driver and the minimum level. The shipped file channel is the initial backend; named channels, remote aggregators, and automatic rotation are not part of this implementation. A local application can set `LOG_LEVEL=debug` during diagnosis, then use `info` or a stricter threshold according to its production policy.

```php
use App\Plugins\Log;

Log::info('Report completed', ['report_id' => $reportId]);
Log::warning('Upstream response was incomplete');

// The helper addresses the same Application-owned logger.
logger()->error('Report delivery failed', ['exception' => $exception]);
```

Keep context small and structured. Use IDs only when they are acceptable to retain in logs; do not place passwords, private keys, reset tokens, Mail bodies, Queue payloads, or complete HTTP requests into messages or context. Configure storage permissions and retention outside the framework. Log records are operational data and must be protected like other application data.

Context is bounded to six nested levels, 100 entries per array, and 4,096 bytes per string. Sensitive keys are recursively replaced with `[REDACTED]`. Configured and environment secrets, and string values under sensitive context keys, are removed from messages and context strings where known. The shared redactor also masks strings shaped like SqueHub personal access tokens, including a newly issued token before it appears in an HTTP request. An arbitrary object is recorded as its class only. A `Throwable` records class, code, file, and line, without a raw message or argument-bearing trace. Do not deliberately place credentials in free-form messages: this redaction is a safeguard, and unknown secrets cannot be recognized automatically. See [API tokens](ApiTokens.md) for issuance and storage rules.

During an HTTP request, framework-owned `request_id`, method, and matched route template are added to log context. Developer context stays under `data`; it cannot replace the generated request ID. No user identity, session ID, IP address, request body, cookies, CSRF token, or authorization header is added automatically.

The normal `fail_fast` setting is `false`: a sink failure writes only the failure class to PHP's error log and leaves application execution intact. Set it to `true` in controlled environments to receive a `LoggingException`. The HTTP exception handler still preserves the original error response when logging fails. Uncaught 5xx errors are logged once at the HTTP boundary as `error`; CSRF and 405 rejections are `notice`, other expected 4xx errors are `warning`, and routine validation and 404 responses are quiet. The legacy Whoops bootstrap remains separate. Automatic CLI exception logging is not yet centralized; CLI code can call `logger()` explicitly.

## Application-facing Plugins import

Application code may import `App\Plugins\Log`. These entries delegate to the current subsystem; canonical imports and global helpers remain supported. See [Plugins](Plugins.md) for the complete mapping and compatibility rules.
