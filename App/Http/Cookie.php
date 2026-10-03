<?php

declare(strict_types=1);

namespace App\Http;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Immutable response cookie. Values are opaque strings encoded as URI bytes;
 * no PHP serialization or implicit structured-data conversion takes place.
 */
final readonly class Cookie
{
    private string $sameSite;

    public function __construct(
        private string $name,
        #[\SensitiveParameter] private string $value,
        private ?DateTimeImmutable $expires = null,
        private ?int $maxAge = null,
        private string $path = '/',
        private ?string $domain = null,
        private bool $secure = false,
        private bool $httpOnly = true,
        string $sameSite = 'Lax'
    ) {
        if ($name === '' || strlen($name) > 256
            || preg_match("/\A[!#$%&'*+.^_`|~0-9A-Za-z-]+\z/D", $name) !== 1) {
            throw new InvalidArgumentException('Cookie name is invalid.');
        }
        if (strlen($value) > 4096 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException('Cookie value is invalid.');
        }
        if ($maxAge !== null && ($maxAge < 0 || $maxAge > 2147483647)) {
            throw new InvalidArgumentException('Cookie Max-Age is invalid.');
        }
        if ($path === '' || strlen($path) > 1024 || $path[0] !== '/'
            || preg_match('/[\x00-\x20\x7F;]/', $path) === 1) {
            throw new InvalidArgumentException('Cookie Path is invalid.');
        }
        if ($domain !== null && (strlen($domain) > 253
            || preg_match('/\A[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?'
                . '(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*\z/D', $domain) !== 1)) {
            throw new InvalidArgumentException('Cookie Domain is invalid.');
        }
        $this->sameSite = match (strtolower($sameSite)) {
            'lax' => 'Lax',
            'strict' => 'Strict',
            'none' => 'None',
            default => throw new InvalidArgumentException('Cookie SameSite is invalid.'),
        };
        if ($this->sameSite === 'None' && !$secure) {
            throw new InvalidArgumentException('SameSite=None requires a Secure cookie.');
        }
        // Cookie prefixes are browser-enforced assertions; reject values that
        // would silently fail those assertions before a response is created.
        if (str_starts_with($name, '__Secure-') && !$secure) {
            throw new InvalidArgumentException('__Secure- cookies require Secure.');
        }
        if (str_starts_with($name, '__Host-')
            && (!$secure || $path !== '/' || $domain !== null)) {
            throw new InvalidArgumentException('__Host- cookies require Secure, Path=/, and no Domain.');
        }
    }

    /** A deletion uses the same name and path/domain scope as the original cookie. */
    public static function forget(string $name, string $path = '/', ?string $domain = null,
        bool $secure = false, bool $httpOnly = true, string $sameSite = 'Lax'): self
    {
        return new self($name, '', new DateTimeImmutable('@0'), 0,
            $path, $domain, $secure, $httpOnly, $sameSite);
    }

    public function name(): string { return $this->name; }
    public function value(): string { return $this->value; }
    public function expires(): ?DateTimeImmutable { return $this->expires; }
    public function maxAge(): ?int { return $this->maxAge; }
    public function path(): string { return $this->path; }
    public function domain(): ?string { return $this->domain; }
    public function secure(): bool { return $this->secure; }
    public function httpOnly(): bool { return $this->httpOnly; }
    public function sameSite(): string { return $this->sameSite; }

    /** The emitter writes this as one Set-Cookie field, never as a joined list. */
    public function headerValue(): string
    {
        $header = $this->name . '=' . rawurlencode($this->value);
        if ($this->expires !== null) {
            $header .= '; Expires=' . gmdate('D, d M Y H:i:s', $this->expires->getTimestamp()) . ' GMT';
        }
        if ($this->maxAge !== null) $header .= '; Max-Age=' . $this->maxAge;
        $header .= '; Path=' . $this->path;
        if ($this->domain !== null) $header .= '; Domain=' . strtolower($this->domain);
        if ($this->secure) $header .= '; Secure';
        if ($this->httpOnly) $header .= '; HttpOnly';
        return $header . '; SameSite=' . $this->sameSite;
    }

    /** Prevent accidental value disclosure in diagnostic object dumps. */
    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'value' => '[REDACTED]',
            'path' => $this->path, 'domain' => $this->domain,
            'secure' => $this->secure, 'httpOnly' => $this->httpOnly,
            'sameSite' => $this->sameSite];
    }
}
