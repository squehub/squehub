<?php

declare(strict_types=1);

namespace App\Http;

use App\Data\DataMapper;
use App\Data\DataMappingException;
use App\Data\ValidatedData;
use App\Http\Exception\MalformedJsonException;
use App\Container\Container;
use App\Foundation\UrlBasePath;
use App\Validation\UploadedFile;
use App\Validation\ValidationException;
use App\Validation\ValidatorFactory;
use JsonException;

/** One captured HTTP request. Body, query, headers and server values stay stable. */
final class Request
{
    private string $method;
    private string $transportMethod;
    private string $uri;
    private array $query;
    private array $form;
    private array $cookies;
    private array $files;
    private array $headers = [];
    private array $headerNames = [];
    private array $headerConflicts = [];
    private array $headerRepeats = [];
    private array $server;
    private string $rawBody;
    private ?array $json = null;
    private array $attributes = [];
    private ?ValidatorFactory $validatorFactory = null;
    private ?string $requestId = null;
    private ?string $effectiveIp = null;
    private ?string $effectiveHost = null;
    private ?string $effectiveScheme = null;
    private ?string $effectivePath = null;
    private string $urlBasePath = '';

    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $query = [],
        array $form = [],
        array $cookies = [],
        array $files = [],
        array $headers = [],
        array $server = [],
        string $rawBody = ''
    ) {
        $this->transportMethod = strtoupper($method);
        $this->method = $this->transportMethod;
        $this->uri = $uri === '' ? '/' : $uri;
        $this->query = $query;
        $this->form = $form;
        $this->cookies = $cookies;
        $this->files = $files;
        $this->server = $server;
        $this->rawBody = $rawBody;
        foreach ($headers as $name => $value) {
            $this->storeHeader((string) $name, (string) $value);
        }
        // Only a real POST form can tunnel a browser's limited method set.
        // JSON, ambiguous Content-Type headers, and URL/cookie values cannot
        // influence route matching or the global CSRF decision.
        if ($this->transportMethod === 'POST' && !$this->headerRepeated('Content-Type')
            && in_array($this->contentType(), [null, 'application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
            $this->method = self::normalizeFormMethod($this->form['_method'] ?? null) ?? 'POST';
        }
    }

    /** Snapshot superglobals and the raw body once; later global changes do not alter the Request. */
    public static function capture(): self
    {
        $server = $_SERVER;
        $headers = [];
        foreach ($server as $key => $value) {
            // CLI/web server environments may add numeric SERVER entries;
            // only named string entries can represent HTTP headers.
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', substr($key, 5))] = $value;
            } elseif ($key === 'CONTENT_TYPE' || $key === 'CONTENT_LENGTH') {
                $headers[str_replace('_', '-', $key)] = $value;
            }
        }

        return new self(
            (string) ($server['REQUEST_METHOD'] ?? 'GET'),
            (string) ($server['REQUEST_URI'] ?? '/'),
            $_GET,
            $_POST,
            $_COOKIE,
            $_FILES,
            $headers,
            $server,
            (string) file_get_contents('php://input')
        );
    }

    public function method(): string { return $this->method; }

    /** The original wire method remains available when a POST form tunnels PUT, PATCH, or DELETE. */
    public function transportMethod(): string { return $this->transportMethod; }

    /** Share the browser-form allowlist with the hidden-field helper; invalid input has no override. */
    public static function normalizeFormMethod(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 6) return null;
        $method = strtoupper($value);
        return in_array($method, ['PUT', 'PATCH', 'DELETE'], true) ? $method : null;
    }

    public function uri(): string { return $this->uri; }

    /** Exact captured bytes for signature verification; treat the result as sensitive input. */
    public function rawBody(): string { return $this->rawBody; }

    /** Opaque server correlation; incoming headers and request attributes are never trusted. */
    public function requestId(): string
    {
        return $this->requestId ??= bin2hex(random_bytes(16));
    }

    /** Resolved version of the matched API route, or null for unversioned requests. */
    public function apiVersion(): ?string
    {
        $version = $this->attribute('_squehub.api_version');
        return is_string($version) ? $version : null;
    }

    /** @internal Begin a distinct handling attempt, including reuse of a Request instance. */
    public function renewRequestId(): void
    {
        $this->requestId = bin2hex(random_bytes(16));
    }

    /** The original URI path before the Application removes its URL mount. */
    public function rawPath(): string
    {
        $path = parse_url($this->uri, PHP_URL_PATH);
        return is_string($path) && $path !== '' ? $path : '/';
    }

    /** The application-relative route path after Kernel mount resolution. */
    public function path(): string
    {
        return $this->effectivePath ?? '/' . trim($this->rawPath(), '/');
    }

    /** The selected URL mount, never the filesystem Application base path. */
    public function basePath(): string
    {
        return $this->urlBasePath;
    }

    /** @internal Return false when the raw request does not belong to this mount. */
    public function applyUrlBasePath(UrlBasePath $basePath): bool
    {
        $this->resetUrlBasePath();
        $this->urlBasePath = $basePath->value();
        $path = $basePath->strip($this->rawPath());
        if ($path === null) return false;
        $this->effectivePath = '/' . trim($path, '/');
        return true;
    }

    /** @internal A reused Request must not retain another Application's mount. */
    public function resetUrlBasePath(): void
    {
        $this->effectivePath = null;
        $this->urlBasePath = '';
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->query : ($this->query[$key] ?? $default);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $input = $this->all();
        return $input[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->isJsonContentType() ? $this->json() : $this->form;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->all(), array_flip($keys));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if (!$this->isJsonContentType()) {
            return $key === null ? [] : $default;
        }
        if ($this->json === null) {
            if (trim($this->rawBody) === '') {
                $this->json = [];
            } else {
                try {
                    $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $exception) {
                    throw new MalformedJsonException();
                }
                if (!is_array($decoded)) {
                    throw new MalformedJsonException();
                }
                $this->json = $decoded;
            }
        }
        return $key === null ? $this->json : ($this->json[$key] ?? $default);
    }

    public function headers(): array { return $this->headers; }

    public function header(string $name, mixed $default = null): mixed
    {
        $stored = $this->headerNames[strtolower($name)] ?? null;
        return $stored === null ? $default : $this->headers[$stored];
    }

    /** Whether differently cased copies of one header supplied conflicting values. */
    public function headerConflict(string $name): bool
    {
        return $this->headerConflicts[strtolower(str_replace('_', '-', $name))] ?? false;
    }

    /** Repeated security headers remain ambiguous even when their values agree. */
    public function headerRepeated(string $name): bool
    {
        return $this->headerRepeats[strtolower(str_replace('_', '-', $name))] ?? false;
    }

    /** Accept one bounded Bearer credential from the Authorization header only. */
    public function bearerToken(): ?string
    {
        if ($this->headerRepeated('Authorization')) return null;
        $header = $this->header('Authorization');
        return is_string($header) && strlen($header) <= 2048
            && preg_match('/\ABearer ([A-Za-z0-9._~+\/-]+=*)\z/iD', $header, $match)
            ? $match[1] : null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /** Cookie values are request data; callers must not log or expose this snapshot. */
    public function cookies(): array { return $this->cookies; }

    public function file(string $key, mixed $default = null): mixed
    {
        return $this->files[$key] ?? $default;
    }

    public function files(): array { return $this->files; }

    /** Validate body/JSON and uploaded files; query, route, cookies and headers stay separate. */
    public function validate(array $rules, array $messages = []): array
    {
        $input = $this->all();
        foreach ($this->files as $field => $entry) {
            $input[$field] = self::normalizeUpload($entry);
        }
        $factory = $this->validatorFactory ?? new ValidatorFactory(new Container());
        $result = $factory->for($input)->check($rules, $messages);
        if ($result->fails()) {
            throw new ValidationException($result->errors());
        }
        return $result->validated();
    }

    /**
     * Validate the ordinary body/upload snapshot, then construct a typed value.
     *
     * Explicit rules work with any plain class. Omitted rules require the
     * class to opt in with ValidatedData; unchecked request input never reaches
     * a constructor. Input conversion failures reuse the established 422 and
     * browser-form flow, while invalid definitions and constructor invariants
     * remain application errors.
     *
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function validatedAs(string $class, ?array $rules = null, array $messages = []): object
    {
        if ($rules === null) {
            if (!is_subclass_of($class, ValidatedData::class)) {
                throw new DataMappingException('rules_missing');
            }
            $rules = $class::rules();
        }
        $validated = $this->validate($rules, $messages);
        try {
            return DataMapper::map($class, $validated);
        } catch (DataMappingException $exception) {
            if (!$exception->isInputFailure()) {
                throw $exception;
            }
            throw new ValidationException([
                $exception->field() => [$exception->validationMessage()],
            ], $exception);
        }
    }

    /** @internal The Kernel attaches the Application's factory before controller dispatch. */
    public function setValidatorFactory(ValidatorFactory $factory): void
    {
        $this->validatorFactory = $factory;
    }

    private static function normalizeUpload(mixed $entry): mixed
    {
        if (!is_array($entry)) return $entry;
        if (array_key_exists('error', $entry) && !is_array($entry['error'])) {
            return UploadedFile::fromPhpEntry($entry);
        }
        if (isset($entry['error'], $entry['tmp_name'], $entry['name'], $entry['size']) && is_array($entry['error'])) {
            $files = [];
            foreach ($entry['error'] as $index => $error) {
                $files[$index] = self::normalizeUpload([
                    'error' => $error, 'tmp_name' => $entry['tmp_name'][$index] ?? null,
                    'name' => $entry['name'][$index] ?? null, 'size' => $entry['size'][$index] ?? null,
                ]);
            }
            return $files;
        }
        return array_map(self::normalizeUpload(...), $entry);
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    /**
     * Keep the captured server values intact. The Kernel replaces these
     * per-request overrides after the selected Application checks its proxy
     * policy; a reused Request cannot inherit another application's trust.
     *
     * @internal
     */
    public function applyTrustedProxyPolicy(TrustedProxyPolicy $policy): void
    {
        $this->resetTrustedProxyMetadata();
        $resolved = $policy->resolve($this);
        $this->effectiveIp = $resolved['ip'];
        $this->effectiveHost = $resolved['host'];
        $this->effectiveScheme = $resolved['scheme'];
    }

    /** @internal Clear prior handling state before a Kernel selects its policy. */
    public function resetTrustedProxyMetadata(): void
    {
        $this->effectiveIp = null;
        $this->effectiveHost = null;
        $this->effectiveScheme = null;
    }

    /** @internal The Router validates this same effective authority strictly. */
    public function effectiveAuthorityHeader(): ?string
    {
        if ($this->effectiveHost !== null) return $this->effectiveHost;
        $raw = $this->server['HTTP_HOST'] ?? $this->server['SERVER_NAME'] ?? 'localhost';
        return is_string($raw) ? $raw : null;
    }

    /**
     * A read-only CORS route probe uses the authority already resolved for
     * this request. Re-resolving from a partial server copy could change trust.
     *
     * @internal
     */
    public function forRouteProbe(string $method): self
    {
        $probe = new self($method, $this->uri, headers: $this->headers, server: $this->server);
        $probe->effectiveIp = $this->effectiveIp;
        $probe->effectiveHost = $this->effectiveHost;
        $probe->effectiveScheme = $this->effectiveScheme;
        $probe->effectivePath = $this->effectivePath;
        $probe->urlBasePath = $this->urlBasePath;
        return $probe;
    }

    public function host(): string
    {
        $authority = $this->effectiveAuthorityHeader();
        return $authority === null ? '' : (HostAuthority::normalize($authority) ?? '');
    }

    public function scheme(): string
    {
        if ($this->effectiveScheme !== null) return $this->effectiveScheme;
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return in_array($https, ['on', '1', 'true'], true) ? 'https' : 'http';
    }

    public function ip(): ?string
    {
        if ($this->effectiveIp !== null) return $this->effectiveIp;
        $raw = $this->server['REMOTE_ADDR'] ?? null;
        return is_string($raw) && filter_var($raw, FILTER_VALIDATE_IP) !== false ? $raw : null;
    }

    /** The public authority port; internal listener ports do not override trusted public metadata. */
    public function port(): int
    {
        // A trusted public scheme cannot inherit an internal listener port.
        // Only a trusted forwarded authority may provide its explicit port.
        if ($this->effectiveHost !== null || $this->effectiveScheme === null) {
            $authority = $this->effectiveAuthorityHeader();
            $explicit = $authority === null ? null : HostAuthority::port($authority);
            if ($explicit !== null) return $explicit;
        }
        if ($this->effectiveHost === null && $this->effectiveScheme === null) {
            $raw = $this->server['SERVER_PORT'] ?? null;
            if ((is_int($raw) || is_string($raw)) && preg_match('/\A[0-9]{1,5}\z/D', (string) $raw) === 1
                && (int) $raw >= 1 && (int) $raw <= 65535) return (int) $raw;
        }
        return $this->scheme() === 'https' ? 443 : 80;
    }

    public function contentType(): ?string
    {
        $value = $this->header('Content-Type');
        return is_string($value) ? strtolower(trim(explode(';', $value, 2)[0])) : null;
    }

    public function expectsJson(): bool
    {
        $accept = strtolower((string) $this->header('Accept', ''));
        return $this->isJsonContentType() || (bool) preg_match('~(?:/json|\+json)(?:\s*[;,]|\s*$)~', $accept);
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /** Parameters from the matched route, separate from query and body input. */
    public function route(?string $key = null, mixed $default = null): mixed
    {
        $params = $this->attribute('route.params', []);
        return $key === null ? $params : ($params[$key] ?? $default);
    }

    private function isJsonContentType(): bool
    {
        $type = $this->contentType();
        return $type === 'application/json' || ($type !== null && str_ends_with($type, '+json'));
    }

    private function storeHeader(string $name, string $value): void
    {
        $normalized = implode('-', array_map('ucfirst', explode('-', strtolower(str_replace('_', '-', $name)))));
        $key = strtolower($normalized);
        $previous = $this->headerNames[$key] ?? null;
        if ($previous !== null) {
            $this->headerRepeats[$key] = true;
            if ($this->headers[$previous] !== $value) {
                $this->headerConflicts[$key] = true;
            }
            unset($this->headers[$previous]);
        }
        $this->headers[$normalized] = $value;
        $this->headerNames[$key] = $normalized;
    }
}
