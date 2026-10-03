<?php

declare(strict_types=1);

namespace App\Session;

use App\Config\Repository;
use App\Session\Drivers\ArraySessionDriver;
use App\Session\Drivers\NativeSessionDriver;
use App\Session\Drivers\RedisSessionHandler;
use App\Redis\InfrastructureSelection;
use App\Redis\RedisManager;

/**
 * Builds one configured store for an Application without starting PHP sessions.
 * Configuration is checked before the native driver can emit cookie headers;
 * subsequent resolutions reuse the same store and request state.
 */
final class SessionManager
{
    private ?SessionStore $store = null;
    private ?InfrastructureSelection $selection = null;

    public function __construct(private Repository $config, private ?RedisManager $redis = null,
        private string $namespace = '')
    {
    }

    /** The selected backend remains fixed once store() has resolved it. */
    public function infrastructure(): ?InfrastructureSelection { return $this->selection; }

    public function store(): SessionStore
    {
        if ($this->store !== null) return $this->store;
        $configured = $this->config->get('session.driver', 'native');
        $redisName = $this->config->get('session.redis_connection');
        $redisNamespace = $this->config->get('session.redis_namespace');
        if (!is_string($configured) || !in_array($configured, ['native', 'array', 'redis', 'auto'], true)
            || ($redisName !== null && (!is_string($redisName)
                || !preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,63}$/D', $redisName)))
            || ($redisNamespace !== null && (!is_string($redisNamespace)
                || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $redisNamespace)))) {
            throw new SessionException('Invalid session driver or Redis selection.');
        }
        $this->selection ??= new InfrastructureSelection($configured, 'native', $this->redis, $redisName);
        try {
            $driver = $this->selection->resolve();
        } catch (\App\Redis\RedisException $failure) {
            throw new SessionException('Session Redis selection failed.', 0, $failure);
        }
        if ($driver === 'array') {
            return $this->store = new SessionStore(new ArraySessionDriver());
        }
        if ($driver !== 'native' && $driver !== 'redis') throw new SessionException('Unsupported session driver.');

        $name = $this->config->get('session.name', 'squehub_session');
        $minutes = $this->config->get('session.lifetime', 120);
        $path = $this->config->get('session.path', '/');
        $domain = $this->config->get('session.domain');
        $secure = $this->config->get('session.secure', false);
        $httpOnly = $this->config->get('session.http_only', true);
        $strict = $this->config->get('session.strict_mode', true);
        $sameSite = $this->config->get('session.same_site', 'Lax');
        if (!is_string($name) || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/D', $name) !== 1) {
            throw new SessionException('Invalid session name.');
        }
        if (filter_var($minutes, FILTER_VALIDATE_INT) === false || (int) $minutes < 0 || (int) $minutes > 525600) {
            throw new SessionException('Session lifetime must be minutes between 0 and 525600.');
        }
        if (!is_string($path) || $path === '' || $path[0] !== '/' || preg_match('/[\x00-\x1F\x7F;]/', $path)) {
            throw new SessionException('Invalid session cookie path.');
        }
        if ($domain !== null && (!is_string($domain) || preg_match('/[\x00-\x20\x7F;,]/', $domain))) {
            throw new SessionException('Invalid session cookie domain.');
        }
        if (!is_bool($secure) || !is_bool($httpOnly) || !is_bool($strict)) {
            throw new SessionException('Session security options must be booleans.');
        }
        if (!is_string($sameSite) || !in_array(strtolower($sameSite), ['lax', 'strict', 'none'], true)) {
            throw new SessionException('Invalid SameSite policy.');
        }
        $sameSite = ucfirst(strtolower($sameSite));
        if ($sameSite === 'None' && !$secure) {
            throw new SessionException('SameSite=None requires a secure session cookie.');
        }
        $handler = null;
        if ($driver === 'redis') {
            if ($this->redis === null) throw new SessionException('Redis Session requires the Redis provider.');
            try {
                $handler = new RedisSessionHandler($this->redis->connection($redisName),
                    ($redisNamespace ?? $this->namespace) . "\0" . $name,
                    (int) $minutes === 0 ? 1440 : (int) $minutes * 60);
            } catch (\App\Redis\RedisException $failure) {
                throw new SessionException('Redis Session is unavailable.', 0, $failure);
            }
        }
        return $this->store = new SessionStore(new NativeSessionDriver([
            'name' => $name, 'lifetime' => (int) $minutes, 'path' => $path,
            'domain' => $domain, 'secure' => $secure, 'http_only' => $httpOnly,
            'strict_mode' => $strict, 'same_site' => $sameSite,
        ], $handler));
    }
}
