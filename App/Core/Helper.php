<?php

/** Global developer helpers and compatibility bridges for the booted Application. */

/** Legacy entry point delegates startup to the Application-owned store. */
function startSessionIfNotStarted()
{
    session()->start();
}

/** Return the one store owned by the current Application. */
function session(): \App\Session\SessionStore
{
    return \App\Session\Session::manager()->store();
}

/** Resolve the Application-owned authentication manager. */
function auth(): \App\Auth\AuthManager
{
    return \App\Auth\Auth::manager();
}

/** Evaluate abilities through the current Application's authorization rules. */
function authorize(): \App\Authorization\AuthorizationManager
{
    return \App\Authorization\Authorization::manager();
}

/** Resolve the Application-owned credential and one-time token service. */
function accountSecurity(): \App\AccountSecurity\AccountSecurityManager
{
    return \App\AccountSecurity\AccountSecurity::manager();
}

/** Resolve Application-owned Crypt without colliding with PHP's crypt(). */
function crypto(): \App\Cryptography\CryptManager
{
    return \App\Cryptography\Crypt::manager();
}

/** Resolve this Application's operational checks without running them. */
function health(): \App\Health\HealthManager
{
    return \App\Health\Health::manager();
}

/** Resolve the Application-owned, application-neutral rate limiter. */
function rateLimiter(): \App\RateLimit\RateLimiter
{
    return \App\RateLimit\RateLimit::manager();
}

/** Resolve the Application-owned mailer without shadowing PHP's mail(). */
function mailer(): \App\Mail\Mailer
{
    return \App\Mail\Mail::manager();
}

/** Resolve Application-owned delivery notifications, distinct from flash messages. */
function notifications(): \App\Notifications\NotificationManager
{
    return \App\Notifications\Notifications::manager();
}

/** Resolve the current Application-owned Queue service. */
function queue(): \App\Queue\QueueManager
{
    return \App\Queue\Queue::manager();
}

/** Resolve this Application's optional Redis service without connecting. */
function redis(): \App\Redis\RedisManager
{
    return \App\Redis\Redis::manager();
}

/** Resolve the current Application's bounded lock leases. */
function lock(): \App\Locks\LockManager
{
    return \App\Locks\Lock::manager();
}

/** Resolve the current Application-owned Scheduler service. */
function schedule(): \App\Scheduler\Scheduler
{
    return \App\Scheduler\Schedule::manager();
}

/** Read nested input flashed by the previous request. */
function old(?string $key = null, mixed $default = null): mixed
{
    return session()->old($key, $default);
}

/** Resolve a named URL through the active v2 registry or a standalone legacy Router. */
function route($name, $params = [])
{
    // Standalone legacy Router instances can still resolve named routes.
    $registry = class_exists(\App\Routing\Route::class) ? \App\Routing\Route::registry() : null;
    if ($registry !== null) {
        return $registry->url($name, $params);
    }

    global $router;
    if ($router instanceof \Router) {
        return $router->route($name, $params);
    }

    throw new \LogicException('No route registry or legacy router is available.');
}

/** Resolve an application asset against the selected public URL mount. */
function asset(string $url): string
{
    $container = \App\Core\View::application()->container();
    return $container->make(\App\Frontend\AssetMapper::class)->url($url);
}

/** Read one key from the selected Application's loaded configuration. */
function config(string $key, mixed $default = null): mixed
{
    return \App\Core\View::application()->config()->get($key, $default);
}

/** Resolve only the active HTTP Request; CLI code has no implicit request. */
function request(): \App\Http\Request
{
    return \App\Core\View::application()->views()->currentRequest()
        ?? throw new \LogicException('No HTTP Request is active for the selected Application.');
}

/** Issue the one session-bound token used by modern and legacy forms. */
function csrf_token(): string
{
    return \App\Security\Csrf\Csrf::manager()->token();
}

/** Build the trusted form control at render time, using the configured name. */
function csrf_field(): string
{
    $tokens = \App\Security\Csrf\Csrf::manager();
    return '<input type="hidden" name="' . \App\Core\ViewEscaper::escape($tokens->field())
        . '" value="' . \App\Core\ViewEscaper::escape($tokens->token()) . '">';
}

