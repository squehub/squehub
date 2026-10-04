<?php

declare(strict_types=1);

namespace App\Idempotency;

use JsonException;

/** @internal Shared bounded record validation for file and Redis stores. */
final class Record
{
    public static function fresh(string $fingerprint, string $owner, int $now,
        int $leaseSeconds, int $retentionSeconds): array
    {
        self::identity($fingerprint, $owner);
        if ($now < 1 || $leaseSeconds < 1 || $retentionSeconds <= $leaseSeconds
            || $retentionSeconds > 604800 || $now > PHP_INT_MAX - $retentionSeconds) {
            throw new IdempotencyException('Idempotency lease or retention is invalid.');
        }
        return ['version' => 1, 'fingerprint' => $fingerprint, 'state' => 'processing',
            'owner' => $owner, 'lease_until' => $now + $leaseSeconds,
            'expires_at' => $now + $retentionSeconds, 'snapshot' => null];
    }

    public static function identity(string $fingerprint, string $owner): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $fingerprint) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $owner) !== 1) {
            throw new IdempotencyException('Idempotency identity is invalid.');
        }
    }

    public static function scope(string $scope): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $scope) !== 1) {
            throw new IdempotencyException('Idempotency scope is invalid.');
        }
    }

    public static function encode(array $record): string
    {
        self::validate($record);
        try {
            $json = json_encode($record, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new IdempotencyException('Idempotency record cannot be encoded.', 0, $failure);
        }
        if (strlen($json) > 48000) throw new IdempotencyException('Idempotency record is too large.');
        return $json;
    }

    public static function decode(string $json): array
    {
        if (strlen($json) > 48000) throw new IdempotencyException('Idempotency record is corrupt.');
        try {
            $record = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $failure) {
            throw new IdempotencyException('Idempotency record is corrupt.', 0, $failure);
        }
        self::validate($record);
        return $record;
    }

    private static function validate(mixed $record): void
    {
        if (!is_array($record) || array_diff(array_keys($record), [
            'version', 'fingerprint', 'state', 'owner', 'lease_until', 'expires_at', 'snapshot']) !== []
            || count($record) !== 7
            || $record['version'] !== 1 || !is_string($record['fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $record['fingerprint']) !== 1
            || !in_array($record['state'], ['processing', 'complete'], true)
            || !is_int($record['lease_until']) || !is_int($record['expires_at'])
            || $record['expires_at'] < 1 || $record['lease_until'] < 0
            || ($record['owner'] !== null && (!is_string($record['owner'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $record['owner']) !== 1))) {
            throw new IdempotencyException('Idempotency record is corrupt.');
        }
        if ($record['state'] === 'processing' && ($record['owner'] === null
            || $record['lease_until'] < 1 || $record['snapshot'] !== null)) {
            throw new IdempotencyException('Idempotency record is corrupt.');
        }
        if ($record['state'] === 'complete' && ($record['owner'] !== null
            || $record['lease_until'] !== 0)) {
            throw new IdempotencyException('Idempotency record is corrupt.');
        }
        if ($record['snapshot'] !== null) ResponseSnapshot::fromArray($record['snapshot']);
    }
}
