<?php

declare(strict_types=1);

namespace App\OAuth;

use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Session\SessionStore;
use InvalidArgumentException;

/**
 * One-session storage for short-lived OAuth authorization attempts.
 *
 * Concurrent browser tabs retain separate states. Only a SHA-256 digest of
 * each state is persisted; the PKCE verifier and OIDC nonce remain in private
 * session metadata, never in ordinary session data or flash values.
 */
final class OAuthTransactionStore
{
    private ModelClock $clock;

    public function __construct(private SessionStore $session, ?ModelClock $clock = null,
        private int $ttlSeconds = 600, private int $maxTransactions = 8)
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 900 || $maxTransactions < 1 || $maxTransactions > 16) {
            throw new OAuthException('OIDC transaction policy is invalid.');
        }
        $this->clock = $clock ?? new SystemModelClock();
    }

    /** Store a new authorization attempt. All fields must be generated or validated by the caller. */
    public function put(string $provider, string $state, string $codeVerifier, string $nonce): void
    {
        if ($provider === '' || $state === '' || $codeVerifier === '' || $nonce === '') {
            throw new InvalidArgumentException('OAuth transaction fields must be non-empty.');
        }

        $now = $this->clock->now()->getTimestamp();
        $this->session->recordOAuthTransaction(
            hash('sha256', $state),
            [
                'provider' => $provider,
                'code_verifier' => $codeVerifier,
                'nonce' => $nonce,
                'expires_at' => $now + $this->ttlSeconds,
            ],
            $now,
            $this->maxTransactions,
        );
    }

    /**
     * Consume state exactly once, including provider mismatch and expiry.
     *
     * @return array{code_verifier: string, nonce: string}|null
     */
    public function take(string $provider, string $state): ?array
    {
        $entry = $this->session->takeOAuthTransaction(hash('sha256', $state));
        if ($entry === null
            || !isset($entry['provider'], $entry['code_verifier'], $entry['nonce'], $entry['expires_at'])
            || !is_string($entry['provider'])
            || !is_string($entry['code_verifier'])
            || !is_string($entry['nonce'])
            || !is_int($entry['expires_at'])
            || $entry['expires_at'] <= $this->clock->now()->getTimestamp()
            || !hash_equals($entry['provider'], $provider)) {
            return null;
        }

        return ['code_verifier' => $entry['code_verifier'], 'nonce' => $entry['nonce']];
    }
}
