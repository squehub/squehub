<?php

declare(strict_types=1);

namespace App\Idempotency;

use App\Http\Response;

/** Only a bounded body, status, and Content-Type may cross the replay boundary. */
final readonly class ResponseSnapshot
{
    public function __construct(public int $status, public string $body, public ?string $contentType)
    {
        if ($status < 200 || $status >= 300 || strlen($body) > 32768
            || ($contentType !== null && strlen($contentType) > 256)) {
            throw new IdempotencyException('Idempotency response snapshot is invalid.');
        }
    }

    public static function capture(Response $response, int $maxBytes): ?self
    {
        if ($response->status() < 200 || $response->status() >= 300
            || $response->hasDeferredBody() || $response->cookies() !== []
            || $response->header('Set-Cookie') !== null
            || strlen($response->content()) > $maxBytes) {
            return null;
        }
        $type = $response->header('Content-Type');
        if ($type !== null && strlen($type) > 256) return null;
        return new self($response->status(), $response->content(), $type);
    }

    /** @return array{status:int,body:string,content_type:?string} */
    public function toArray(): array
    {
        return ['status' => $this->status, 'body' => base64_encode($this->body),
            'content_type' => $this->contentType];
    }

    public static function fromArray(mixed $value): self
    {
        if (!is_array($value) || count($value) !== 3
            || array_diff(array_keys($value), ['status', 'body', 'content_type']) !== []
            || !is_int($value['status']) || !is_string($value['body'])
            || ($value['content_type'] !== null && !is_string($value['content_type']))) {
            throw new IdempotencyException('Idempotency response snapshot is corrupt.');
        }
        $body = base64_decode($value['body'], true);
        if ($body === false) throw new IdempotencyException('Idempotency response snapshot is corrupt.');
        return new self($value['status'], $body, $value['content_type']);
    }

    public function response(): Response
    {
        $headers = $this->contentType === null ? [] : ['Content-Type' => $this->contentType];
        return new Response($this->body, $this->status, $headers);
    }
}
