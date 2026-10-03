<?php

declare(strict_types=1);

namespace App\Database\Pagination;

use InvalidArgumentException;
use JsonException;

/**
 * Encodes scalar keyset positions without PHP object serialization. The token
 * is opaque API state, not encrypted or an authorization credential.
 * The query fingerprint prevents accidental use with a different query; the
 * public checksum catches altered transport text but is not an authentication
 * signature. Applications must not put sensitive ordering values in cursors.
 * Every decoded value is still passed to SQL through a bound parameter.
 *
 * @internal
 */
final class CursorCodec
{
    private const MAX_TOKEN_BYTES = 8192;
    private const MAX_VALUE_BYTES = 2048;

    /** @param list<int|float|string|bool> $values */
    public static function encode(string $fingerprint, array $values): string
    {
        self::assertValues($values);
        try {
            $json = json_encode(['v' => 1, 'q' => $fingerprint, 'p' => $values], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Cursor position cannot be encoded.', 0, $exception);
        }
        $token = rtrim(strtr(base64_encode($json), '+/', '-_'), '=') . '.' . hash('sha256', $json);
        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw new InvalidArgumentException('Cursor exceeds the supported size.');
        }
        return $token;
    }

    /** @return list<int|float|string|bool> */
    public static function decode(string $token, string $fingerprint, int $terms): array
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES
            || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $token, $matches) !== 1) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        $json = base64_decode(strtr($matches[1], '-_', '+/'), true);
        if ($json === false || rtrim(strtr(base64_encode($json), '+/', '-_'), '=') !== $matches[1]
            || !hash_equals(hash('sha256', $json), $matches[2])) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Invalid cursor.', 0, $exception);
        }
        if (!is_array($payload) || array_keys($payload) !== ['v', 'q', 'p']
            || $payload['v'] !== 1 || !is_string($payload['q'])
            || !hash_equals($fingerprint, $payload['q'])
            || !is_array($payload['p']) || !array_is_list($payload['p'])
            || count($payload['p']) !== $terms) {
            throw new InvalidArgumentException('Cursor does not belong to this query.');
        }
        self::assertValues($payload['p']);
        return $payload['p'];
    }

    /** @param array<mixed> $values */
    private static function assertValues(array $values): void
    {
        foreach ($values as $value) {
            if ((!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value))
                || (is_float($value) && !is_finite($value))
                || (is_string($value) && strlen($value) > self::MAX_VALUE_BYTES)) {
                throw new InvalidArgumentException('Cursor contains an unsupported position.');
            }
        }
    }
}
