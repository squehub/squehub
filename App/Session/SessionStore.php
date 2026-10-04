<?php

declare(strict_types=1);

namespace App\Session;

use App\Support\SensitiveKey;
use App\Validation\UploadedFile;
use Closure;
use ReflectionReference;
use Throwable;

/**
 * One session's data and request-based flash generations.
 *
 * The store owns application keys while the driver owns persistence and ID
 * changes. A close/start pair marks a new request; reading flash data never
 * consumes it, and aging happens exactly once as that request begins.
 */
final class SessionStore
{
    private const INTERNAL = '_squehub';
    private bool $active = false;

    public function __construct(private SessionDriver $driver)
    {
    }

    public function start(): void
    {
        if ($this->active) return;
        $this->driver->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        // Old keys expire unless this request's predecessor flashed the same
        // key again. Move newly flashed keys into the readable generation.
        $new = $meta['new'] ?? [];
        foreach ($meta['old'] ?? [] as $key) {
            if (!in_array($key, $new, true)) unset($data[$key]);
        }
        $meta['old'] = array_values(array_unique($new));
        $meta['new'] = [];
        // Old input has the same one-next-request lifetime but lives solely
        // inside the reserved metadata root, away from application keys.
        if (($meta['input_old'] ?? false) && !($meta['input_new'] ?? false)) {
            unset($meta['old_input']);
        }
        $meta['input_old'] = $meta['input_new'] ?? false;
        $meta['input_new'] = false;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
        $this->active = true;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->validKey($key);
        $this->start();
        $data = $this->driver->data();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function has(string $key): bool
    {
        $this->validKey($key);
        $this->start();
        return array_key_exists($key, $this->driver->data());
    }

    public function put(string $key, mixed $value): void
    {
        $this->validKey($key);
        $this->serializable($value);
        $this->start();
        $data = $this->driver->data();
        $data[$key] = $value;
        $meta = $this->metadata($data);
        $meta['new'] = array_values(array_diff($meta['new'] ?? [], [$key]));
        $meta['old'] = array_values(array_diff($meta['old'] ?? [], [$key]));
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    public function forget(string $key): void
    {
        $this->validKey($key);
        $this->start();
        $data = $this->driver->data();
        unset($data[$key]);
        $meta = $this->metadata($data);
        $meta['new'] = array_values(array_diff($meta['new'] ?? [], [$key]));
        $meta['old'] = array_values(array_diff($meta['old'] ?? [], [$key]));
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    public function all(): array
    {
        $this->start();
        $data = $this->driver->data();
        // The old v1 token key is removed on first CSRF use, but must never
        // become part of the new public all() contract before that migration.
        unset($data[self::INTERNAL], $data['_token']);
        return $data;
    }

    /** @internal Authentication identifiers remain private to framework metadata. */
    public function authIdentifier(string $guard): int|string|null
    {
        $this->start();
        $value = $this->metadata($this->driver->data())['auth'][$guard] ?? null;
        return is_int($value) || is_string($value) ? $value : null;
    }

    /** @internal A missing fingerprint is deliberately stale, not silently upgraded. */
    public function authFingerprint(string $guard): ?string
    {
        $this->start();
        $value = $this->metadata($this->driver->data())['auth_fingerprints'][$guard] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @internal Keep the credential digest beside the ID, outside session()->all(). */
    public function setAuthIdentity(string $guard, int|string $identifier, string $fingerprint): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['auth'][$guard] = $identifier;
        $meta['auth_fingerprints'][$guard] = $fingerprint;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal Legacy ID-only entry stays stale until a fresh guard login adds its fingerprint. */
    public function setAuthIdentifier(string $guard, int|string $identifier): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['auth'][$guard] = $identifier;
        unset($meta['auth_fingerprints'][$guard]);
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal Missing, deleted, or stale credentials revoke only this guard entry. */
    public function forgetAuthIdentifier(string $guard): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        unset($meta['auth'][$guard]);
        unset($meta['auth_fingerprints'][$guard]);
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal A primary factor is not an authenticated identity until MFA completes. */
    public function pendingMfa(): ?array
    {
        $this->start();
        $value = $this->metadata($this->driver->data())['mfa_pending'] ?? null;
        return is_array($value) ? $value : null;
    }

    /** @internal Pending proof state is private framework metadata, never public session data. */
    public function setPendingMfa(array $pending): void
    {
        $this->serializable($pending);
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['mfa_pending'] = $pending;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal Completing or abandoning the challenge removes its identity binding. */
    public function clearPendingMfa(): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        unset($meta['mfa_pending']);
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal CSRF state lives with session metadata, outside application data and flash. */
    public function csrfToken(): ?string
    {
        $this->start();
        $token = $this->metadata($this->driver->data())['csrf_token'] ?? null;
        return is_string($token) ? $token : null;
    }

    /** @internal Token replacement is deliberately separate from ordinary put(). */
    public function setCsrfToken(string $token): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['csrf_token'] = $token;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /** @internal Browser navigation is metadata, never an application session key. */
    public function previousPath(): ?string
    {
        $this->start();
        $path = $this->metadata($this->driver->data())['previous_path'] ?? null;
        return is_string($path) ? $path : null;
    }

    /** @internal Only BrowserNavigation should supply a validated internal path. */
    public function setPreviousPath(string $path): void
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['previous_path'] = $path;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /**
     * @internal OAuth secrets live only in framework metadata. The caller
     * supplies a digest of state, an expiry, and the bounded entry count.
     */
    public function recordOAuthTransaction(string $stateDigest, array $entry, int $now, int $maximum): void
    {
        if ($maximum < 1) throw new SessionException('Invalid OAuth transaction capacity.');
        $this->serializable($entry);
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $transactions = $meta['oauth_transactions'] ?? [];
        if (!is_array($transactions)) $transactions = [];

        foreach ($transactions as $digest => $stored) {
            if (!is_string($digest) || !is_array($stored)
                || !isset($stored['expires_at']) || !is_int($stored['expires_at'])
                || $stored['expires_at'] <= $now) {
                unset($transactions[$digest]);
            }
        }

        // Replacing a digest also makes this entry the newest for eviction.
        unset($transactions[$stateDigest]);
        $transactions[$stateDigest] = $entry;
        while (count($transactions) > $maximum) array_shift($transactions);

        $meta['oauth_transactions'] = $transactions;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /**
     * @internal Remove the state before the caller checks provider or expiry.
     * A failed callback therefore cannot replay the transaction.
     */
    public function takeOAuthTransaction(string $stateDigest): ?array
    {
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $transactions = $meta['oauth_transactions'] ?? [];
        if (!is_array($transactions) || !array_key_exists($stateDigest, $transactions)) return null;

        $entry = $transactions[$stateDigest];
        unset($transactions[$stateDigest]);
        $meta['oauth_transactions'] = $transactions;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);

        return is_array($entry) ? $entry : null;
    }

    public function flash(string $key, mixed $value): void
    {
        $this->put($key, $value);
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['new'][] = $key;
        $meta['new'] = array_values(array_unique($meta['new']));
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    /**
     * @internal Legacy notification maps contain multiple types in one key.
     * Removing one type must retain the original flash generation for peers.
     */
    public function replaceKeepingFlash(string $key, mixed $value): void
    {
        $this->validKey($key);
        $this->serializable($value);
        $this->start();
        $data = $this->driver->data();
        $data[$key] = $value;
        $this->driver->replace($data);
    }

    public function flashInput(array $input): void
    {
        // Filtering precedes any mutation, so unexpected objects cannot leave
        // partial old input or leak a serialized upload into native storage.
        $filtered = $this->safeInput($input);
        $this->start();
        $data = $this->driver->data();
        $meta = $this->metadata($data);
        $meta['old_input'] = $filtered;
        $meta['input_new'] = true;
        $data[self::INTERNAL] = $meta;
        $this->driver->replace($data);
    }

    public function old(?string $key = null, mixed $default = null): mixed
    {
        $this->start();
        $input = $this->metadata($this->driver->data())['old_input'] ?? [];
        if ($key === null) return $input;
        $value = $input;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) return $default;
            $value = $value[$segment];
        }
        return $value;
    }

    public function id(): string { $this->start(); return $this->driver->id(); }
    /** Rotate the session identifier through the active driver when requested. */
    public function regenerate(): void { $this->start(); $this->driver->regenerate(); }
    public function invalidate(): void { $this->start(); $this->driver->invalidate(); }

    /** Release native session locking before the HTTP response body is sent. */
    public function close(): void
    {
        if (!$this->active) return;
        $this->driver->close();
        $this->active = false;
    }

    public function isStarted(): bool { return $this->active; }

    private function metadata(array $data): array
    {
        $meta = $data[self::INTERNAL] ?? [];
        return is_array($meta) ? $meta : [];
    }

    private function validKey(string $key): void
    {
        if ($key === '' || $key === self::INTERNAL || preg_match('/[\x00-\x1F\x7F]/', $key)) {
            throw new SessionException('Invalid or reserved session key.');
        }
    }

    private function serializable(mixed $value): void
    {
        $references = [];
        $this->checkValue($value, $references);
        try {
            serialize($value);
        } catch (Throwable $exception) {
            throw new SessionException('Session value cannot be serialized.', 0, $exception);
        }
    }

    private function checkValue(mixed $value, array &$references): void
    {
        if (is_resource($value) || $value instanceof Closure) {
            throw new SessionException('Session value cannot be serialized.');
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                // PHP can serialize recursive arrays. Follow each reference
                // once so validation cannot recurse forever on such values.
                $reference = ReflectionReference::fromArrayElement($value, $key);
                if ($reference !== null) {
                    $id = $reference->getId();
                    if (isset($references[$id])) continue;
                    $references[$id] = true;
                }
                $this->checkValue($item, $references);
            }
        }
    }

    /** Keep request-style values only; reject unexpected objects and omit uploads. */
    private function safeInput(array $input): array
    {
        $result = [];
        foreach ($input as $key => $value) {
            if (SensitiveKey::matches((string) $key)) continue;
            if ($value instanceof UploadedFile) continue;
            if (is_array($value)) {
                if (array_key_exists('tmp_name', $value) && array_key_exists('error', $value)) continue;
                $result[$key] = $this->safeInput($value);
            } elseif (is_scalar($value) || $value === null) {
                $result[$key] = $value;
            } else {
                throw new SessionException('Old input contains an unsupported value.');
            }
        }
        return $result;
    }
}