/** Emit only a validated browser-form method; the transport remains POST. */
function method_field(#[\SensitiveParameter] mixed $method): string
{
    $normalized = \App\Http\Request::normalizeFormMethod($method);
    if ($normalized === null) {
        throw new \InvalidArgumentException('Form method must be PUT, PATCH, or DELETE.');
    }
    return '<input type="hidden" name="_method" value="' . $normalized . '">';
}

/** Return one fixed HTML attribute name, with no user value interpolation. */
function checked(mixed $condition): string
{
    if (!is_bool($condition)) {
        throw new \InvalidArgumentException('checked() requires a boolean condition.');
    }
    return $condition ? 'checked' : '';
}

/** Return one fixed HTML attribute name, with no loose-value comparison. */
function selected(mixed $condition): string
{
    if (!is_bool($condition)) {
        throw new \InvalidArgumentException('selected() requires a boolean condition.');
    }
    return $condition ? 'selected' : '';
}

/** Encode a JSON value with mandatory inline-script safety flags. */
function json(mixed $value): string
{
    return \App\View\TemplateUtilities::json($value);
}

/** Compose application-supplied class entries as ordinary escapable text. */
function classes(array $entries): string
{
    return \App\View\TemplateUtilities::classes($entries);
}

/** Match one or more names against the selected Application environment. */
function environment(string ...$names): bool
{
    if ($names === []) {
        throw new \InvalidArgumentException('environment() requires at least one name.');
    }
    return in_array(\App\Core\View::application()->environment(), $names, true);
}

/** Read the selected Application's debug setting for presentation. */
function debugging(): bool
{
    return \App\Core\View::application()->isDebug();
}

/** Read the one flashed validation bag without consuming its request lifetime. */
function errors(): \App\Validation\ErrorBag
{
    return \App\Core\View::errorBag();
}

/** Preserve the v1 manual entry point with the v2 verifier and safe 403 error. */
function validateCsrfToken(): void
{
    if (!CsrfTokenValidator()) throw new \App\Security\Csrf\CsrfException();
}

/** Historical POST-only boolean check; both body spellings share one token. */
function CsrfTokenValidator(): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return true;
    return \App\Security\Csrf\Csrf::manager()->verify($_POST['_csrf'] ?? $_POST['_token'] ?? null);
}

/** Legacy superglobal check; CLI has no request and returns false. */
function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? null) === 'POST';
}

