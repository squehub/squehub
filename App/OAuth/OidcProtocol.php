<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Database\ModelClock;
use App\HttpClient\HttpClient;
use JsonException;
use Throwable;

/** OIDC discovery and authorization-code exchange with PKCE. */
final class OidcProtocol
{
    private const MAX_DISCOVERY_BYTES = 32768;
    private const MAX_TOKEN_RESPONSE_BYTES = 65536;

    private IdTokenVerifier $verifier;

    public function __construct(private HttpClient $http, ModelClock $clock)
    {
        $this->verifier = new IdTokenVerifier($http, $clock);
    }

    /** @return array<string,mixed> Validated fields needed by the authorization flow. */
    public function metadata(OAuthProviderConfig $config): array
    {
        $url = rtrim($config->issuer(), '/') . '/.well-known/openid-configuration';
        $config->validateEndpoint($url);
        $document = $this->getJson($url, self::MAX_DISCOVERY_BYTES, 'OIDC discovery failed.');
        return $this->validatedMetadata($config, $document);
    }

    /** @param array<string,mixed> $metadata */
    public function exchange(OAuthProviderConfig $config, array $metadata,
        #[\SensitiveParameter] string $code, #[\SensitiveParameter] string $verifier,
        #[\SensitiveParameter] string $nonce): ExternalIdentity
    {
        $metadata = $this->validatedMetadata($config, $metadata);
        if ($code === '' || strlen($code) > 4096 || preg_match('/[\x00-\x1f\x7f]/', $code) === 1
            || preg_match('/\A[A-Za-z0-9._~-]{43,128}\z/D', $verifier) !== 1 || $nonce === '') {
            throw new OAuthException('OIDC authorization response is invalid.');
        }

        $data = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $config->redirectUri(),
            'code_verifier' => $verifier,
        ];
        $request = $this->http->pending()->acceptJson()->asForm()->followRedirects(0)
            ->retry(1)->maxRequestBytes(8192)->maxResponseBytes(self::MAX_TOKEN_RESPONSE_BYTES);
        $secret = $config->clientSecret();
        if ($secret === null) {
            $data['client_id'] = $config->clientId();
        } else {
            // OAuth client credentials use form encoding before Basic encoding.
            $request = $request->withBasicAuth(rawurlencode($config->clientId()), rawurlencode($secret));
        }
        try { $response = $request->post($metadata['token_endpoint'], $data); }
        catch (Throwable) { throw new OAuthException('OIDC token exchange failed.'); }
        if ($response->status() !== 200 || strlen($response->body()) > self::MAX_TOKEN_RESPONSE_BYTES) {
            throw new OAuthException('OIDC token exchange failed.');
        }
        try { $tokens = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new OAuthException('OIDC token response is invalid.'); }
        if (!is_array($tokens) || !is_string($tokens['id_token'] ?? null)
            || $tokens['id_token'] === '' || strlen($tokens['id_token']) > 32768) {
            throw new OAuthException('OIDC token response is invalid.');
        }

        // Only the verified ID-token claims leave this method. Provider access
        // and refresh tokens are deliberately not represented by ExternalIdentity.
        return $this->verifier->verify($config, $tokens['id_token'], $metadata['jwks_uri'], $nonce);
    }

    /** @return array<string,mixed> */
    private function getJson(string $url, int $limit, string $error): array
    {
        try {
            $response = $this->http->pending()->acceptJson()->followRedirects(0)->retry(1)
                ->maxResponseBytes($limit)->get($url);
        } catch (Throwable) {
            throw new OAuthException($error);
        }
        if ($response->status() !== 200 || strlen($response->body()) > $limit) {
            throw new OAuthException($error);
        }
        try { $document = json_decode($response->body(), true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new OAuthException($error); }
        if (!is_array($document)) throw new OAuthException($error);
        return $document;
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private function validatedMetadata(OAuthProviderConfig $config, array $document): array
    {
        if (($document['issuer'] ?? null) !== $config->issuer()
            || $config->signingAlgorithm() !== 'RS256'
            || !is_array($document['response_types_supported'] ?? null)
            || !in_array('code', $document['response_types_supported'], true)
            || !is_array($document['id_token_signing_alg_values_supported'] ?? null)
            || !in_array('RS256', $document['id_token_signing_alg_values_supported'], true)
            || (isset($document['grant_types_supported'])
                && (!is_array($document['grant_types_supported'])
                    || !in_array('authorization_code', $document['grant_types_supported'], true)))
            || (isset($document['code_challenge_methods_supported'])
                && (!is_array($document['code_challenge_methods_supported'])
                    || !in_array('S256', $document['code_challenge_methods_supported'], true)))) {
            throw new OAuthException('OIDC provider metadata is incompatible.');
        }
        $authMethods = $document['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic'];
        if (!is_array($authMethods)
            || !in_array($config->clientSecret() === null ? 'none' : 'client_secret_basic', $authMethods, true)) {
            throw new OAuthException('OIDC provider authentication method is unsupported.');
        }
        $endpoints = [];
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $field) {
            $value = $document[$field] ?? null;
            if (!is_string($value) || $value === '') {
                throw new OAuthException('OIDC provider metadata is invalid.');
            }
            $config->validateEndpoint($value);
            $endpoints[$field] = $value;
        }
        return [
            'issuer' => $config->issuer(),
            'authorization_endpoint' => $endpoints['authorization_endpoint'],
            'token_endpoint' => $endpoints['token_endpoint'],
            'jwks_uri' => $endpoints['jwks_uri'],
            'response_types_supported' => ['code'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => [
                $config->clientSecret() === null ? 'none' : 'client_secret_basic',
            ],
        ];
    }
}
