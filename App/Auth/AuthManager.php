<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Contracts\AuthGuard;
use App\Auth\Contracts\Authenticatable;
use App\Auth\Contracts\PrimaryAuthenticationGate;
use App\Auth\Contracts\StatefulAuthGuard;
use App\Auth\Guards\SessionGuard;
use App\Auth\Guards\TokenGuard;
use App\Auth\Identity\ModelIdentityProvider;
use App\Auth\Remember\RememberManager;
use App\Auth\Remember\RememberException;
use App\Auth\Remember\Repositories\ArrayRememberTokenRepository;
use App\Auth\Remember\Repositories\DatabaseRememberTokenRepository;
use App\Auth\Tokens\Repositories\ArrayTokenRepository;
use App\Auth\Tokens\Repositories\DatabaseTokenRepository;
use App\Auth\Tokens\TokenManager;
use App\Auth\Tokens\TokenMetadata;
use App\Container\Container;
use App\Config\Repository;
use App\Database\DatabaseManager;
use App\Database\Identifier;
use App\Database\Exception\InvalidIdentifierException;
use App\Database\Model;
use App\Database\ModelClock;
use App\Database\SystemModelClock;
use App\Diagnostics\Diagnostics;
use App\Http\Request;
use App\Http\Response;
use App\Session\SessionManager;

/** Validates Application configuration once and reuses guard/provider instances. */
final class AuthManager
{
    private ?string $default;
    private array $guardsConfig;
    private array $identitiesConfig;
    private array $tokenSettings;
    private array $rememberSettings;
    /** @var array<string, AuthGuard> */
    private array $guards = [];
    private ?Request $request = null;
    private ?string $requestGuard = null;

    public function __construct(
        private Repository $config,
        private SessionManager $sessions,
        private PasswordHasher $passwords,
        private ?Diagnostics $diagnostics = null,
        private ?Container $container = null
    ) {
        $this->validateConfiguration();
    }

    private function validateConfiguration(): void
    {
        $default = $this->config->get('auth.default');
        $guards = $this->config->get('auth.guards', []);
        $identities = $this->config->get('auth.identities', []);
        if (($default !== null && !is_string($default))
            || !is_array($guards) || !is_array($identities)) {
            throw new AuthException('Invalid authentication configuration.');
        }
        $this->default = $default;
        $this->guardsConfig = $guards;
        $this->identitiesConfig = $identities;
        $tokens = $this->config->get('auth.tokens', []);
        if (!is_array($tokens)) throw new AuthException('Authentication token configuration must be an array.');
        $this->tokenSettings = TokenManager::settings($tokens);
        $browser = $this->config->get('auth.browser', []);
        if (!is_array($browser)) throw new AuthException('Authentication browser configuration must be an array.');
        foreach ($this->identitiesConfig as $name => $identity) {
            $this->validName($name);
            if (!is_array($identity) || ($identity['driver'] ?? null) !== 'model') {
                throw new AuthException('Unsupported authentication identity driver.');
            }
            $class = $identity['model'] ?? null;
            if (!is_string($class) || !class_exists($class)
                || !is_subclass_of($class, Model::class)
                || !is_subclass_of($class, Authenticatable::class)) {
                throw new AuthException('Configured identity must be a modern Model implementing Authenticatable.');
            }
            foreach (['identifier', 'password'] as $key) {
                if (!is_string($identity[$key] ?? null)) throw new AuthException('Invalid authentication identity column.');
                $this->validColumn($identity[$key]);
            }
            $fields = $identity['credentials'] ?? null;
            if (!is_array($fields) || !array_is_list($fields) || $fields === []) {
                throw new AuthException('Authentication credential columns must be a nonempty list.');
            }
            foreach ($fields as $field) {
                if (!is_string($field) || $field === $identity['password'] || $field === 'password') {
                    throw new AuthException('Invalid authentication credential column.');
                }
                $this->validColumn($field);
            }
            if (count(array_unique($fields)) !== count($fields)) {
                throw new AuthException('Authentication credential columns must be unique.');
            }
            $address = $identity['verification_address'] ?? null;
            $verified = $identity['verified_at'] ?? null;
            if (($address === null) !== ($verified === null)) {
                throw new AuthException('Email verification requires both address and verified timestamp columns.');
            }
            if ($address !== null) {
                if (!is_string($address) || !is_string($verified) || $address === $verified
                    || in_array($address, [$identity['identifier'], $identity['password']], true)
                    || in_array($verified, [$identity['identifier'], $identity['password']], true)) {
                    throw new AuthException('Invalid email verification columns.');
                }
                $this->validColumn($address);
                $this->validColumn($verified);
            }
        }
        foreach ($this->guardsConfig as $name => $guard) {
            $this->validName($name);
            if (!is_array($guard) || !in_array($guard['driver'] ?? null, ['session', 'token'], true)) {
                throw new AuthException('Unsupported authentication guard driver.');
            }
            $identity = $guard['identity'] ?? null;
            if (!is_string($identity) || !isset($this->identitiesConfig[$identity])) {
                throw new AuthException('Authentication guard refers to an unknown identity source.');
            }
            if ($guard['driver'] === 'token') {
                if (strlen($name) > 64) throw new AuthException('Token guard name is too long.');
                $repository = $guard['repository'] ?? $this->tokenSettings['driver'];
                if (!is_string($repository) || !in_array($repository, ['database', 'array'], true)) {
                    throw new AuthException('Token guard repository must be database or array.');
                }
            }
        }
        $remember = $this->config->get('auth.remember', []);
        if (!is_array($remember)) throw new RememberException('Remember configuration must be a map.');
        $sessionNames = [];
        foreach ($this->guardsConfig as $name => $guard) {
            if ($guard['driver'] === 'session') $sessionNames[] = $name;
        }
        $this->rememberSettings = RememberManager::settings($remember, $this->config, $sessionNames);
        if ($this->rememberSettings['enabled']) {
            foreach ($sessionNames as $name) {
                if (strlen($name) > 64) throw new RememberException('Remember guard name is too long.');
            }
        }
        if ($this->default !== null && !isset($this->guardsConfig[$this->default])) {
            throw new AuthException('Default authentication guard is not configured.');
        }
        $rehash = $this->config->get('auth.passwords.rehash_on_login', true);
        if (!is_bool($rehash)) throw new AuthException('Password rehash_on_login must be a boolean.');
        foreach (['login_path', 'authenticated_path'] as $key) {
            $path = $this->config->get('auth.browser.' . $key);
            if ($path !== null && (!is_string($path) || \App\Http\BrowserNavigation::internalPath($path) === null)) {
                throw new AuthException('Authentication browser path must be a safe internal path.');
            }
        }
    }

