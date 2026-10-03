<?php

declare(strict_types=1);

namespace App\Http;

use InvalidArgumentException;
use LogicException;

/** Holds one HTTP response until its headers and body are emitted once. */
class Response
{
    private array $headers = [];
    /** @var list<Cookie> Cookies remain distinct HTTP fields, never a comma-joined header. */
    private array $cookies = [];
    private bool $sent = false;

    public function __construct(private string $content = '', private int $status = 200, array $headers = [])
    {
        self::assertStatus($status);
        foreach ($headers as $name => $value) {
            $this->putHeader((string) $name, (string) $value);
        }
    }

    public function content(): string { return $this->content; }
    public function status(): int { return $this->status; }
    public function headers(): array { return $this->headers; }
    /** @return list<Cookie> */
    public function cookies(): array { return $this->cookies; }

    /** Deferred bodies expose no bytes through content() before emission. */
    public function hasDeferredBody(): bool { return false; }

    public function header(string $name, ?string $default = null): ?string
    {
        foreach ($this->headers as $stored => $value) {
            if (strcasecmp($stored, $name) === 0) {
                return $value;
            }
        }
        return $default;
    }

    public function withHeader(string $name, string $value): static
    {
        $copy = clone $this;
        $copy->sent = false;
        $copy->putHeader($name, $value);
        return $copy;
    }

    /** Add a separately emitted Set-Cookie field while preserving response immutability. */
    public function withCookie(Cookie $cookie): static
    {
        $copy = clone $this;
        $copy->sent = false;
        $copy->cookies[] = $cookie;
        return $copy;
    }

    public function withStatus(int $status): static
    {
        self::assertStatus($status);
        $copy = clone $this;
        $copy->sent = false;
        $copy->status = $status;
        return $copy;
    }

    /** Remove a header without changing the response body, status, or subclass. */
    public function withoutHeader(string $name): static
    {
        $copy = clone $this;
        $copy->sent = false;
        foreach (array_keys($copy->headers) as $stored) {
            if (strcasecmp($stored, $name) === 0) {
                unset($copy->headers[$stored]);
            }
        }
        return $copy;
    }

    public function withContent(string $content): static
    {
        if ($this->hasDeferredBody()) {
            throw new LogicException('A deferred response body cannot be replaced with string content.');
        }
        $copy = clone $this;
        $copy->sent = false;
        $copy->content = $content;
        return $copy;
    }

    /**
     * Send once through the same HTTP boundary for ordinary and deferred bodies.
     * Preparation precedes headers so an unreadable file can fail before any
     * response bytes are committed. A producer failure after output begins
     * cannot be converted into another response by the framework.
     */
    public function send(bool $head = false): void
    {
        if ($this->sent) {
            return;
        }
        // HEAD and these status codes never execute a deferred body producer.
        $bodyAllowed = !$head && !in_array($this->status, [204, 205, 304], true);
        try {
            if ($bodyAllowed) {
                $this->prepareBody();
            }
            $this->sent = true;
            if (!headers_sent()) {
                http_response_code($this->status);
                foreach ($this->headers as $name => $value) {
                    header($name . ': ' . $value, true);
                }
                foreach ($this->cookies as $cookie) {
                    header('Set-Cookie: ' . $cookie->headerValue(), false);
                }
            }
            if ($bodyAllowed) {
                $this->emitBody();
            }
        } finally {
            if ($bodyAllowed) {
                $this->finishBody();
            }
        }
    }

    /** Subclasses may open a bounded body source before headers are committed. */
    protected function prepareBody(): void { }

    /** The base response writes the exact PHP string bytes without conversion. */
    protected function emitBody(): void { echo $this->content; }

    /** Release a prepared body source even when emission throws. */
    protected function finishBody(): void { }

    private static function assertStatus(int $status): void
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP status must be between 100 and 599.');
        }
    }

    private function putHeader(string $name, string $value): void
    {
        if (!preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $name)
            || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid HTTP header name or value.');
        }
        foreach (array_keys($this->headers) as $stored) {
            if (strcasecmp($stored, $name) === 0) {
                unset($this->headers[$stored]);
            }
        }
        $this->headers[$name] = $value;
    }
}
