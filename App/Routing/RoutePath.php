<?php

declare(strict_types=1);

namespace App\Routing;

/** Starts a route with its path; registration occurs when an HTTP verb is chosen. */
final class RoutePath
{
    private ?string $host = null;

    public function __construct(
        private RouteRegistry $registry,
        private string $uri
    ) {
    }

    /** Restrict this path-first declaration before choosing its verb or fallback. */
    public function host(string $host): self
    {
        $normalized = (new RoutePattern('/', $host))->host();
        if ($this->host !== null && $this->host !== $normalized) {
            throw new \LogicException('A route path cannot declare conflicting hosts.');
        }
        $this->host = $normalized;
        return $this;
    }

    public function get(mixed $action): RouteDefinition
    {
        return $this->registry->get($this->uri, $action, $this->host);
    }

    public function post(mixed $action): RouteDefinition
    {
        return $this->registry->post($this->uri, $action, $this->host);
    }

    public function put(mixed $action): RouteDefinition
    {
        return $this->registry->put($this->uri, $action, $this->host);
    }

    public function patch(mixed $action): RouteDefinition
    {
        return $this->registry->patch($this->uri, $action, $this->host);
    }

    public function delete(mixed $action): RouteDefinition
    {
        return $this->registry->delete($this->uri, $action, $this->host);
    }

    public function options(mixed $action): RouteDefinition
    {
        return $this->registry->options($this->uri, $action, $this->host);
    }

    /** Catch unmatched requests within this static path prefix after 405 checks. */
    public function fallback(mixed $action): RouteDefinition
    {
        return $this->registry->addFallback($this->uri, $action, $this->host);
    }
}
