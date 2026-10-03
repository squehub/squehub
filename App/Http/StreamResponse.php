<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

/**
 * Emits an iterable of byte strings only when the normal Response sender runs.
 * The producer is never evaluated by construction, inspection, or cloning.
 */
final class StreamResponse extends Response
{
    /** @var Closure():iterable<string> */
    private Closure $producer;

    /** @param callable():iterable<string> $producer */
    public function __construct(callable $producer, int $status = 200, array $headers = [])
    {
        foreach (array_keys($headers) as $name) {
            if (in_array(strtolower((string) $name), ['content-length', 'transfer-encoding'], true)) {
                throw new InvalidArgumentException('Streaming responses cannot set transfer framing headers.');
            }
        }
        $this->producer = Closure::fromCallable($producer);
        parent::__construct('', $status, $headers);
    }

    public function hasDeferredBody(): bool { return true; }

    public function withContent(string $content): static
    {
        throw new LogicException('A streaming response does not have replaceable string content.');
    }

    public function withHeader(string $name, string $value): static
    {
        if (in_array(strtolower($name), ['content-length', 'transfer-encoding'], true)) {
            throw new InvalidArgumentException('Streaming responses cannot set transfer framing headers.');
        }
        return parent::withHeader($name, $value);
    }

    /**
     * No SqueHub buffer aggregates the chunks. Once bytes leave PHP, a later
     * producer failure cannot be rewritten as a complete error response.
     */
    protected function emitBody(): void
    {
        $chunks = ($this->producer)();
        if (!is_iterable($chunks)) {
            throw new UnexpectedValueException('Stream producer must return an iterable of byte strings.');
        }
        foreach ($chunks as $chunk) {
            if (!is_string($chunk)) {
                throw new UnexpectedValueException('Stream producer yielded a non-string chunk.');
            }
            echo $chunk;
        }
    }
}
