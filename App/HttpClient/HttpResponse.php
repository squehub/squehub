<?php

declare(strict_types=1);

namespace App\HttpClient;

use JsonException;

/** Immutable completed exchange; body bytes are preserved without charset conversion. */
final class HttpResponse
{
    /** @param array<string,list<string>> $headers Lowercase names with every received value. */
    public function __construct(private int $status, private string $body = '', private array $headers = [])
    {
        if ($status < 100 || $status > 599) throw new HttpConfigurationException('HTTP response status is invalid.');
        $normalized = [];
        foreach ($headers as $name => $values) {
            PendingRequest::validateHeaderName($name);
            if (!is_array($values) || !array_is_list($values)) {
                throw new HttpConfigurationException('HTTP response headers are invalid.');
            }
            foreach ($values as $value) {
                if (!is_string($value)) throw new HttpConfigurationException('HTTP response headers are invalid.');
                PendingRequest::validateHeaderValue($value);
            }
            $normalized[strtolower($name)] = $values;
        }
        $this->headers = $normalized;
    }

    public function status(): int { return $this->status; }
    public function body(): string { return $this->body; }
    /** @return array<string,list<string>> */
    public function headers(): array { return $this->headers; }
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }
    public function successful(): bool { return $this->status >= 200 && $this->status < 300; }
    public function failed(): bool { return !$this->successful(); }
    public function clientError(): bool { return $this->status >= 400 && $this->status < 500; }
    public function serverError(): bool { return $this->status >= 500; }

    /** JSON null is a valid result; empty or malformed bodies raise an error. */
    public function json(): mixed
    {
        try { return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $exception) {
            throw new HttpDecodeException('External HTTP response is not valid JSON.', 0, $exception);
        }
    }

    public function throw(): self
    {
        if ($this->status >= 400) throw new HttpStatusException($this->status);
        return $this;
    }
}
