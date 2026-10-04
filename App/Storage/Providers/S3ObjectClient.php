<?php

declare(strict_types=1);

namespace App\Storage\Providers;

use DateTimeImmutable;

/** Small, SDK-independent boundary for one configured S3 bucket. */
interface S3ObjectClient
{
    /** @return array{size:int,modified:DateTimeImmutable,mime:?string,etag:?string}|null */
    public function head(string $key): ?array;

    public function read(string $key): string;

    /** Caller owns and closes the returned resource. @return resource */
    public function readStream(string $key);

    public function write(string $key, string $contents): void;

    /** Reads from the current cursor without closing the input. @param resource $stream */
    public function writeStream(string $key, $stream): int;

    public function delete(string $key): void;

    /** Deletes only the supplied keys, in a provider-supported bounded batch. @param list<string> $keys */
    public function deleteMany(array $keys): void;

    public function copy(string $source, string $destination): void;

    /** @return array{keys:list<string>,next:?string} */
    public function list(string $prefix, ?string $continuation = null, int $limit = 1000): array;
}
