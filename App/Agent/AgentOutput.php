<?php

declare(strict_types=1);

namespace App\Agent;

use JsonException;

/**
 * Final bounded output guard. Allowlisted inspectors are the primary privacy
 * boundary; this scrub catches accidentally embedded credentials in labels or
 * excerpts before they reach an MCP client.
 */
final class AgentOutput
{
    private const MAX_BYTES = 262144;

    /** @param array<string,mixed> $value @return array<string,mixed> */
    public static function safe(array $value): array
    {
        $nodes = 0;
        $filtered = self::filter($value, 0, $nodes);
        try {
            $json = json_encode($filtered, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new AgentException('Agent result could not be encoded safely.');
        }
        if (strlen($json) > self::MAX_BYTES) {
            throw new AgentException('Agent result exceeds its size limit.');
        }
        return $filtered;
    }

    private static function filter(mixed $value, int $depth, int &$nodes): mixed
    {
        if (++$nodes > 10000 || $depth > 12) {
            throw new AgentException('Agent result exceeds its structure limit.');
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && preg_match('/\A(?:app_key|password|secret|api_key|access_token|refresh_token|authorization|cookie|session_id|private_key|credential|token)(?:_|\z)/i',
                    $key) === 1) {
                    $result[$key] = '[redacted]';
                    continue;
                }
                $result[$key] = self::filter($item, $depth + 1, $nodes);
            }
            return $result;
        }
        if (is_string($value)) {
            if (strlen($value) > 8192) {
                throw new AgentException('Agent result contains an oversized value.');
            }
            $value = preg_replace('/(?i)\b(?:APP_KEY|[A-Z0-9_]*(?:PASSWORD|SECRET|TOKEN|API_KEY|PRIVATE_KEY))\s*[:=]\s*[^\s,;]+/',
                '[redacted]', $value) ?? '[redacted]';
            return preg_replace('/(?i)\bBearer\s+[A-Za-z0-9._~+\/-]+/',
                'Bearer [redacted]', $value) ?? '[redacted]';
        }
        if (is_null($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }
        throw new AgentException('Agent result contains an unsupported value.');
    }
}
