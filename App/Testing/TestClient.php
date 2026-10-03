<?php

declare(strict_types=1);

namespace App\Testing;

use App\Http\Cookie;
use App\Http\Kernel;
use App\Http\Request;
use App\Session\SessionManager;
use JsonException;

/**
 * Sends requests through the real Kernel in one disposable Application.
 * Headers and cookies belong to this client only; a new TestCase gets a new
 * Application and client. The previous session stays active for assertions
 * after a response, then closes just before the next request so flash ages
 * once when that next request begins.
 */
final class TestClient
{
    /** @var array<string, string> */
    private array $headers = [];

    /** @var array<string, string> */
    private array $cookies = [];

    private string $host = 'localhost';
    private string $scheme = 'http';

    public function __construct(private TestApplication $testing)
    {
    }

    public function withHeader(string $name, string $value): self
    {
        $this->withoutHeader($name);
        $this->headers[$name] = $value;
        return $this;
    }

    public function withoutHeader(string $name): self
    {
        foreach (array_keys($this->headers) as $stored) {
            if (strcasecmp($stored, $name) === 0) {
                unset($this->headers[$stored]);
            }
        }
        return $this;
    }

    public function withCookie(string $name, string $value): self
    {
        $this->cookies[$name] = $value;
        return $this;
    }

    public function withoutCookie(string $name): self
    {
        unset($this->cookies[$name]);
        return $this;
    }

    /** Request::host() reads server metadata, as it does in a web request. */
    public function withHost(string $host): self
    {
        if ($host === '' || strlen($host) > 255
            || preg_match('/[\x00-\x20\x7F\/\\\\@]/', $host) === 1) {
            throw new \InvalidArgumentException('Test request host is invalid.');
        }
        $this->host = $host;
        return $this;
    }

    public function withScheme(string $scheme): self
    {
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Test request scheme must be HTTP or HTTPS.');
        }
        $this->scheme = $scheme;
        return $this;
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], $headers);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PUT', $uri, $data, $headers);
    }

    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('PATCH', $uri, $data, $headers);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('DELETE', $uri, $data, $headers);
    }

    public function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, [], ['Accept' => 'application/json', ...$headers]);
    }

    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, ['Accept' => 'application/json', ...$headers], true);
    }

    /**
     * JSON requests encode only caller-provided data. Ordinary form requests
     * keep the Request's form collection, so validation and CSRF use their
     * production paths rather than a test-only dispatcher.
     */
    public function request(string $method, string $uri, array $data = [], array $headers = [],
        bool $json = false): TestResponse
    {
        $app = $this->testing->application();
        $this->testing->loadRoutes();
        $query = [];
        $queryString = parse_url($uri, PHP_URL_QUERY);
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }
        try {
            $body = $json ? json_encode($data, JSON_THROW_ON_ERROR) : '';
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Test JSON body cannot be encoded.', 0, $exception);
        }
        if ($json) {
            $headers = ['Content-Type' => 'application/json', ...$headers];
        }
        $server = [
            'HTTP_HOST' => $this->host,
            'SERVER_NAME' => explode(':', $this->host, 2)[0],
            'HTTPS' => $this->scheme === 'https' ? 'on' : 'off',
        ];
        $request = new Request($method, $uri, $query, $json ? [] : $data,
            $this->cookies, [], [...$this->headers, ...$headers], $server, $body);
        $session = $app->container()->make(SessionManager::class)->store();
        $session->close();
        $response = $app->container()->make(Kernel::class)->handle($request);
        // Preserve the historical single raw header, then apply each typed
        // cookie in wire order. This jar is intentionally name-scoped for
        // isolated Kernel tests; it does not emulate browser domain matching.
        $rawCookie = $response->header('Set-Cookie');
        if ($rawCookie !== null && preg_match('/\A([^=;\s]+)=([^;]*)/', $rawCookie, $match) === 1) {
            if (preg_match('/(?:\A|;)\s*Max-Age=0(?:;|\z)/i', $rawCookie) === 1) {
                unset($this->cookies[$match[1]]);
            } else {
                $this->cookies[$match[1]] = rawurldecode($match[2]);
            }
        }
        foreach ($response->cookies() as $cookie) {
            $this->adoptCookie($cookie);
        }
        return new TestResponse($response);
    }

    private function adoptCookie(Cookie $cookie): void
    {
        if ($cookie->maxAge() === 0 || ($cookie->maxAge() === null
            && $cookie->expires() !== null && $cookie->expires()->getTimestamp() <= time())) {
            unset($this->cookies[$cookie->name()]);
            return;
        }
        $this->cookies[$cookie->name()] = $cookie->value();
    }
}
