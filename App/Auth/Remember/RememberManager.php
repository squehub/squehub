<?php

declare(strict_types=1);

namespace App\Auth\Remember;

use App\AccountSecurity\CredentialFingerprint;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\IdentityProvider;
use App\Config\Repository;
use App\Database\ModelClock;
use App\Database\Schema\Table;
use App\Http\Cookie;
use App\Http\Request;
use DateTimeImmutable;
use Throwable;

/** One guard's persistent browser credentials and request-local cookie output. */
final class RememberManager
{
    private ?Request $request = null;
    private ?Cookie $pendingCookie = null;
    private ?string $currentSelector = null;
    private bool $attempted = false;

    /** @param array<string,mixed> $settings */
    public function __construct(private string $guard, private RememberTokenRepository $tokens,
        private ModelClock $clock, private array $settings)
    {
    }

    /** @param array<string,mixed> $provided @param list<string> $guardNames
     *  @return array<string,mixed>
     */
    public static function settings(array $provided, Repository $config, array $guardNames): array
    {
        $cookie = $provided['cookie'] ?? [];
        if (!is_array($cookie)) throw new RememberException('Remember cookie configuration must be a map.');
        $settings = [
            'enabled' => $provided['enabled'] ?? false,
            'driver' => $provided['driver'] ?? 'database',
            'table' => $provided['table'] ?? 'remember_tokens',
            'connection' => $provided['connection'] ?? null,
            'ttl' => $provided['ttl'] ?? 2592000,
            'cookie' => [
                'name' => $cookie['name'] ?? 'squehub_remember',
                'path' => $cookie['path'] ?? null,
                'domain' => $cookie['domain'] ?? null,
                'secure' => $cookie['secure'] ?? null,
                'http_only' => $cookie['http_only'] ?? true,
                'same_site' => $cookie['same_site'] ?? 'Lax',
            ],
            'session_path' => $config->get('session.path', '/'),
            'session_secure' => $config->get('session.secure', false),
        ];
        if (!is_bool($settings['enabled']) || !in_array($settings['driver'], ['database', 'array'], true)
            || !is_string($settings['table'])
            || ($settings['connection'] !== null && (!is_string($settings['connection'])
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/D', $settings['connection']) !== 1))
            || !is_int($settings['ttl']) || $settings['ttl'] < 1 || $settings['ttl'] > 31536000
            || !is_string($settings['session_path']) || !is_bool($settings['session_secure'])) {
            throw new RememberException('Remember configuration is invalid.');
        }
        try { Table::name($settings['table']); } catch (Throwable $failure) {
            throw new RememberException('Remember table name is invalid.', 0, $failure);
        }
        $policy = $settings['cookie'];
        if (!is_string($policy['name']) || strlen($policy['name']) > 180
            || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $policy['name']) !== 1
            || ($policy['path'] !== null && !is_string($policy['path']))
            || ($policy['domain'] !== null && !is_string($policy['domain']))
            || ($policy['secure'] !== null && !is_bool($policy['secure']))
            || !is_bool($policy['http_only']) || !is_string($policy['same_site'])) {
            throw new RememberException('Remember cookie configuration is invalid.');
        }
        try {
            foreach ($settings['enabled'] ? $guardNames : ['web'] as $guard) {
                new Cookie($policy['name'] . '_' . $guard, '', null, null,
                    $policy['path'] ?? '/', $policy['domain'], $policy['secure'] ?? true,
                    $policy['http_only'], $policy['same_site']);
            }
            new Cookie('remember_validation', '', null, null, $settings['session_path']);
        } catch (Throwable $failure) {
            throw new RememberException('Remember cookie configuration is invalid.', 0, $failure);
        }
        return $settings;
    }

    public function beginRequest(Request $request): void
    {
        $this->clearRequest();
        $this->request = $request;
    }

    public function clearRequest(): void
    {
        $this->request = null;
        $this->pendingCookie = null;
        $this->currentSelector = null;
        $this->attempted = false;
    }

    public function pendingCookie(): ?Cookie { return $this->pendingCookie; }

    /** Issue before Session mutation; caller publishes only after primary adoption. */
    public function issue(Authenticatable $identity): RememberCredential
    {
        $this->requireRequest();
        [$record, $credential] = $this->newCredential($identity);
        $presented = self::parse($this->request->cookie($this->cookieName()));
        if ($presented !== null) {
            $old = $this->tokens->find($presented[0]);
            if ($old !== null && $old->guard === $this->guard) {
                if (!hash_equals($old->validatorHash, hash('sha256', $presented[1]))) {
                    $this->tokens->revokeSelector($presented[0]);
                } elseif ($old->identityKey === $record->identityKey) {
                    if (!$this->tokens->rotate($presented[0], $old->validatorHash, $record)) {
                        throw new RememberException('Remember credential changed during reissue.');
                    }
                    return $credential;
                } else {
                    // A browser changing accounts must not retain the old
                    // account's active remembered credential.
                    $this->tokens->revokeSelector($presented[0]);
                }
            }
        }
        $this->tokens->insert($record);
        return $credential;
    }

    public function publish(RememberCredential $credential): void
    {
        if ($this->currentSelector !== null && $this->currentSelector !== $credential->selector) {
            $this->tokens->revokeSelector($this->currentSelector);
        }
        $this->currentSelector = $credential->selector;
        $this->pendingCookie = $credential->cookie;
    }

    public function discard(RememberCredential $credential): void
    {
        $this->tokens->revokeSelector($credential->selector);
        if ($this->currentSelector === $credential->selector) $this->currentSelector = null;
    }

    /** A later failed login in the same request must not publish an older issue. */
    public function cancelPendingIssue(): void
    {
        if ($this->currentSelector === null) return;
        $selector = $this->currentSelector;
        $this->currentSelector = null;
        $this->forgetCookie();
        $this->tokens->revokeSelector($selector);
    }

    /** A remembered primary factor is returned without establishing a Session. */
    public function recall(IdentityProvider $provider): ?RememberedIdentity
    {
        if ($this->attempted || $this->request === null) return null;
        $this->attempted = true;
        $value = $this->request->cookie($this->cookieName());
        if ($value === null) return null;
        $parsed = self::parse($value);
        if ($parsed === null) {
            $this->forgetCookie();
            return null;
        }
        [$selector, $validator] = $parsed;
        $record = $this->tokens->find($selector);
        if ($record === null) {
            $this->forgetCookie();
            return null;
        }
        if ($record->guard !== $this->guard) {
            $this->forgetCookie();
            return null;
        }
        if (!hash_equals($record->validatorHash, hash('sha256', $validator))) {
            // A known selector with a wrong validator is a possible replay.
            $this->tokens->revokeSelector($selector);
            $this->forgetCookie();
            return null;
        }
        if ($record->expired($this->clock->now())) {
            $this->tokens->revokeSelector($selector);
            $this->forgetCookie();
            return null;
        }
        $identity = $provider->retrieveById($record->identifier());
        if ($identity === null || !$provider->supports($identity)
            || RememberTokenRecord::identityKey($identity->authIdentifier()) !== $record->identityKey
            || !hash_equals($record->credentialFingerprint,
                CredentialFingerprint::fromHash($identity->authPasswordHash()))) {
            $this->tokens->revokeSelector($selector);
            $this->forgetCookie();
            return null;
        }
        [$replacement, $credential] = $this->newCredential($identity);
        if (!$this->tokens->rotate($selector, $record->validatorHash, $replacement)) {
            // Another request won the old selector. It owns the new cookie.
            return null;
        }
        return new RememberedIdentity($identity, $credential);
    }

    /** Logout forgets this browser only, including an invalid presented cookie. */
    public function forgetCurrent(): void
    {
        if ($this->request === null) return;
        // Even if persistence is unavailable, the response must attempt to
        // expire this browser's cookie on the same name/path/domain scope.
        $this->forgetCookie();
        if ($this->currentSelector !== null) {
            $this->tokens->revokeSelector($this->currentSelector);
            $this->currentSelector = null;
        }
        $parsed = self::parse($this->request->cookie($this->cookieName()));
        if ($parsed !== null) {
            $record = $this->tokens->find($parsed[0]);
            if ($record !== null && $record->guard === $this->guard) {
                $this->tokens->revokeSelector($parsed[0]);
            }
        }
    }

    /** Explicit all-device revocation; password writes also invalidate lazily by fingerprint. */
    public function revokeIdentity(Authenticatable $identity): void
    {
        $this->tokens->revokeIdentity($this->guard, RememberTokenRecord::identityKey($identity->authIdentifier()));
    }

    private function newCredential(Authenticatable $identity): array
    {
        $now = $this->clock->now();
        $expires = $now->modify('+' . $this->settings['ttl'] . ' seconds');
        $selector = \App\Support\SecureRandom::token(16);
        $validator = \App\Support\SecureRandom::token(32);
        $record = new RememberTokenRecord($selector, hash('sha256', $validator), $this->guard,
            RememberTokenRecord::identityKey($identity->authIdentifier()),
            CredentialFingerprint::fromHash($identity->authPasswordHash()), $now, $expires);
        return [$record, new RememberCredential($selector,
            $this->cookie($selector . '.' . $validator, $expires))];
    }

    private function cookie(string $value, DateTimeImmutable $expires): Cookie
    {
        $request = $this->requireRequest();
        $policy = $this->settings['cookie'];
        return new Cookie($this->cookieName(), $value, $expires, $this->settings['ttl'],
            $this->path(), $policy['domain'], $this->secure($request),
            $policy['http_only'], $policy['same_site']);
    }

    private function forgetCookie(): void
    {
        $request = $this->requireRequest();
        $policy = $this->settings['cookie'];
        $this->pendingCookie = Cookie::forget($this->cookieName(), $this->path(),
            $policy['domain'], $this->secure($request), $policy['http_only'], $policy['same_site']);
    }

    private function path(): string
    {
        $configured = $this->settings['cookie']['path'];
        if ($configured !== null) return $configured;
        $mount = $this->request?->basePath() ?? '';
        return $mount !== '' ? $mount : $this->settings['session_path'];
    }

    private function secure(Request $request): bool
    {
        return $this->settings['cookie']['secure']
            ?? ($this->settings['session_secure'] || $request->scheme() === 'https');
    }

    private function cookieName(): string { return $this->settings['cookie']['name'] . '_' . $this->guard; }

    private function requireRequest(): Request
    {
        return $this->request ?? throw new RememberException('Remember credentials require an HTTP request.');
    }

    /** @return array{string,string}|null */
    private static function parse(mixed $value): ?array
    {
        if (!is_string($value) || strlen($value) !== 66
            || preg_match('/\A([A-Za-z0-9_-]{22})\.([A-Za-z0-9_-]{43})\z/D', $value, $match) !== 1) {
            return null;
        }
        return [$match[1], $match[2]];
    }
}
