<?php

declare(strict_types=1);

namespace App\View\Compiled;

use Closure;

/**
 * Invalidates only compiled View paths that SqueHub is about to replace or
 * remove. OPcache is optional, including on hosts where its functions exist
 * but the extension is disabled for the current PHP SAPI.
 */
final class OpcacheBridge
{
    private readonly Closure $enabled;
    private readonly Closure $invalidate;

    public function __construct(?callable $enabled = null, ?callable $invalidate = null)
    {
        $this->enabled = $enabled === null
            ? static function (): bool {
                if (!function_exists('opcache_invalidate')
                    || !function_exists('opcache_get_status')) {
                    return false;
                }
                $status = @opcache_get_status(false);
                return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
            }
            : Closure::fromCallable($enabled);
        $this->invalidate = $invalidate === null
            ? static fn (string $path): bool => @opcache_invalidate($path, true)
            : Closure::fromCallable($invalidate);
    }

    public function invalidate(string $path): void
    {
        if (!(($this->enabled)())) {
            return;
        }
        if (!(($this->invalidate)($path))) {
            // A failed invalidation cannot safely be treated as a successful
            // replacement: PHP could still execute bytecode for the old file.
            throw new CompiledViewException('Compiled View OPcache invalidation failed.');
        }
    }
}
