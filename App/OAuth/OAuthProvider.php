<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Http\RedirectResponse;
use App\Http\Request;
use App\Support\SecureRandom;

/** One named provider's browser authorization and single-use OIDC callback flow. */
final class OAuthProvider
{
    public function __construct(
        private OAuthProviderConfig $config,
        private OidcProtocol $protocol,
        private OAuthTransactionStore $transactions
    ) {
    }

    /**
     * Begin Authorization Code + PKCE S256. Only selected provider hints are
     * accepted, so application input cannot replace security-critical fields.
     *
     * @param array<string,string> $parameters
     */
    public function redirect(array $parameters = []): RedirectResponse
    {
        $metadata = $this->protocol->metadata($this->config);
        $authorizationEndpoint = $metadata['authorization_endpoint'] ?? null;
        if (!is_string($authorizationEndpoint)) {
            throw new OAuthException('OIDC authorization endpoint is unavailable.');
        }
        $this->config->validateEndpoint($authorizationEndpoint);
        $state = SecureRandom::token(32);
        $nonce = SecureRandom::token(32);
        $verifier = SecureRandom::token(32); // 43 unpadded base64url characters.
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = [
            'response_type' => 'code',
            'client_id' => $this->config->clientId(),
            'redirect_uri' => $this->config->redirectUri(),
            'scope' => implode(' ', $this->config->scopes()),
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
        foreach ($parameters as $name => $value) {
            if (!in_array($name, ['prompt', 'login_hint'], true) || !is_string($value)
                || $value === '' || strlen($value) > 256
                || preg_match('/[\x00-\x1f\x7f]/', $value)) {
                throw new OAuthException('OIDC authorization parameter is invalid.');
            }
            if ($name === 'prompt' && preg_match('/\A[A-Za-z_]+(?: [A-Za-z_]+)*\z/D', $value) !== 1) {
                throw new OAuthException('OIDC prompt value is invalid.');
            }
            $query[$name] = $value;
        }

        $this->transactions->put($this->config->name(), $state, $verifier, $nonce);
        $separator = str_contains($authorizationEndpoint, '?') ? '&' : '?';
        return new RedirectResponse($authorizationEndpoint . $separator
            . http_build_query($query, '', '&', PHP_QUERY_RFC3986), 302,
            ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    /**
     * Consume the browser-bound transaction before any token exchange. RFC
     * 9207 response issuer is required to reject mix-up before sending a code.
     */
    public function callback(Request $request): ExternalIdentity
    {
        if ($request->method() !== 'GET'
            || (parse_url($request->uri(), PHP_URL_PATH) ?: '/')
                !== (parse_url($this->config->redirectUri(), PHP_URL_PATH) ?: '/')) {
            throw new OAuthException('OIDC callback route is invalid.');
        }
        $values = self::callbackQuery($request->uri());
        $state = $values['state'] ?? null;
        if (!is_string($state) || strlen($state) < 32 || strlen($state) > 512) {
            throw new OAuthException('OIDC callback state is invalid.');
        }
        $transaction = $this->transactions->take($this->config->name(), $state);
        if ($transaction === null) throw new OAuthException('OIDC callback transaction is invalid or expired.');

        // The callback's untrusted issuer never chooses an endpoint. The
        // configured provider and single-use Session transaction do that.
        $issuer = $values['iss'] ?? null;
        if (!is_string($issuer) || !hash_equals($this->config->issuer(), $issuer)) {
            throw new OAuthException('OIDC authorization response issuer is invalid.');
        }
        if (isset($values['error'])) {
            if ($values['error'] === 'access_denied') {
                throw new OAuthCancelledException('External sign-in was cancelled.');
            }
            throw new OAuthException('External identity provider rejected sign-in.');
        }
        $code = $values['code'] ?? null;
        if (!is_string($code) || $code === '' || strlen($code) > 4096
            || preg_match('/[\x00-\x1f\x7f]/', $code)) {
            throw new OAuthException('OIDC authorization code is invalid.');
        }
        $metadata = $this->protocol->metadata($this->config);
        return $this->protocol->exchange($this->config, $metadata, $code,
            $transaction['code_verifier'], $transaction['nonce']);
    }

    /**
     * Request::query() follows PHP's last-value parsing. Parse the raw URI so
     * duplicate state, issuer, or code parameters cannot become ambiguous.
     *
     * @return array<string,string>
     */
    private static function callbackQuery(string $uri): array
    {
        if (strlen($uri) > 12288 || str_contains($uri, '#')) {
            throw new OAuthException('OIDC callback query is invalid.');
        }
        $raw = parse_url($uri, PHP_URL_QUERY);
        if (!is_string($raw) || strlen($raw) > 8192) {
            throw new OAuthException('OIDC callback query is invalid.');
        }
        $pairs = explode('&', $raw);
        if (count($pairs) > 32) throw new OAuthException('OIDC callback query is too large.');
        $values = [];
        foreach ($pairs as $pair) {
            if ($pair === '' || preg_match('/%(?![0-9A-Fa-f]{2})/', $pair)) {
                throw new OAuthException('OIDC callback query is invalid.');
            }
            [$rawName, $rawValue] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($rawName);
            $value = urldecode($rawValue);
            if ($name === '' || strlen($name) > 128 || strlen($value) > 8192
                || preg_match('/[\x00-\x1f\x7f]/', $name . $value) || array_key_exists($name, $values)) {
                throw new OAuthException('OIDC callback query is invalid or duplicated.');
            }
            $values[$name] = $value;
        }
        return $values;
    }
}
