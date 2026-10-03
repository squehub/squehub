<?php

declare(strict_types=1);

namespace App\Logging;

use App\Support\SecretRedactor;
use App\Support\SensitiveKey;
use Throwable;

/**
 * Converts arbitrary developer context into bounded, non-traversing data.
 *
 * Unknown objects are represented by class alone. In particular, Models and
 * sessions are never asked to serialize themselves or reveal hidden fields.
 */
final class LogContextNormalizer
{
    private const MAX_DEPTH = 6;
    private const MAX_ITEMS = 100;
    private const MAX_STRING_BYTES = 4096;

    public function __construct(private SecretRedactor $redactor)
    {
    }

    /** @param array<string|int, mixed> $context */
    public function message(string $message, array $context): string
    {
        $secrets = [];
        $this->collectSensitive($context, $secrets, 0);
        return $this->redactor->redact($message, null, $secrets);
    }

    /** @param array<string|int, mixed> $context @return array<string|int, mixed> */
    public function normalize(array $context): array
    {
        $secrets = [];
        $this->collectSensitive($context, $secrets, 0);
        return $this->normalizeArray($context, $secrets, 0);
    }

    private function collectSensitive(array $items, array &$secrets, int $depth): void
    {
        if ($depth >= self::MAX_DEPTH) return;
        $count = 0;
        foreach ($items as $key => $value) {
            if (++$count > self::MAX_ITEMS) break;
            if (SensitiveKey::matches((string) $key) && is_string($value)) {
                $secrets[] = $value;
            } elseif (is_array($value)) {
                $this->collectSensitive($value, $secrets, $depth + 1);
            }
        }
    }

    /** @return array<string|int, mixed> */
    private function normalizeArray(array $items, array $secrets, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) return ['_truncated' => '[depth limit]'];
        $normalized = [];
        $count = 0;
        foreach ($items as $key => $value) {
            if (++$count > self::MAX_ITEMS) {
                $normalized['_truncated'] = '[item limit]';
                break;
            }
            $normalized[$key] = SensitiveKey::matches((string) $key)
                ? '[REDACTED]' : $this->value($value, $secrets, $depth + 1);
        }
        return $normalized;
    }

    private function value(mixed $value, array $secrets, int $depth): mixed
    {
        if (is_array($value)) return $this->normalizeArray($value, $secrets, $depth);
        if ($value instanceof Throwable) {
            // Trace arguments and raw exception messages can contain submitted
            // credentials. Class and source location retain useful diagnostics.
            return ['class' => $value::class, 'code' => $value->getCode(),
                'file' => $value->getFile(), 'line' => $value->getLine()];
        }
        if (is_object($value)) return ['class' => $value::class];
        if (is_resource($value)) return '[resource]';
        if (is_float($value) && !is_finite($value)) return '[non-finite number]';
        if (is_string($value)) {
            $text = strlen($value) > self::MAX_STRING_BYTES
                ? substr($value, 0, self::MAX_STRING_BYTES) . '[truncated]' : $value;
            return $this->redactor->redact($text, null, $secrets);
        }
        return $value;
    }
}