/** Retain the v1 mask without raising a warning for a malformed address. */
function maskEmail(string $email): string
{
    [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $masked = substr($name, 0, 2) . str_repeat('*', max(strlen($name) - 2, 0));
    return $masked . '@' . $domain;
}

function maskPhoneNumber(string $phone): string
{
    $len = strlen($phone);
    return $len <= 2 ? str_repeat('*', $len) : str_repeat('*', $len - 2) . substr($phone, -2);
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = preg_replace('~-+~', '-', trim($text, '-'));
    return strtolower($text ?: 'n-a');
}

/**
 * Keep the historical _flash map and consume-on-read API. The surrounding
 * map is marked as one flash key, so reading one message must preserve the
 * original expiry of any sibling messages.
 */
function flash(string $key, ?string $message = null)
{
    $store = session();
    if ($message !== null) {
        $messages = $store->get('_flash', []);
        $messages[$key] = $message;
        $store->flash('_flash', $messages);
    } else {
        $messages = $store->get('_flash', []);
        $value = $messages[$key] ?? null;
        unset($messages[$key]);
        if ($messages === []) $store->forget('_flash');
        else $store->replaceKeepingFlash('_flash', $messages);
        return $value;
    }
}

function priceFormatter(float $amount, string $currency = '', bool $symbolBefore = true, int $decimals = 2): string
{
    $formatted = number_format($amount, $decimals);
    return $currency !== ''
        ? ($symbolBefore ? $currency . $formatted : $formatted . ' ' . $currency)
        : $formatted;
}

/** @deprecated This v1 URL uses an untrusted raw host; use route() for named paths. */
function url(...$pathSegments)
{
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = implode('/', array_map(fn($s) => trim($s, '/'), $pathSegments));
    return rtrim($protocol . '://' . $host . '/' . $path, '/');
}

/**
 * A supplied application path produces a returnable, mounted Response. The
 * no-argument object remains solely for v1 immediate-header compatibility;
 * new code must return the Response from this helper instead.
 */
function redirect(?string $path = null, int $status = 302): object
{
    if (func_num_args() !== 0) {
        if ($path === null || \App\Http\BrowserNavigation::internalPath($path) === null) {
            throw new \InvalidArgumentException('Redirect path must be a safe application-root path.');
        }
        $basePath = \App\Core\View::application()->container()
            ->make(\App\Foundation\UrlBasePath::class);
        return new \App\Http\RedirectResponse($basePath->publicPath($path), $status);
    }

    return new class {
        /** @deprecated Return redirect($path) from new controllers instead. */
        public function to(string $url)
        {
            header("Location: $url");
            exit;
        }

        /** @deprecated Use redirectBack($request) for same-origin navigation. */
        public function back()
        {
            $referer = $_SERVER['HTTP_REFERER'] ?? '/';
            header("Location: $referer");
            exit;
        }
    };
}

/**
 * Return to a safe browser GET destination or a validated application path.
 * BrowserNavigation owns same-origin Referer checks; this helper only builds
 * the mounted RedirectResponse and never emits headers or exits.
 */
function redirectBack(\App\Http\Request $request, string $fallback = '/', int $status = 303): \App\Http\RedirectResponse
{
    if (\App\Http\BrowserNavigation::internalPath($fallback) === null) {
        throw new \InvalidArgumentException('Redirect fallback must be a safe application-root path.');
    }
    $app = \App\Core\View::application();
    $container = $app->container();
    $basePath = $container->make(\App\Foundation\UrlBasePath::class);
    if ($request->basePath() !== $basePath->value()) {
        throw new \LogicException('Redirect Request has a different URL base path.');
    }
    $navigation = $container->has(\App\Http\BrowserNavigation::class)
        ? $container->make(\App\Http\BrowserNavigation::class)
        : null;
    $destination = $navigation?->back($request) ?? $fallback;
    if (\App\Http\BrowserNavigation::internalPath($destination) === null) {
        throw new \LogicException('Browser navigation returned an invalid path.');
    }
    return new \App\Http\RedirectResponse($basePath->publicPath($destination), $status);
}

/** Return a response factory with no arguments, or one returnable HTTP response. */
function response(?string $content = null, int $status = 200, array $headers = []): \App\Http\Response|\App\Http\ResponseFactory
{
    $factory = new \App\Http\ResponseFactory();
    return func_num_args() === 0 ? $factory : $factory->make($content ?? '', $status, $headers);
}

/** Resolve the booted database manager without opening a connection. */
function database(): \App\Database\DatabaseManager
{
    return \App\Database\Database::manager();
}

/** Start a raw table query with no Model scopes or soft-delete policy. */
function db(string $table): \App\Database\QueryBuilder
{
    return database()->table($table);
}

/** Select the schema facade for the configured or explicitly named connection. */
function schema(?string $connection = null): \App\Database\Schema\Schema
{
    return database()->schema($connection);
}

/** Standalone non-throwing validation; request validation uses the Application factory. */
function validator(array $data): \App\Validation\Validator
{
    return new \App\Validation\Validator($data);
}

/** Resolve the Application's configured logger without opening a new channel. */
function logger(): \App\Logging\Logger
{
    return \App\Logging\Log::logger();
}

/** Resolve the configured Application cache without exposing its driver. */
function cache(): \App\Cache\CacheStore
{
    return \App\Cache\Cache::store();
}

/** Resolve the current Application's storage manager; drives remain lazy. */
function storage(): \App\Storage\StorageManager
{
    return \App\Storage\Storage::manager();
}

/** Resolve the current Application's synchronous event dispatcher. */
function events(): \App\Events\EventDispatcher
{
    return \App\Events\Events::dispatcher();
}

/** Inspect safe operational metadata for the current HTTP request. */
function diagnostics(): \App\Diagnostics\Diagnostics
{
    return \App\Diagnostics\Diagnostic::current();
}

/** Resolve the current Application's outgoing HTTP Client. */
function httpClient(): \App\HttpClient\HttpClient
{
    return \App\HttpClient\Http::client();
}

/** Resolve this Application's configured external OpenID Connect clients. */
function oauth(): \App\OAuth\OAuthManager
{
    return \App\OAuth\OAuth::manager();
}

/** Resolve this Application's named incoming and outgoing Webhook peers. */
function webhooks(): \App\Webhooks\WebhookManager
{
    return \App\Webhooks\Webhook::manager();
}