    private function validName(mixed $name): void
    {
        if (!is_string($name) || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1) {
            throw new AuthException('Authentication name must be an identifier.');
        }
    }

    private function validColumn(string $column): void
    {
        try {
            Identifier::simple($column);
        } catch (InvalidIdentifierException $exception) {
            throw new AuthException('Invalid authentication identity column.', 0, $exception);
        }
    }

    public function guard(?string $name = null): AuthGuard
    {
        $name ??= $this->requestGuard ?? $this->default;
        if ($name === null) throw new AuthException('No default authentication guard is configured.');
        if (!isset($this->guardsConfig[$name])) throw new AuthException('Authentication guard is not configured.');
        if (isset($this->guards[$name])) return $this->guards[$name];
        $source = $this->identitiesConfig[$this->guardsConfig[$name]['identity']];
        $provider = new ModelIdentityProvider($source['model'], $source['identifier'],
            $source['password'], $source['credentials'],
            $source['verification_address'] ?? null, $source['verified_at'] ?? null);
        if ($this->guardsConfig[$name]['driver'] === 'token') {
            if ($this->container === null) throw new AuthException('Token guard requires the Application container.');
            $driver = $this->guardsConfig[$name]['repository'] ?? $this->tokenSettings['driver'];
            $repository = $driver === 'array'
                ? new ArrayTokenRepository()
                : new DatabaseTokenRepository($this->container->make(DatabaseManager::class),
                    $this->tokenSettings['table'], $this->tokenSettings['connection']);
            $clock = $this->container->has(ModelClock::class)
                ? $this->container->make(ModelClock::class) : new SystemModelClock();
            $settings = $this->tokenSettings;
            $settings['driver'] = $driver;
            $guard = new TokenGuard(new TokenManager($name, $provider, $repository,
                $clock, $settings, $this->diagnostics));
            $guard->bindRequest($this->request);
            return $this->guards[$name] = $guard;
        }
        $remember = null;
        if ($this->rememberSettings['enabled']) {
            if ($this->container === null) throw new RememberException('Remember-me requires the Application container.');
            $repository = $this->rememberSettings['driver'] === 'array'
                ? new ArrayRememberTokenRepository()
                : new DatabaseRememberTokenRepository($this->container->make(DatabaseManager::class),
                    $this->rememberSettings['table'], $this->rememberSettings['connection']);
            $clock = $this->container->has(ModelClock::class)
                ? $this->container->make(ModelClock::class) : new SystemModelClock();
            $remember = new RememberManager($name, $repository, $clock, $this->rememberSettings);
            if ($this->request !== null) $remember->beginRequest($this->request);
        }
        return $this->guards[$name] = new SessionGuard(
            $name, $provider, $this->sessions->store(), $this->passwords,
            $source['credentials'], $this->config->get('auth.passwords.rehash_on_login', true),
            $this->diagnostics, fn () => $this->afterSessionLogout(), $remember,
            $this->container?->has(PrimaryAuthenticationGate::class)
                ? $this->container->make(PrimaryAuthenticationGate::class) : null
        );
    }

