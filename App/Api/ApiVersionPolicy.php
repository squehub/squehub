<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Repository;
use App\Http\Request;
use App\Http\Response;
use App\Routing\RouteDefinition;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Resolves explicit route version metadata for one matched request.
 * @internal
 */
final class ApiVersionPolicy
{
    private string $strategy;
    private string $header;

    public function __construct(Repository $config)
    {
        $options = $config->get('api.versioning', []);
        if (!is_array($options) || ($options !== [] && array_is_list($options))) {
            throw new InvalidArgumentException('api.versioning must be a named option array.');
        }
        foreach (array_keys($options) as $key) {
            if (!in_array($key, ['strategy', 'header'], true)) {
                throw new InvalidArgumentException('api.versioning contains an unsupported option.');
            }
        }
        $strategy = $options['strategy'] ?? 'uri';
        $header = $options['header'] ?? 'X-API-Version';
        if (!is_string($strategy) || !in_array($strategy, ['uri', 'header'], true)) {
            throw new InvalidArgumentException('api.versioning.strategy must be uri or header.');
        }
        if (!is_string($header) || strlen($header) > 64
            || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $header) !== 1) {
            throw new InvalidArgumentException('api.versioning.header must be a valid bounded HTTP header name.');
        }
        $this->strategy = $strategy;
        $this->header = $header;
    }

    /** A bounded token, deliberately independent of semantic-version syntax. */
    public static function normalizeIdentifier(#[SensitiveParameter] string $version): string
    {
        // HTTP optional whitespace is SP/HTAB. Other controls must fail.
        $version = strtolower(trim($version, " \t"));
        if (strlen($version) > 32 || preg_match('/\A[a-z0-9][a-z0-9_-]*\z/D', $version) !== 1) {
            throw new InvalidArgumentException('API version must be a bounded identifier.');
        }
        return $version;
    }

    /** Called after canonical route matching, before route middleware or controllers. */
    public function resolve(#[SensitiveParameter] Request $request, RouteDefinition $route): void
    {
        $version = $route->apiVersionValue();
        if ($version === null) {
            return;
        }
        if ($this->strategy === 'header') {
            // Even rejected versions depend on this request header for caching.
            $request->setAttribute('_squehub.api_version_vary', true);
            if ($request->headerConflict($this->header)) {
                throw ApiError::make('api_version_conflict', 'Conflicting API versions were requested.', 400);
            }
            $declared = $request->header($this->header);
            if ($declared === null || trim($declared, " \t") === '') {
                throw ApiError::make('api_version_required', 'An API version is required.', 400);
            }
            if (str_contains($declared, ',')) {
                throw ApiError::make('api_version_conflict', 'Conflicting API versions were requested.', 400);
            }
            try {
                $requested = self::normalizeIdentifier($declared);
            } catch (InvalidArgumentException) {
                throw ApiError::make('unsupported_api_version', 'The requested API version is not supported.', 400);
            }
            if ($requested !== $version) {
                throw ApiError::make('unsupported_api_version', 'The requested API version is not supported.', 400);
            }
        }
        $request->setAttribute('_squehub.api_version', $version);
    }

    /** Preserve existing Vary tokens and avoid duplicate header names. */
    public function applyVary(Response $response, Request $request): Response
    {
        if ($request->attribute('_squehub.api_version_vary') !== true) {
            return $response;
        }
        $existing = $response->header('Vary');
        if ($existing === null || trim($existing) === '') {
            return $response->withHeader('Vary', $this->header);
        }
        $tokens = array_values(array_filter(array_map('trim', explode(',', $existing)),
            static fn (string $token): bool => $token !== ''));
        foreach ($tokens as $token) {
            if ($token === '*' || strcasecmp($token, $this->header) === 0) {
                return $response;
            }
        }
        return $response->withHeader('Vary', implode(', ', [...$tokens, $this->header]));
    }
}
