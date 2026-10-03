<?php

declare(strict_types=1);

namespace App\Storage\Providers;

use App\Storage\StorageException;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Optional AWS SDK v3 bridge. This class is constructed only for a selected
 * S3 drive; neither the SDK nor a network connection is needed at app boot.
 */
final class AwsS3ObjectClient implements S3ObjectClient
{
    private object $client;
    private string $bucket;

    /** @param array<string,mixed> $settings */
    public function __construct(array $settings)
    {
        if (!class_exists(\Aws\S3\S3Client::class)
            || !class_exists(\Aws\S3\ObjectUploader::class)
            || !class_exists(\Aws\S3\ObjectCopier::class)) {
            throw new StorageException('S3 Storage requires the optional aws/aws-sdk-php package.');
        }
        $bucket = $settings['bucket'] ?? null;
        $region = $settings['region'] ?? null;
        $endpoint = $settings['endpoint'] ?? null;
        $accessKey = $settings['access_key'] ?? null;
        $secretKey = $settings['secret_key'] ?? null;
        $sessionToken = $settings['session_token'] ?? null;
        $pathStyle = self::boolean($settings['path_style'] ?? false);
        if (!is_string($bucket) || !preg_match('/\A[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]\z/D', $bucket)
            || str_contains($bucket, '..') || str_contains($bucket, '.-') || str_contains($bucket, '-.')) {
            throw new StorageException('Invalid S3 Storage bucket configuration.');
        }
        if (!is_string($region) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9-]{0,63}\z/D', $region)) {
            throw new StorageException('Invalid S3 Storage region configuration.');
        }
        if ($endpoint === '') $endpoint = null;
        if ($endpoint !== null) {
            if (!is_string($endpoint) || strlen($endpoint) > 2048
                || filter_var($endpoint, FILTER_VALIDATE_URL) === false) {
                throw new StorageException('Invalid S3 Storage endpoint configuration.');
            }
            $parts = parse_url($endpoint);
            if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment'])) {
                throw new StorageException('Invalid S3 Storage endpoint configuration.');
            }
        }
        foreach (['access key' => $accessKey, 'secret key' => $secretKey, 'session token' => $sessionToken] as $label => $value) {
            if ($value !== null && (!is_string($value) || strlen($value) > 8192
                || preg_match('/[\x00-\x1F\x7F]/', $value))) {
                throw new StorageException('Invalid S3 Storage ' . $label . ' configuration.');
            }
        }
        if (($accessKey === null || $accessKey === '') !== ($secretKey === null || $secretKey === '')) {
            throw new StorageException('S3 Storage access and secret keys must be configured together.');
        }
        if ($sessionToken !== null && $sessionToken !== '' && ($accessKey === null || $accessKey === '')) {
            throw new StorageException('S3 Storage session token requires explicit credentials.');
        }
        // Bound each remote request and leave durable retry decisions to the
        // calling operation or Queue worker. SDK defaults can retry implicitly
        // and otherwise wait indefinitely on a stalled provider connection.
        $options = ['version' => '2006-03-01', 'region' => $region,
            'use_path_style_endpoint' => $pathStyle,
            'http' => ['connect_timeout' => 5.0, 'timeout' => 30.0],
            'retries' => 0];
        if ($endpoint !== null) $options['endpoint'] = $endpoint;
        if (is_string($accessKey) && $accessKey !== '') {
            $options['credentials'] = ['key' => $accessKey, 'secret' => $secretKey];
            if (is_string($sessionToken) && $sessionToken !== '') $options['credentials']['token'] = $sessionToken;
        }
        try { $this->client = new \Aws\S3\S3Client($options); }
        catch (Throwable) { throw new StorageException('S3 Storage client configuration failed.'); }
        $this->bucket = $bucket;
    }

    /** @return array{size:int,modified:DateTimeImmutable,mime:?string,etag:?string}|null */
    public function head(string $key): ?array
    {
        try {
            $result = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $modified = $result['LastModified'] ?? null;
            $date = $modified instanceof DateTimeInterface
                ? DateTimeImmutable::createFromInterface($modified)
                : (is_string($modified) ? new DateTimeImmutable($modified) : null);
            $size = $result['ContentLength'] ?? null;
            if (!is_int($size) || $size < 0 || $date === null) throw new S3ClientFailure();
            $mime = $result['ContentType'] ?? null;
            $etag = $result['ETag'] ?? null;
            return ['size' => $size,
                'modified' => $date->setTimezone(new DateTimeZone('UTC')),
                'mime' => is_string($mime) ? $mime : null,
                'etag' => is_string($etag) ? trim($etag, '"') : null];
        } catch (Throwable $error) {
            $failure = self::failure($error);
            if ($failure->reason() === S3ClientFailure::NOT_FOUND) return null;
            throw $failure;
        }
    }

    public function read(string $key): string
    {
        try {
            $body = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key])['Body'] ?? null;
            if (!is_object($body) || !method_exists($body, 'getContents')) throw new S3ClientFailure();
            return $body->getContents();
        } catch (Throwable $error) { throw self::failure($error); }
    }

    public function readStream(string $key)
    {
        try {
            $body = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $key,
                '@http' => ['stream' => true]])['Body'] ?? null;
            if (!is_object($body) || !method_exists($body, 'detach')) throw new S3ClientFailure();
            $stream = $body->detach();
            if (!is_resource($stream) || get_resource_type($stream) !== 'stream') throw new S3ClientFailure();
            return $stream;
        } catch (Throwable $error) { throw self::failure($error); }
    }

    public function write(string $key, string $contents): void
    {
        try { $this->client->putObject(['Bucket' => $this->bucket, 'Key' => $key, 'Body' => $contents]); }
        catch (Throwable $error) { throw self::failure($error); }
    }

    public function writeStream(string $key, $stream): int
    {
        // The SDK may seek or take ownership of an input stream. Spool from the
        // caller's cursor in bounded memory so those behaviors cannot change
        // the caller's resource or accidentally include earlier bytes.
        $temporary = @fopen('php://temp/maxmemory:2097152', 'w+b');
        if ($temporary === false) throw new S3ClientFailure();
        $bytes = 0;
        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || ($chunk === '' && !feof($stream))) throw new S3ClientFailure();
                $length = strlen($chunk);
                for ($offset = 0; $offset < $length;) {
                    $written = fwrite($temporary, substr($chunk, $offset));
                    if ($written === false || $written === 0) throw new S3ClientFailure();
                    $offset += $written;
                }
                $bytes += $length;
            }
            if (!rewind($temporary)) throw new S3ClientFailure();
            $clearAcl = static function ($command): void { unset($command['ACL']); };
            $uploader = new \Aws\S3\ObjectUploader($this->client, $this->bucket, $key, $temporary, null,
                ['before_upload' => $clearAcl, 'before_initiate' => $clearAcl]);
            $uploader->upload();
            return $bytes;
        } catch (\Aws\Exception\MultipartUploadException $error) {
            $this->abortMultipart($error, $key);
            throw self::failure($error);
        } catch (Throwable $error) { throw self::failure($error); }
        finally { if (is_resource($temporary)) fclose($temporary); }
    }

    public function delete(string $key): void
    {
        try { $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]); }
        catch (Throwable $error) { throw self::failure($error); }
    }

    public function deleteMany(array $keys): void
    {
        if ($keys === [] || count($keys) > 1000) throw new S3ClientFailure();
        try {
            $objects = array_map(static fn (string $key): array => ['Key' => $key], $keys);
            $result = $this->client->deleteObjects(['Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects, 'Quiet' => true]]);
            if (($result['Errors'] ?? []) !== []) throw new S3ClientFailure();
        } catch (Throwable $error) { throw self::failure($error); }
    }

    public function copy(string $source, string $destination): void
    {
        try {
            // ObjectCopier chooses multipart copy above S3's 5 GiB CopyObject
            // ceiling. null ACL means the bucket's own policy remains in force.
            $copier = new \Aws\S3\ObjectCopier($this->client,
                ['Bucket' => $this->bucket, 'Key' => $source],
                ['Bucket' => $this->bucket, 'Key' => $destination], null);
            $copier->copy();
        } catch (\Aws\Exception\MultipartUploadException $error) {
            $this->abortMultipart($error, $destination);
            throw self::failure($error);
        } catch (Throwable $error) { throw self::failure($error); }
    }

    /** @return array{keys:list<string>,next:?string} */
    public function list(string $prefix, ?string $continuation = null, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 1000) throw new S3ClientFailure();
        try {
            $request = ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'MaxKeys' => $limit];
            if ($continuation !== null) $request['ContinuationToken'] = $continuation;
            $result = $this->client->listObjectsV2($request);
            $contents = $result['Contents'] ?? [];
            if (!is_array($contents)) throw new S3ClientFailure();
            $keys = [];
            foreach ($contents as $object) {
                if (!is_array($object) || !is_string($object['Key'] ?? null)) throw new S3ClientFailure();
                $keys[] = $object['Key'];
            }
            $truncated = $result['IsTruncated'] ?? false;
            if (!is_bool($truncated)) throw new S3ClientFailure();
            $next = $truncated ? ($result['NextContinuationToken'] ?? null) : null;
            if ($truncated && (!is_string($next) || $next === '')) throw new S3ClientFailure();
            return ['keys' => $keys, 'next' => $next];
        } catch (Throwable $error) { throw self::failure($error); }
    }

    private static function boolean(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        if (is_string($value) || is_int($value)) {
            $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($parsed !== null) return $parsed;
        }
        throw new StorageException('Invalid S3 Storage path-style configuration.');
    }

    private function abortMultipart(\Aws\Exception\MultipartUploadException $error, string $key): void
    {
        // A failed multipart transfer can leave billable parts behind. Only
        // abort an upload whose SDK state names this exact destination.
        try {
            $id = $error->getState()->getId();
            if (is_array($id) && ($id['Bucket'] ?? null) === $this->bucket
                && ($id['Key'] ?? null) === $key
                && is_string($id['UploadId'] ?? null) && $id['UploadId'] !== '') {
                $this->client->abortMultipartUpload($id);
            }
        } catch (Throwable) {
            // Preserve the original transfer failure without exposing SDK data.
        }
    }

    private static function failure(Throwable $error): S3ClientFailure
    {
        if ($error instanceof S3ClientFailure) return $error;
        if ($error instanceof \Aws\Exception\AwsException) {
            $status = $error->getStatusCode();
            $code = $error->getAwsErrorCode();
            if (in_array($code, ['NoSuchBucket', 'InvalidBucketName', 'AuthorizationHeaderMalformed'], true)) {
                return new S3ClientFailure(S3ClientFailure::CONFIGURATION_FAILURE);
            }
            if ($status === 404 || in_array($code, ['NoSuchKey', 'NotFound'], true)) {
                return new S3ClientFailure(S3ClientFailure::NOT_FOUND);
            }
            if ($status === 401 || $status === 403 || in_array($code, ['AccessDenied', 'InvalidAccessKeyId'], true)) {
                return new S3ClientFailure(S3ClientFailure::PERMISSION_DENIED);
            }
        }
        return new S3ClientFailure();
    }
}
