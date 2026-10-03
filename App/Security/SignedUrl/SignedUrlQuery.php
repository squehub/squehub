<?php

declare(strict_types=1);

namespace App\Security\SignedUrl;

use App\Http\Request;
use InvalidArgumentException;

/** A deliberately small RFC 3986 query profile with no PHP array/key aliases. */
final class SignedUrlQuery
{
    public const EXPIRES = 'sqh_expires';
    public const SIGNATURE = 'sqh_signature';
    private const MAX_PAIRS = 64;
    private const MAX_BYTES = 16384;
    private const MAX_VALUE_BYTES = 4096;

    /** @param array<array-key,mixed> $parameters */
    public static function build(array $parameters): string
    {
        if (count($parameters) > self::MAX_PAIRS) {
            throw new InvalidArgumentException('Signed URL query has too many parameters.');
        }
        $normalized = [];
        foreach ($parameters as $key => $value) {
            if (!is_string($key) || !self::validKey($key)
                || $key === self::EXPIRES || $key === self::SIGNATURE
                || (!is_string($value) && !is_int($value))) {
                throw new InvalidArgumentException('Signed URL query parameters must be unique scalar values with safe names.');
            }
            $text = (string) $value;
            if (!self::validValue($text)) {
                throw new InvalidArgumentException('Signed URL query value is invalid.');
            }
            $normalized[$key] = $text;
        }
        ksort($normalized, SORT_STRING);
        $parts = [];
        foreach ($normalized as $key => $value) {
            $parts[] = $key . '=' . rawurlencode($value);
        }
        $query = implode('&', $parts);
        if (strlen($query) > self::MAX_BYTES - 320) {
            throw new InvalidArgumentException('Signed URL query is too long.');
        }
        return $query;
    }

    /** @return array{query:string,expires:string,signature:string}|null */
    public static function parse(Request $request): ?array
    {
        $uri = $request->uri();
        if (str_contains($uri, '#') || strlen($uri) > 49152) return null;
        $raw = parse_url($uri, PHP_URL_QUERY);
        if (!is_string($raw) || $raw === '' || strlen($raw) > self::MAX_BYTES) return null;
        $fields = [];
        foreach (explode('&', $raw) as $pair) {
            if ($pair === '' || !str_contains($pair, '=')) return null;
            [$rawKey, $rawValue] = explode('=', $pair, 2);
            $key = rawurldecode($rawKey);
            $value = rawurldecode($rawValue);
            if (!self::validKey($key) || rawurlencode($key) !== $rawKey
                || !self::validValue($value) || rawurlencode($value) !== $rawValue
                || array_key_exists($key, $fields)) return null;
            $fields[$key] = $value;
        }
        if (count($fields) > self::MAX_PAIRS + 2
            || !isset($fields[self::EXPIRES], $fields[self::SIGNATURE])) return null;

        // PHP has already parsed Request::query(). A disagreement means the
        // controller could act on values other than those authenticated here.
        $snapshot = $request->query();
        if (!is_array($snapshot)) return null;
        ksort($fields, SORT_STRING);
        ksort($snapshot, SORT_STRING);
        if ($fields !== $snapshot) return null;

        $expires = $fields[self::EXPIRES];
        $signature = $fields[self::SIGNATURE];
        unset($fields[self::EXPIRES], $fields[self::SIGNATURE]);
        if (preg_match('/\A[1-9][0-9]{0,11}\z/D', $expires) !== 1
            || $signature === '' || strlen($signature) > 256) return null;
        try { $query = self::build($fields); }
        catch (InvalidArgumentException) { return null; }
        return ['query' => $query, 'expires' => $expires, 'signature' => $signature];
    }

    private static function validKey(string $key): bool
    {
        return preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,63}\z/D', $key) === 1;
    }

    private static function validValue(string $value): bool
    {
        return strlen($value) <= self::MAX_VALUE_BYTES
            && preg_match('//u', $value) === 1
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
    }
}
