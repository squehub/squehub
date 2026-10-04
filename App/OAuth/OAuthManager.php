<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Config\Repository;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\HttpClient\HttpClient;
use App\Session\SessionManager;

/** Resolves only application-configured OIDC clients; no provider is discovered by name. */
final class OAuthManager
{
    private ModelClock $clock;
    private OidcProtocol $protocol;

    public function __construct(
        private Repository $config,
        private HttpClient $http,
        private SessionManager $sessions,
        ?ModelClock $clock = null
    ) {
        $this->clock = $clock ?? new SystemModelClock();
        $this->protocol = new OidcProtocol($http, $this->clock);
    }

    public function provider(string $name): OAuthProvider
    {
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $name) !== 1) {
            throw new OAuthException('OIDC provider name is invalid.');
        }
        $providers = $this->config->get('oauth.providers', []);
        $settings = is_array($providers) ? ($providers[$name] ?? null) : null;
        if (!is_array($settings)) {
            throw new OAuthException('OIDC provider is not configured.');
        }
        $ttl = $this->config->get('oauth.transaction_ttl', 600);
        $capacity = $this->config->get('oauth.max_outstanding', 8);
        if (!is_int($ttl) || !is_int($capacity)) {
            throw new OAuthException('OIDC transaction policy is invalid.');
        }
        $provider = new OAuthProviderConfig($name, $settings);
        return new OAuthProvider(
            $provider,
            $this->protocol,
            new OAuthTransactionStore($this->sessions->store(), $this->clock, $ttl, $capacity)
        );
    }
}
