<?php

declare(strict_types=1);

namespace App\OAuth;

/** Validated application-owned OIDC provider settings and outbound endpoint policy. */
final class OAuthProviderConfig
{
    private string $issuer;
    private string $clientId;
    private ?string $clientSecret;
    private string $redirectUri;
    /** @var list<string> */
    private array $scopes;
    /** @var list<string> */
    private array $trustedHosts;
    private bool $allowLocalHttp;
    private int $clockSkew;

    /** @param array<string,mixed> $settings */
    public function __construct(private string $name, array $settings)
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $name) !== 1) {
            throw new OAuthException('OIDC provider name is invalid.');
        }
        $this->allowLocalHttp = $settings['allow_local_http'] ?? false;
        if (!is_bool($this->allowLocalHttp)) throw new OAuthException('OIDC local HTTP policy is invalid.');

        $issuer = $settings['issuer'] ?? null;
        $clientId = $settings['client_id'] ?? null;
        $clientSecret = $settings['client_secret'] ?? null;
        $redirectUri = $settings['redirect_uri'] ?? null;
        if (!is_string($issuer) || !is_string($clientId) || !is_string($redirectUri)
            || $clientId === '' || strlen($clientId) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $clientId)
            || ($clientSecret !== null && (!is_string($clientSecret) || $clientSecret === ''))) {
            throw new OAuthException('OIDC provider credentials or redirect URI are invalid.');
        }
        $this->issuer = $issuer;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;

        // Only configured hosts may receive discovery, token, or signing-key requests.
        // This is an application trust boundary, not a DNS-level SSRF guarantee.
        $issuerHost = $this->urlHost($issuer, true);
        $hosts = $settings['trusted_hosts'] ?? [$issuerHost];
        if (!is_array($hosts) || !array_is_list($hosts) || $hosts === [] || count($hosts) > 16) {
            throw new OAuthException('OIDC trusted hosts are invalid.');
        }
        $trusted = [];
        foreach ($hosts as $host) {
            if (!is_string($host) || strlen($host) > 253
                || preg_match('/\A[A-Za-z0-9][A-Za-z0-9.:-]*\z/D', $host) !== 1) {
                throw new OAuthException('OIDC trusted host is invalid.');
            }
            $trusted[] = strtolower($host);
        }
        if (!in_array($issuerHost, $trusted, true)) {
            throw new OAuthException('OIDC issuer host must be trusted.');
        }
        $this->trustedHosts = array_values(array_unique($trusted));
        // The application's callback origin is usually different from the
        // identity provider's trusted outbound origins.
        $this->urlHost($redirectUri, false);
        if (parse_url($redirectUri, PHP_URL_QUERY) !== null) {
            throw new OAuthException('OIDC redirect URI must not contain a query.');
        }

        $scopes = $settings['scopes'] ?? ['openid'];
        if (!is_array($scopes) || !array_is_list($scopes) || $scopes === [] || count($scopes) > 16) {
            throw new OAuthException('OIDC scopes are invalid.');
        }
        $this->scopes = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}\z/D', $scope) !== 1
                || in_array($scope, $this->scopes, true)) {
                throw new OAuthException('OIDC scope is invalid or duplicated.');
            }
            $this->scopes[] = $scope;
        }
        if (!in_array('openid', $this->scopes, true)) throw new OAuthException('OIDC requires the openid scope.');
        if (($settings['signing_algorithm'] ?? 'RS256') !== 'RS256') {
            throw new OAuthException('Unsupported ID Token signing algorithm.');
        }
        $skew = $settings['clock_skew'] ?? 60;
        if (!is_int($skew) || $skew < 0 || $skew > 300) {
            throw new OAuthException('OIDC clock skew must be between 0 and 300 seconds.');
        }
        $this->clockSkew = $skew;
    }

    public function name(): string { return $this->name; }
    public function issuer(): string { return $this->issuer; }
    public function clientId(): string { return $this->clientId; }
    public function clientSecret(): ?string { return $this->clientSecret; }
    public function redirectUri(): string { return $this->redirectUri; }
    /** @return list<string> */
    public function scopes(): array { return $this->scopes; }
    public function signingAlgorithm(): string { return 'RS256'; }
    public function clockSkew(): int { return $this->clockSkew; }

    /** Reject unsafe schemes, userinfo, IP literals, and untrusted discovered hosts. */
    public function validateEndpoint(string $url): void
    {
        $host = $this->urlHost($url, false);
        if (!in_array($host, $this->trustedHosts, true)) {
            throw new OAuthException('OIDC endpoint host is not trusted.');
        }
    }

    private function urlHost(string $url, bool $issuer): string
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            throw new OAuthException('OIDC URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass']) || isset($parts['user'])
            || isset($parts['fragment']) || ($issuer && isset($parts['query']))) {
            throw new OAuthException('OIDC URL is invalid.');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopback = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $this->allowLocalHttp && $loopback)) {
            throw new OAuthException('OIDC endpoints require HTTPS.');
        }
        if (!$loopback && (filter_var($host, FILTER_VALIDATE_IP) !== false
            || $host === 'localhost' || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local'))) {
            throw new OAuthException('OIDC endpoint host is not allowed.');
        }
        if ($loopback && !$this->allowLocalHttp) {
            throw new OAuthException('OIDC loopback endpoints require explicit local policy.');
        }
        return $host;
    }
}
