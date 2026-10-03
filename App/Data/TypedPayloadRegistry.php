<?php

declare(strict_types=1);

namespace App\Data;

use App\Queue\QueueCodec;
use ReflectionClass;
use Throwable;

/**
 * Application-owned names for selected values crossing a Queue boundary.
 * Stored aliases, never stored class names, select reconstruction code. A
 * worker Application must register the same alias and version as its sender.
 */
final class TypedPayloadRegistry
{
    /** @var array<string, array{version:int,class:class-string<QueuePayloadData>}> */
    private array $aliases = [];

    /** @var array<class-string<QueuePayloadData>, string> */
    private array $classes = [];

    public function define(string $alias, int $version, string $class): void
    {
        if (strlen($alias) > 128 || preg_match('/\A[a-z][a-z0-9._-]*\z/D', $alias) !== 1) {
            throw new TypedPayloadException('Typed payload alias is invalid.');
        }
        if ($version < 1 || $version > 2147483647) {
            throw new TypedPayloadException('Typed payload version is invalid.');
        }
        if (!QueueCodec::validClass($class) || !class_exists($class)
            || !is_subclass_of($class, QueuePayloadData::class)
            || !(new ReflectionClass($class))->isInstantiable()) {
            throw new TypedPayloadException('Typed payload class is ineligible.');
        }
        if (isset($this->aliases[$alias]) || isset($this->classes[$class])) {
            throw new TypedPayloadException('Typed payload registration is duplicated.');
        }
        $this->aliases[$alias] = ['version' => $version, 'class' => $class];
        $this->classes[$class] = $alias;
    }

    /** @return array{type:string,version:int,data:array<string,mixed>} */
    public function encode(QueuePayloadData $value): array
    {
        $class = $value::class;
        $alias = $this->classes[$class] ?? null;
        if ($alias === null) {
            throw new TypedPayloadException('Typed payload class is not registered.');
        }
        try {
            try {
                $data = $value->toQueueData();
            } catch (Throwable) {
                // An application may throw even our exception type with a
                // sensitive value. Only framework-authored text may escape.
                throw new TypedPayloadException('Typed payload encoding failed.');
            }
            self::fields($data);
            $envelope = ['type' => $alias, 'version' => $this->aliases[$alias]['version'],
                'data' => $data];
            // The existing Queue validator rejects nested objects and other
            // values that JSON would otherwise encode through public members.
            QueueCodec::assertData($envelope);
            $json = json_encode($envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > QueueCodec::MAX_PAYLOAD_BYTES) {
                throw new TypedPayloadException('Typed payload exceeds the Queue size limit.');
            }
            return $envelope;
        } catch (TypedPayloadException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Queue validation and JSON encoding must not publish payload data.
            throw new TypedPayloadException('Typed payload encoding failed.');
        }
    }

    /** @param array<string|int, mixed> $envelope */
    public function decode(array $envelope): QueuePayloadData
    {
        $keys = array_keys($envelope);
        sort($keys);
        if ($keys !== ['data', 'type', 'version']
            || !is_string($envelope['type'] ?? null)
            || !is_int($envelope['version'] ?? null)
            || !is_array($envelope['data'] ?? null)) {
            throw new TypedPayloadException('Typed payload envelope is invalid.');
        }
        $alias = $envelope['type'];
        $registered = $this->aliases[$alias] ?? null;
        if ($registered === null) {
            throw new TypedPayloadException('Typed payload type is not registered.');
        }
        if ($envelope['version'] !== $registered['version']) {
            throw new TypedPayloadException('Typed payload version is unsupported.');
        }
        try {
            self::fields($envelope['data']);
            QueueCodec::assertData($envelope);
            $json = json_encode($envelope,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
            if (strlen($json) > QueueCodec::MAX_PAYLOAD_BYTES) {
                throw new TypedPayloadException('Typed payload exceeds the Queue size limit.');
            }
            $class = $registered['class'];
            try {
                $value = $class::fromQueueData($envelope['data']);
            } catch (Throwable) {
                throw new TypedPayloadException('Typed payload reconstruction failed.');
            }
            if ($value::class !== $class) {
                throw new TypedPayloadException('Typed payload reconstruction returned a different type.');
            }
            return $value;
        } catch (TypedPayloadException $exception) {
            throw $exception;
        } catch (Throwable) {
            // Do not attach an application exception carrying persisted data.
            throw new TypedPayloadException('Typed payload reconstruction failed.');
        }
    }

    /** @param array<string|int, mixed> $data */
    private static function fields(array $data): void
    {
        foreach ($data as $field => $_) {
            if (!is_string($field) || $field === '' || strlen($field) > 128
                || preg_match('/[\x00-\x1F\x7F]/', $field) === 1) {
                throw new TypedPayloadException('Typed payload field name is invalid.');
            }
        }
    }
}
