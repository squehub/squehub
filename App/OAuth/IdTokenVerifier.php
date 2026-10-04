<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Database\ModelClock;
use App\HttpClient\HttpClient;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use JsonException;
use stdClass;
use Throwable;

/** Validates an OIDC ID token against keys from the configured issuer. */
final class IdTokenVerifier
{
    private const MAX_JWT_BYTES = 32768;
    private const MAX_JWKS_BYTES = 65536;
    private const MAX_KEYS = 32;
    private const CACHE_SECONDS = 300;

    /** @var array<string,array{keys:array<string,Key>,expiresAt:int}> */
    private array $cache = [];

    public function __construct(private HttpClient $http, private ModelClock $clock) {}

    public function verify(OAuthProviderConfig $config, #[\SensitiveParameter] string $jwt,
        string $jwksUri, #[\SensitiveParameter] string $nonce): ExternalIdentity
    {
        if ($config->signingAlgorithm() !== 'RS256' || $nonce === '') {
            throw new OAuthException('ID token verification is not configured.');
        }
        $config->validateEndpoint($jwksUri);
        $kid = self::keyId($jwt);
        [$keys, $cached] = $this->keys($config, $jwksUri);

        if (!isset($keys[$kid]) && $cached) {
            [$keys] = $this->keys($config, $jwksUri, true);
            $cached = false;
        }
        if (!isset($keys[$kid])) throw new OAuthException('ID token signing key is unavailable.');

        try {
            $payload = $this->decode($jwt, $keys[$kid], $config->clockSkew());
        } catch (SignatureInvalidException) {
            // A provider may rotate material while retaining a key ID. Retry
            // against one fresh JWKS only when the first set came from cache.
            if (!$cached) throw new OAuthException('ID token signature is invalid.');
            [$keys] = $this->keys($config, $jwksUri, true);
            if (!isset($keys[$kid])) throw new OAuthException('ID token signing key is unavailable.');
            try { $payload = $this->decode($jwt, $keys[$kid], $config->clockSkew()); }
            catch (Throwable) { throw new OAuthException('ID token verification failed.'); }
        } catch (Throwable) {
            throw new OAuthException('ID token verification failed.');
        }

        $claims = self::claimArray($payload);
        $this->validateClaims($config, $claims, $nonce);

        return new ExternalIdentity($config->name(), $config->issuer(), $claims['sub'], $claims);
    }

    /** Read only the key selector and algorithm; this header is not trusted. */
    private static function keyId(#[\SensitiveParameter] string $jwt): string
    {
        if ($jwt === '' || strlen($jwt) > self::MAX_JWT_BYTES) {
            throw new OAuthException('ID token is invalid.');
        }
        $segments = explode('.', $jwt);
        if (count($segments) !== 3 || strlen($segments[0]) > 4096
            || preg_match('/\A[A-Za-z0-9_-]+\z/D', $segments[0]) !== 1) {
            throw new OAuthException('ID token is invalid.');
        }
        try {
            $header = json_decode(self::base64Url($segments[0]), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OAuthException('ID token header is invalid.');
        }
        if (!is_array($header) || ($header['alg'] ?? null) !== 'RS256'
            || !is_string($header['kid'] ?? null) || $header['kid'] === ''
            || strlen($header['kid']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $header['kid']) === 1
            || isset($header['crit']) || isset($header['jku']) || isset($header['jwk'])
            || isset($header['x5u']) || isset($header['x5c'])) {
            throw new OAuthException('ID token header is invalid.');
        }
        return $header['kid'];
    }

    private static function base64Url(string $encoded): string
    {
        if (strlen($encoded) % 4 === 1) throw new OAuthException('ID token header is invalid.');
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($decoded === false) throw new OAuthException('ID token header is invalid.');
        return $decoded;
    }

    /** @return array{0:array<string,Key>,1:bool} */
    private function keys(OAuthProviderConfig $config, string $uri, bool $refresh = false): array
    {
        $now = $this->clock->now()->getTimestamp();
        // A shared JWKS URI does not make two configured providers the same
        // trust domain. Keep their cached key sets separate.
        $cacheKey = hash('sha256', $config->name() . "\0" . $config->issuer()
            . "\0" . $config->clientId() . "\0" . $uri);
        if (!$refresh && isset($this->cache[$cacheKey]) && $this->cache[$cacheKey]['expiresAt'] > $now) {
            return [$this->cache[$cacheKey]['keys'], true];
        }
        try {
            $response = $this->http->pending()->acceptJson()->followRedirects(0)->retry(1)
                ->maxResponseBytes(self::MAX_JWKS_BYTES)->get($uri);
        } catch (Throwable) {
            throw new OAuthException('OIDC signing keys could not be loaded.');
        }
        if ($response->status() !== 200 || strlen($response->body()) > self::MAX_JWKS_BYTES) {
            throw new OAuthException('OIDC signing keys could not be loaded.');
        }
        try { $document = json_decode($response->body(), true, 16, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new OAuthException('OIDC signing keys are invalid.'); }
        if (!is_array($document) || !is_array($document['keys'] ?? null)
            || !array_is_list($document['keys']) || count($document['keys']) > self::MAX_KEYS) {
            throw new OAuthException('OIDC signing keys are invalid.');
        }

        $keys = [];
        $seen = [];
        foreach ($document['keys'] as $item) {
            if (!is_array($item) || !is_string($item['kid'] ?? null) || $item['kid'] === ''
                || strlen($item['kid']) > 128 || preg_match('/[\x00-\x1f\x7f]/', $item['kid']) === 1) {
                throw new OAuthException('OIDC signing keys are invalid.');
            }
            $kid = $item['kid'];
            if (isset($seen[$kid])) throw new OAuthException('OIDC signing keys are ambiguous.');
            $seen[$kid] = true;
            if (($item['kty'] ?? null) !== 'RSA') continue;
            if (isset($item['alg']) && $item['alg'] !== 'RS256') continue;
            if (isset($item['use']) && $item['use'] !== 'sig') continue;
            if (isset($item['key_ops']) && (!is_array($item['key_ops'])
                || !in_array('verify', $item['key_ops'], true))) continue;
            foreach (['d', 'p', 'q', 'dp', 'dq', 'qi', 'oth'] as $privatePart) {
                if (array_key_exists($privatePart, $item)) {
                    throw new OAuthException('OIDC signing keys are invalid.');
                }
            }
            if (!is_string($item['n'] ?? null) || !is_string($item['e'] ?? null)
                || strlen($item['n']) > 8192 || strlen($item['e']) > 32
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $item['n']) !== 1
                || preg_match('/\A[A-Za-z0-9_-]+\z/D', $item['e']) !== 1) {
                throw new OAuthException('OIDC signing keys are invalid.');
            }
            try { $key = JWK::parseKey($item, 'RS256'); }
            catch (Throwable) { throw new OAuthException('OIDC signing keys are invalid.'); }
            if (!$key instanceof Key) throw new OAuthException('OIDC signing keys are invalid.');
            $keys[$kid] = $key;
        }
        if ($keys === []) throw new OAuthException('OIDC signing keys are unavailable.');
        if (count($this->cache) >= 16 && !isset($this->cache[$cacheKey])) array_shift($this->cache);
        $this->cache[$cacheKey] = ['keys' => $keys, 'expiresAt' => $now + self::CACHE_SECONDS];
        return [$keys, false];
    }

    private function decode(#[\SensitiveParameter] string $jwt, Key $key, int $skew): stdClass
    {
        if ($skew < 0 || $skew > 300) throw new OAuthException('OIDC clock skew is invalid.');
        $previousTime = JWT::$timestamp;
        $previousLeeway = JWT::$leeway;
        try {
            JWT::$timestamp = $this->clock->now()->getTimestamp();
            JWT::$leeway = $skew;
            return JWT::decode($jwt, $key);
        } finally {
            JWT::$timestamp = $previousTime;
            JWT::$leeway = $previousLeeway;
        }
    }

    /** @return array<string,mixed> */
    private static function claimArray(stdClass $payload): array
    {
        $value = self::jsonValue($payload, 0);
        if (!is_array($value)) throw new OAuthException('ID token claims are invalid.');
        return $value;
    }

    private static function jsonValue(mixed $value, int $depth): mixed
    {
        if ($depth > 32) throw new OAuthException('ID token claims are invalid.');
        if ($value instanceof stdClass) $value = get_object_vars($value);
        if (!is_array($value)) {
            if (is_scalar($value) || $value === null) return $value;
            throw new OAuthException('ID token claims are invalid.');
        }
        $copy = [];
        foreach ($value as $key => $item) $copy[$key] = self::jsonValue($item, $depth + 1);
        return $copy;
    }

    /** @param array<string,mixed> $claims */
    private function validateClaims(OAuthProviderConfig $config, array $claims, string $nonce): void
    {
        $clientId = $config->clientId();
        $audience = $claims['aud'] ?? null;
        if (is_string($audience)) $audience = [$audience];
        if (($claims['iss'] ?? null) !== $config->issuer()
            || !is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || strlen($claims['sub']) > 255 || preg_match('/[\x00-\x1f\x7f]/', $claims['sub']) === 1
            || !is_array($audience) || !array_is_list($audience) || $audience === []
            || !in_array($clientId, $audience, true)
            || (count($audience) > 1 && ($claims['azp'] ?? null) !== $clientId)
            || (isset($claims['azp']) && $claims['azp'] !== $clientId)
            || !is_string($claims['nonce'] ?? null)
            || !hash_equals($nonce, $claims['nonce'])) {
            throw new OAuthException('ID token claims are invalid.');
        }
        foreach ($audience as $entry) {
            if (!is_string($entry) || $entry === '') throw new OAuthException('ID token audience is invalid.');
        }
        if (count($audience) !== count(array_unique($audience, SORT_STRING))) {
            throw new OAuthException('ID token audience is invalid.');
        }
        $now = $this->clock->now()->getTimestamp();
        $skew = $config->clockSkew();
        if (!is_int($claims['exp'] ?? null) || !is_int($claims['iat'] ?? null)
            || $claims['exp'] <= $now - $skew || $claims['iat'] > $now + $skew
            || $claims['exp'] <= $claims['iat']
            || (array_key_exists('nbf', $claims) && (!is_int($claims['nbf'])
                || $claims['nbf'] > $now + $skew))) {
            throw new OAuthException('ID token timing is invalid.');
        }
    }
}