    private function stateful(): StatefulAuthGuard
    {
        $guard = $this->guard();
        if (!$guard instanceof StatefulAuthGuard) throw new AuthException('Default guard cannot change authentication state.');
        return $guard;
    }

    private function currentGuard(): AuthGuard { return $this->guard(); }

    /** Configuration lives for the Application; resolved identities live for one request. */
    public function resetRequestState(): void
    {
        $this->request = null;
        $this->requestGuard = null;
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard) $guard->clearRequestState();
            else $guard->resetRequestState();
        }
    }

    /** @internal Clear identities after whole-session invalidation without losing remember request bindings. */
    public function clearSessionGuardIdentityCaches(): void
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard) $guard->resetRequestState();
        }
    }

    /** @internal Errors before request validation must never receive an older cookie. */
    public function prepareRequest(): void { $this->resetRequestState(); }

    /** Bind one Request without touching Session; cached identities cannot cross Kernel handles. */
    public function beginRequest(Request $request): void
    {
        $this->resetRequestState();
        $this->request = $request;
        foreach ($this->guards as $guard) {
            if ($guard instanceof TokenGuard) $guard->bindRequest($request);
            if ($guard instanceof SessionGuard) $guard->beginRequest($request);
        }
    }

    /** Attach queued typed cookies after route handling, including error responses. */
    public function decorateResponse(Response $response): Response
    {
        foreach ($this->guards as $guard) {
            if ($guard instanceof SessionGuard && ($cookie = $guard->pendingRememberCookie()) !== null) {
                $response = $response->withCookie($cookie);
            }
        }
        return $response;
    }

    /** Whole-session logout must also clear each named guard's browser cookie. */
    private function afterSessionLogout(): void
    {
        $failure = null;
        if ($this->rememberSettings['enabled']) {
            foreach ($this->guardsConfig as $name => $configuration) {
                if ($configuration['driver'] !== 'session') continue;
                try {
                    $guard = $this->guard($name);
                    if ($guard instanceof SessionGuard) $guard->forgetRemembered();
                } catch (\Throwable $exception) {
                    $failure ??= $exception;
                }
            }
        }
        $this->requestGuard = null;
        foreach ($this->guards as $guard) $guard->resetRequestState();
        if ($failure !== null) throw $failure;
    }

    /** @internal Token middleware chooses the sole identity source for downstream Auth and Gate calls. */
    public function selectGuardForRequest(string $name): void
    {
        if ($this->request === null) throw new AuthException('A request guard cannot be selected outside HTTP handling.');
        $guard = $this->guard($name);
        if (!$guard instanceof TokenGuard || !$guard->check()) {
            throw new AuthException('A verified token guard is required for request selection.');
        }
        $this->requestGuard = $name;
    }

    /** The selected token is safe metadata; raw Bearer material is never retained here. */
    public function token(): ?TokenMetadata
    {
        if ($this->requestGuard === null && $this->default === null) return null;
        $guard = $this->guard();
        return $guard instanceof TokenGuard ? $guard->token() : null;
    }

    public function tokenAllows(string $ability): bool
    {
        return $this->token()?->allows($ability) ?? false;
    }

    /** Resolve the issuance manager for an explicitly configured token guard. */
    public function tokens(?string $name = null): TokenManager
    {
        $guard = $this->guard($name);
        if (!$guard instanceof TokenGuard) throw new AuthException('Selected guard does not support API tokens.');
        return $guard->manager();
    }

    public function user(): ?Authenticatable { return $this->currentGuard()->user(); }
    /** A selected token guard can supply an identity even without a browser default. */
    public function hasDefaultGuard(): bool { return $this->default !== null || $this->requestGuard !== null; }
    /** @internal AccountSecurity uses the configured guard rather than parsing Auth configuration again. */
    public function defaultGuardName(): ?string { return $this->default; }
    public function id(): int|string|null { return $this->currentGuard()->id(); }
    public function check(): bool { return $this->currentGuard()->check(); }
    public function guest(): bool { return $this->currentGuard()->guest(); }
    /** @param array<string, mixed> $credentials */
    public function attempt(#[\SensitiveParameter] array $credentials, bool $remember = false): bool
    {
        return $this->stateful()->attempt($credentials, $remember);
    }
    public function login(Authenticatable $identity, bool $remember = false): void
    {
        $this->stateful()->login($identity, $remember);
    }
    public function logout(): void { $this->stateful()->logout(); }

    public function forgetRemembered(?string $name = null): void
    {
        $guard = $this->guard($name);
        if (!$guard instanceof SessionGuard) throw new AuthException('Selected guard has no browser Session.');
        $guard->forgetRemembered();
    }

    public function revokeRemembered(Authenticatable $identity, ?string $name = null): void
    {
        $guard = $this->guard($name);
        if (!$guard instanceof SessionGuard) throw new AuthException('Selected guard has no browser Session.');
        $guard->revokeRemembered($identity);
    }
}
