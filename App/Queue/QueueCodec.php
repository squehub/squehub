<?php

declare(strict_types=1);

namespace App\Queue;

use App\Observability\CorrelationContext;
use JsonException;
use Throwable;

/**
 * Converts explicit job data to a bounded, versioned JSON envelope.
 * Reconstruction invokes only the declared QueueJob contract; it never
 * deserializes arbitrary PHP objects or includes payload values in errors.
 */
final class QueueCodec
{
    public const MAX_PAYLOAD_BYTES = 60000;

    /** Share the Queue's JSON-safe value policy with explicit typed envelopes. */
    public static function assertData(mixed $value): void
    {
        self::validate($value, 0);
    }

    public static function encode(QueueJob $job, ?string $correlationId = null): string
    {
        try {
            $data = $job->toQueuePayload();
            self::validate($data, 0);
            if (!self::validClass($job::class)) {
                throw new QueueException('Queue job class cannot be persisted.');
            }
            if ($correlationId !== null && !CorrelationContext::valid($correlationId)) {
                throw new QueueException('Queue correlation metadata is invalid.');
            }
            $envelope = ['version' => 1, 'job' => $job::class, 'data' => $data];
            if ($correlationId !== null) {
                // Correlation is transport metadata, never application job data.
                $envelope['meta'] = ['correlation_id' => $correlationId];
            }
            $json = json_encode($envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > self::MAX_PAYLOAD_BYTES) throw new QueueException('Queue payload exceeds the supported size.');
            return $json;
        } catch (QueueException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new QueueException('Unable to encode queue payload.', 0, $exception);
        }
    }

    public static function decode(string $json): QueueJob
    {
        return self::decodeWithMetadata($json)['job'];
    }

    /** @return array{job:QueueJob,correlation_id:?string} */
    public static function decodeWithMetadata(string $json): array
    {
        try {
            $envelope = self::envelope($json);
            $class = $envelope['job'];
            if (!self::validClass($class)
                || !class_exists($class) || !is_subclass_of($class, QueueJob::class)) {
                throw new QueueException('Queue job class is unavailable or invalid.');
            }
            self::validate($envelope['data'], 0);
            $job = $class::fromQueuePayload($envelope['data']);
            if (!$job instanceof QueueJob) throw new QueueException('Queue job reconstruction is invalid.');
            return ['job' => $job, 'correlation_id' => $envelope['meta']['correlation_id'] ?? null];
        } catch (QueueException $exception) {
            throw $exception;
        } catch (JsonException $exception) {
            throw new QueueException('Queue payload JSON is invalid.', 0, $exception);
        } catch (Throwable $exception) {
            // Never include application payload values in worker output.
            throw new QueueException('Queue job reconstruction failed.', 0, $exception);
        }
    }

    /** Inspect metadata before starting a worker observation, without running job code. */
    public static function correlationId(string $json): ?string
    {
        try { return self::envelope($json)['meta']['correlation_id'] ?? null; }
        catch (JsonException $exception) {
            throw new QueueException('Queue payload JSON is invalid.', 0, $exception);
        }
    }

    /** @return array<string,mixed> */
    private static function envelope(string $json): array
    {
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) throw new QueueException('Queue payload exceeds the supported size.');
        $envelope = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($envelope) || ($envelope['version'] ?? null) !== 1
            || !is_string($envelope['job'] ?? null) || !is_array($envelope['data'] ?? null)) {
            throw new QueueException('Queue payload envelope is invalid.');
        }
        if (array_key_exists('meta', $envelope)) {
            $meta = $envelope['meta'];
            if (!is_array($meta) || array_keys($meta) !== ['correlation_id']
                || !CorrelationContext::valid($meta['correlation_id'] ?? null)) {
                throw new QueueException('Queue correlation metadata is invalid.');
            }
        }
        return $envelope;
    }

    /** @param mixed $value */
    private static function validate(mixed $value, int $depth): void
    {
        if ($depth > 24) throw new QueueException('Queue payload nesting is too deep.');
        if (is_array($value)) {
            foreach ($value as $item) {
                self::validate($item, $depth + 1);
            }
            return;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_string($value)
            || (is_float($value) && is_finite($value))) return;
        throw new QueueException('Queue payload contains an unsupported value.');
    }

    public static function validClass(string $class): bool
    {
        return strlen($class) <= 255
            && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/D', $class) === 1;
    }
}
