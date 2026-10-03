<?php

declare(strict_types=1);

namespace SqueHub\Tests\Fixtures;

use App\Storage\Providers\S3ClientFailure;
use App\Storage\Providers\S3ObjectClient;
use DateTimeImmutable;
use DateTimeZone;

/** Deterministic object-key fake; never opens a socket or infers directories. */
final class FakeS3ObjectClient implements S3ObjectClient
{
    /** @var array<string,array{bytes:string,modified:DateTimeImmutable,mime:?string}> */
    public array $objects = [];
    public ?string $failOperation = null;
    public ?string $failKey = null;
    public string $failReason = S3ClientFailure::PROVIDER_FAILURE;
    /** @var list<string> */
    public array $listedPrefixes = [];

    public function head(string $key): ?array
    {
        $this->fail('head', $key);
        $object = $this->objects[$key] ?? null;
        return $object === null ? null : ['size' => strlen($object['bytes']),
            'modified' => $object['modified'], 'mime' => $object['mime'], 'etag' => null];
    }

    public function read(string $key): string
    {
        $this->fail('read', $key);
        if (!isset($this->objects[$key])) throw new S3ClientFailure(S3ClientFailure::NOT_FOUND);
        return $this->objects[$key]['bytes'];
    }

    public function readStream(string $key)
    {
        $bytes = $this->read($key);
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);
        return $stream;
    }

    public function write(string $key, string $contents): void
    {
        $this->fail('write', $key);
        $this->objects[$key] = ['bytes' => $contents,
            'modified' => (new DateTimeImmutable('2026-10-01T00:00:00Z'))->setTimezone(new DateTimeZone('UTC')),
            'mime' => str_ends_with($key, '/') ? null : 'application/octet-stream'];
    }

    public function writeStream(string $key, $stream): int
    {
        $this->fail('writeStream', $key);
        $bytes = stream_get_contents($stream);
        if ($bytes === false) throw new S3ClientFailure();
        $this->write($key, $bytes);
        return strlen($bytes);
    }

    public function delete(string $key): void
    {
        $this->fail('delete', $key);
        unset($this->objects[$key]);
    }

    public function deleteMany(array $keys): void
    {
        foreach ($keys as $key) $this->delete($key);
    }

    public function copy(string $source, string $destination): void
    {
        $this->fail('copy', $destination);
        $this->write($destination, $this->read($source));
    }

    public function list(string $prefix, ?string $continuation = null, int $limit = 1000): array
    {
        $this->fail('list', $prefix);
        $this->listedPrefixes[] = $prefix;
        $keys = array_values(array_filter(array_keys($this->objects),
            static fn (string $key): bool => str_starts_with($key, $prefix)
                && ($continuation === null || strcmp($key, $continuation) > 0)));
        sort($keys, SORT_STRING);
        $page = array_slice($keys, 0, $limit);
        return ['keys' => $page, 'next' => count($keys) > $limit ? end($page) : null];
    }

    private function fail(string $operation, string $key): void
    {
        if ($this->failOperation === $operation && ($this->failKey === null || $this->failKey === $key)) {
            throw new S3ClientFailure($this->failReason);
        }
    }
}
