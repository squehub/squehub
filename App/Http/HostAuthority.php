<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Validates an HTTP authority for both direct Host and trusted forwarded Host.
 * Routing compares normalized hostnames without ports; other consumers may
 * inspect an explicitly supplied port only after the same validation passes.
 * @internal
 */
final class HostAuthority
{
    public static function normalize(string $host, bool $allowPort = true): ?string
    {
        if ($host === '' || strlen($host) > 261
            || preg_match('/[\x00-\x20\x7F\/@?#,\\\\]/', $host) === 1) {
            return null;
        }
        if (preg_match('/\A\[([^\]]+)\](?::([0-9]{1,5}))?\z/D', $host, $match) === 1) {
            if ((!$allowPort && isset($match[2]))
                || !filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                || (isset($match[2]) && ((int) $match[2] < 1 || (int) $match[2] > 65535))) {
                return null;
            }
            $packed = inet_pton($match[1]);
            return $packed === false ? null : '[' . inet_ntop($packed) . ']';
        }
        if ($allowPort && preg_match('/\A([^:]+):([0-9]{1,5})\z/D', $host, $match) === 1) {
            if ((int) $match[2] < 1 || (int) $match[2] > 65535) {
                return null;
            }
            $host = $match[1];
        }
        if (str_contains($host, ':') || strlen($host) > 253) {
            return null;
        }
        $labels = explode('.', strtolower($host));
        foreach ($labels as $label) {
            if (strlen($label) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/D', $label) !== 1) {
                return null;
            }
        }
        if (count($labels) === 4 && count(array_filter($labels, ctype_digit(...))) === 4
            && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        return strtolower($host);
    }

    /** An absent or invalid explicit port is distinct from a scheme default. */
    public static function port(string $host): ?int
    {
        if (self::normalize($host) === null) {
            return null;
        }
        if (preg_match('/\A\[[^\]]+\]:(\d{1,5})\z/D', $host, $match) === 1
            || preg_match('/\A[^:]+:(\d{1,5})\z/D', $host, $match) === 1) {
            return (int) $match[1];
        }
        return null;
    }
}
