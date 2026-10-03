<?php

declare(strict_types=1);

namespace App\Auth;

use App\Config\Repository;

/** Uses PHP's password APIs with validated resource and bcrypt length limits. */
class PasswordHasher
{
    private string|int $algorithm;
    private array $options;
    private int $maxBytes;
    private ?string $dummyHash = null;

    public function __construct(Repository $config)
    {
        $settings = $config->get('auth.passwords', []);
        if (!is_array($settings)) throw new AuthException('Authentication password configuration must be an array.');
        $name = $settings['algorithm'] ?? 'default';
        if (!is_string($name) || !in_array($name, ['default', 'bcrypt', 'argon2id'], true)) {
            throw new AuthException('Unsupported password algorithm.');
        }
        if ($name === 'argon2id' && !defined('PASSWORD_ARGON2ID')) {
            throw new AuthException('Argon2id is unavailable in this PHP build.');
        }
        $this->algorithm = match ($name) {
            'default' => PASSWORD_DEFAULT,
            'bcrypt' => PASSWORD_BCRYPT,
            'argon2id' => PASSWORD_ARGON2ID,
        };
        $this->options = $settings['options'] ?? [];
        if (!is_array($this->options)) throw new AuthException('Password options must be an array.');
        $allowed = $name === 'argon2id' ? ['memory_cost', 'time_cost', 'threads'] : ['cost'];
        foreach ($this->options as $key => $value) {
            if (!is_string($key) || !in_array($key, $allowed, true) || !is_int($value) || $value < 1) {
                throw new AuthException('Invalid password algorithm option.');
            }
        }
        if (isset($this->options['cost']) && ($this->options['cost'] < 4 || $this->options['cost'] > 31)) {
            throw new AuthException('Bcrypt cost must be between 4 and 31.');
        }
        if (isset($this->options['memory_cost']) && $this->options['memory_cost'] < 8) {
            throw new AuthException('Argon2id memory cost must be at least 8 KiB.');
        }
        $this->maxBytes = $settings['max_bytes'] ?? 4096;
        if (!is_int($this->maxBytes) || $this->maxBytes < 1 || $this->maxBytes > 65536) {
            throw new AuthException('Password byte limit must be between 1 and 65536.');
        }
        if (!is_bool($settings['rehash_on_login'] ?? true)) {
            throw new AuthException('Password rehash_on_login must be a boolean.');
        }
    }

    public function accepts(#[\SensitiveParameter] string $password, #[\SensitiveParameter] ?string $hash = null): bool
    {
        if (strlen($password) > $this->maxBytes) return false;
        $bcrypt = $hash === null
            ? $this->algorithm === PASSWORD_BCRYPT
            : password_get_info($hash)['algoName'] === 'bcrypt';
        return !$bcrypt || strlen($password) <= 72;
    }

    public function hash(#[\SensitiveParameter] string $plainPassword): string
    {
        if (!$this->accepts($plainPassword)) throw new AuthException('Password exceeds the configured technical limit.');
        return password_hash($plainPassword, $this->algorithm, $this->options);
    }

    public function verify(#[\SensitiveParameter] string $plainPassword, #[\SensitiveParameter] string $hash): bool
    {
        return $this->accepts($plainPassword, $hash) && password_verify($plainPassword, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, $this->algorithm, $this->options);
    }

    /** One lazily generated hash per hasher avoids repeating expensive work for unknown identities. */
    public function verifyDummy(#[\SensitiveParameter] string $plainPassword): void
    {
        $this->dummyHash ??= $this->hash(str_repeat('x', min($this->maxBytes, 16)));
        $this->verify($plainPassword, $this->dummyHash);
    }
}
