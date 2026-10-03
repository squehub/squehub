<?php

declare(strict_types=1);

namespace App\Security\Csrf;

use App\Config\Repository;
use App\Http\Request;
use App\Http\Response;
use Closure;
use InvalidArgumentException;

/**
 * Enforces synchronizer-token verification before route middleware and code.
 *
 * Only explicit, normalized path exclusions bypass unsafe-request checks.
 * Ambiguous encoded or repeated-separator paths never match exclusions.
 */
final class CsrfMiddleware
{
    private bool $enabled;
    private string $field;
    private string $header;
    /** @var list<string> */
    private array $except;

    public function __construct(Repository $config, private CsrfTokenManager $tokens)
    {
        $enabled = $config->get('csrf.enabled', true);
        $field = $config->get('csrf.field', '_csrf');
        $header = $config->get('csrf.header', 'X-CSRF-Token');
        $except = $config->get('csrf.except', []);
        if (!is_bool($enabled) || !is_string($field)
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $field) !== 1
            || $field === '_token' || !is_string($header)
            || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/", $header) !== 1
            || !is_array($except)) {
            throw new InvalidArgumentException('Invalid CSRF configuration.');
        }
        foreach ($except as $pattern) {
            if (!is_string($pattern) || !self::validPattern($pattern)) {
                throw new InvalidArgumentException('Invalid CSRF exclusion path.');
            }
        }
        $this->enabled = $enabled;
        $this->field = $field;
        $this->header = $header;
        $this->except = array_values($except);
    }

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->enabled || in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            || $this->excluded($request->path())) {
            return $next($request);
        }

        // A present header is authoritative, including an empty or invalid
        // one. Query strings are intentionally ignored because URLs are logged
        // and can leak through browser history and referrer headers.
        $header = $request->header($this->header);
        $body = $request->all();
        $submitted = $header !== null ? $header
            : (array_key_exists($this->field, $body) ? $body[$this->field] : ($body['_token'] ?? null));
        if (!$this->tokens->verify($submitted)) throw new CsrfException();
        return $next($request);
    }

    private function excluded(string $path): bool
    {
        // Router paths are not decoded. Refuse exclusion matching for any
        // ambiguous spelling rather than guessing how downstream servers may
        // canonicalize encoded separators, traversal, or duplicate slashes.
        if (str_contains($path, '%') || str_contains($path, '\\') || str_contains($path, '//')
            || preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x1F\x7F]~', $path)) {
            return false;
        }
        foreach ($this->except as $pattern) {
            if (str_ends_with($pattern, '/*')) {
                $prefix = substr($pattern, 0, -1);
                if (str_starts_with($path, $prefix)) return true;
            } elseif ($path === $pattern) {
                return true;
            }
        }
        return false;
    }

    private static function validPattern(string $pattern): bool
    {
        if ($pattern === '' || $pattern[0] !== '/' || str_contains($pattern, '?')
            || str_contains($pattern, '#') || str_contains($pattern, '%')
            || str_contains($pattern, '\\') || str_contains($pattern, '//')
            || preg_match('~(?:^|/)\.\.?(/|$)|[\x00-\x20\x7F]~', $pattern)
            || ($pattern !== '/' && str_ends_with($pattern, '/') && !str_ends_with($pattern, '/*'))) {
            return false;
        }
        $bare = str_ends_with($pattern, '/*') ? substr($pattern, 0, -2) : $pattern;
        return $bare !== '' && !str_contains($bare, '*')
            && (!str_contains($pattern, '*') || str_ends_with($pattern, '/*'));
    }
}
