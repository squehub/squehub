<?php

declare(strict_types=1);

namespace App\Api\Contract;

use App\Foundation\Application;
use Closure;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * One deliberately registered HTTP example. Inputs remain in Application memory
 * and never enter the public contract or a verification report.
 */
final class VerificationCase
{
    private ?string $operationId = null;
    private ?string $method = null;
    private ?string $uri = null;
    /** @var array<string,int|string> */
    private array $routeParameters = [];
    private array $query = [];
    private array $headers = [];
    private array $cookies = [];
    private array $form = [];
    private mixed $jsonBody = null;
    private bool $hasJsonBody = false;
    private ?int $expectedStatus = null;
    private bool $mutation = false;
    /** @var ?Closure(Application):void */
    private ?Closure $setup = null;
    /** @var ?Closure(Application):void */
    private ?Closure $cleanup = null;

    public function __construct(private readonly string $name)
    {
        OperationContract::identifier($name, 'verification case name');
    }

    public function name(): string { return $this->name; }
    public function operationId(): ?string { return $this->operationId; }
    public function methodValue(): ?string { return $this->method; }
    public function uriValue(): ?string { return $this->uri; }
    public function routeParameters(): array { return $this->routeParameters; }
    public function queryValues(): array { return $this->query; }
    public function headerValues(): array { return $this->headers; }
    public function cookieValues(): array { return $this->cookies; }
    public function formValues(): array { return $this->form; }
    public function hasJsonBody(): bool { return $this->hasJsonBody; }
    public function jsonValue(): mixed { return $this->jsonBody; }
    public function expectedStatus(): ?int { return $this->expectedStatus; }
    public function isMutation(): bool { return $this->mutation; }
    public function setupCallback(): ?Closure { return $this->setup; }
    public function cleanupCallback(): ?Closure { return $this->cleanup; }

    public function operation(string $id): self
    {
        OperationContract::identifier($id, 'operation ID');
        $this->operationId = $id;
        return $this;
    }

    /** Override the contracted method only for an explicit negative branch. */
    public function method(string $method): self
    {
        $method = strtoupper($method);
        if (!in_array($method, ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'], true)) {
            throw new InvalidArgumentException('Verification HTTP method is invalid.');
        }
        $this->method = $method;
        return $this;
    }

    /** Override the route path for a deliberate routing-error case. */
    public function uri(string $uri): self
    {
        if ($uri === '' || $uri[0] !== '/' || strlen($uri) > 2048
            || preg_match('/[\x00-\x20\x7F?#\\\\]/', $uri) === 1
            || str_contains($uri, '//')) {
            throw new InvalidArgumentException('Verification URI must be a bounded internal path.');
        }
        $this->uri = $uri;
        return $this;
    }

    /** Route values are substituted as one encoded segment, never as a path. */
    public function route(#[SensitiveParameter] array $parameters): self
    {
        foreach ($parameters as $name => $value) {
            if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || (!is_int($value) && !is_string($value))) {
                throw new InvalidArgumentException('Verification route parameters must have scalar identifiers.');
            }
        }
        $this->routeParameters = $parameters;
        return $this;
    }

    public function query(#[SensitiveParameter] array $query): self
    {
        $this->query = $query;
        return $this;
    }

    public function headers(#[SensitiveParameter] array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)
                || preg_match('/\A[!#$%&\'*+.^_`|~0-9A-Za-z-]+\z/D', $name) !== 1
                || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Verification headers require safe string names and values.');
            }
        }
        $this->headers = $headers;
        return $this;
    }

    public function cookies(#[SensitiveParameter] array $cookies): self
    {
        $this->cookies = $cookies;
        return $this;
    }

    public function form(#[SensitiveParameter] array $form): self
    {
        $this->form = $form;
        return $this;
    }

    public function json(#[SensitiveParameter] mixed $body): self
    {
        $this->jsonBody = $body;
        $this->hasJsonBody = true;
        return $this;
    }

    public function expectStatus(int $status): self
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('Verification expected status must be 100-599.');
        }
        $this->expectedStatus = $status;
        return $this;
    }

    /** Mutation classification is explicit because even a GET can change state. */
    public function mutation(): self
    {
        $this->mutation = true;
        return $this;
    }

    /** Setup and cleanup are invoked only during deliberate case execution. */
    public function setup(callable $callback): self
    {
        $this->setup = Closure::fromCallable($callback);
        return $this;
    }

    public function cleanup(callable $callback): self
    {
        $this->cleanup = Closure::fromCallable($callback);
        return $this;
    }
}
