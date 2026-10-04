<?php

declare(strict_types=1);

namespace App\Session\Drivers;

use App\Session\SessionDriver;
use App\Session\SessionException;
use ErrorException;
use SessionHandlerInterface;
use Throwable;

/** Owns PHP's session calls; an existing active legacy session is adopted. */
final class NativeSessionDriver implements SessionDriver
{
    private bool $startedByThis = false;

    public function __construct(private array $config, private ?SessionHandlerInterface $handler = null)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            if ($this->handler !== null && !$this->startedByThis) {
                throw new SessionException('Redis session cannot adopt an active native session.');
            }
            return;
        }
        if (headers_sent()) throw new SessionException('Session cannot start after response output.');

        $seconds = $this->config['lifetime'] * 60;
        // A zero cookie lifetime means "until browser close", not immediate
        // server-side garbage collection of the saved session record.
        $storageSeconds = $seconds === 0 ? 1440 : $seconds;
        // Configure the active PHP session before session_start; PHP rejects
        // several of these options once headers or the session are open.
        $this->call(function () use ($seconds, $storageSeconds): void {
            foreach ([
                'session.use_strict_mode' => $this->config['strict_mode'] ? '1' : '0',
                'session.use_only_cookies' => '1',
                'session.use_trans_sid' => '0',
                'session.gc_maxlifetime' => (string) $storageSeconds,
            ] as $option => $value) {
                if (ini_set($option, $value) === false) {
                    throw new SessionException('Session configuration failed.');
                }
            }
            session_cache_limiter('');
            if (session_name($this->config['name']) === false) {
                throw new SessionException('Session name could not be configured.');
            }
            if (!session_set_cookie_params([
                'lifetime' => $seconds, 'path' => $this->config['path'],
                'domain' => $this->config['domain'] ?? '', 'secure' => $this->config['secure'],
                'httponly' => $this->config['http_only'], 'samesite' => $this->config['same_site'],
            ])) {
                throw new SessionException('Session cookie could not be configured.');
            }
            if ($this->handler !== null && !session_set_save_handler($this->handler, true)) {
                throw new SessionException('Redis session handler could not be configured.');
            }
        }, 'Session configuration failed.');
        $this->call(static function (): void {
            if (!session_start()) throw new SessionException('Session could not start.');
        }, 'Session could not start.');
        $this->startedByThis = true;
    }

    public function data(): array
    {
        $this->start();
        return is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    public function replace(array $data): void
    {
        $this->start();
        $_SESSION = $data;
    }

    public function id(): string { $this->start(); return session_id(); }

    public function regenerate(): void
    {
        $this->start();
        $this->call(static function (): void {
            if (!session_regenerate_id(true)) throw new SessionException('Session identity could not be regenerated.');
        }, 'Session identity could not be regenerated.');
    }

    public function invalidate(): void
    {
        $this->start();
        // Rotate first: if native regeneration fails, the old authenticated
        // session and the guard cache must still describe the same state.
        $this->regenerate();
        // Keep the new identity active for code that continues after logout.
        // The old stored ID was removed by session_regenerate_id(true).
        $_SESSION = [];
    }

    public function close(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) return;
        $this->call(static function (): void {
            if (!session_write_close()) throw new SessionException('Session could not be saved.');
        }, 'Session could not be saved.');
        $this->startedByThis = false;
    }

    private function call(callable $operation, string $safeMessage): void
    {
        set_error_handler(static function (): never {
            throw new ErrorException('Session operation failed.');
        });
        try {
            $operation();
        } catch (Throwable $exception) {
            if ($exception instanceof SessionException) throw $exception;
            throw new SessionException($safeMessage, 0, $exception);
        } finally {
            restore_error_handler();
        }
    }
}
