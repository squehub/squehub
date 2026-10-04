<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\Repository;
use InvalidArgumentException;

/** Validated, Application-owned browser headers applied at the Kernel response boundary. */
final class BrowserSecurityPolicy
{
    private bool $enabled;
    private ?string $csp = null;
    private bool $hasFrameAncestors = false;
    private bool $hstsEnabled;
    private string $hsts;
    private ?string $referrerPolicy;
    private ?string $frameOptions;
    private bool $nosniff;

    public function __construct(Repository $config)
    {
        $settings = $config->get('security.browser', []);
        if (!is_array($settings) || !self::keys($settings,
            ['enabled', 'csp', 'hsts', 'referrer_policy', 'frame_options', 'nosniff'])) {
            throw new InvalidArgumentException('Browser security policy configuration is invalid.');
        }
        $enabled = array_key_exists('enabled', $settings) ? $settings['enabled'] : false;
        $nosniff = array_key_exists('nosniff', $settings) ? $settings['nosniff'] : true;
        $referrer = array_key_exists('referrer_policy', $settings)
            ? $settings['referrer_policy'] : 'strict-origin-when-cross-origin';
        $frame = $settings['frame_options'] ?? null;
        if (!is_bool($enabled) || !is_bool($nosniff)
            || ($referrer !== null && (!is_string($referrer)
                || !in_array($referrer, self::referrerValues(), true)))
            || ($frame !== null && !in_array($frame, ['DENY', 'SAMEORIGIN'], true))) {
            throw new InvalidArgumentException('Browser security policy configuration is invalid.');
        }
        $this->enabled = $enabled;
        $this->nosniff = $nosniff;
        $this->referrerPolicy = $referrer;
        $this->frameOptions = $frame;

        $csp = array_key_exists('csp', $settings) ? $settings['csp'] : [];
        if (!is_array($csp) || !self::keys($csp, ['directives'])) {
            throw new InvalidArgumentException('CSP configuration is invalid.');
        }
        $directives = array_key_exists('directives', $csp) ? $csp['directives'] : [];
        if (!is_array($directives) || count($directives) > 32) {
            throw new InvalidArgumentException('CSP directives are invalid.');
        }
        $rendered = [];
        foreach ($directives as $name => $sources) {
            if (!is_string($name) || preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $name) !== 1
                || !is_array($sources) || !array_is_list($sources) || count($sources) > 32) {
                throw new InvalidArgumentException('CSP directive is invalid.');
            }
            foreach ($sources as $source) {
                // One token per entry: no whitespace or semicolon can smuggle
                // another directive into the final header value.
                if (!is_string($source) || strlen($source) > 255
                    || preg_match('/\A[\x21-\x3A\x3C-\x7E]+\z/D', $source) !== 1
                    || str_starts_with($source, "'nonce-")) {
                    throw new InvalidArgumentException('CSP source is invalid.');
                }
            }
            if (in_array("'none'", $sources, true) && count($sources) !== 1) {
                throw new InvalidArgumentException('CSP none source must stand alone.');
            }
            if ($name === 'frame-ancestors') {
                $this->hasFrameAncestors = true;
                if ($sources === [] || ($frame === 'DENY' && $sources !== ["'none'"])
                    || ($frame === 'SAMEORIGIN' && $sources !== ["'self'"])) {
                    throw new InvalidArgumentException('CSP frame ancestors and X-Frame-Options conflict.');
                }
            }
            $rendered[] = $name . ($sources === [] ? '' : ' ' . implode(' ', $sources));
        }
        if ($rendered !== []) {
            $header = implode('; ', $rendered);
            if (strlen($header) > 8192) throw new InvalidArgumentException('CSP header is too long.');
            $this->csp = $header;
        }

        $hsts = array_key_exists('hsts', $settings) ? $settings['hsts'] : [];
        if (!is_array($hsts) || !self::keys($hsts,
            ['enabled', 'max_age', 'include_subdomains', 'preload'])) {
            throw new InvalidArgumentException('HSTS configuration is invalid.');
        }
        $hstsEnabled = array_key_exists('enabled', $hsts) ? $hsts['enabled'] : false;
        $maxAge = array_key_exists('max_age', $hsts) ? $hsts['max_age'] : 31536000;
        $subdomains = array_key_exists('include_subdomains', $hsts)
            ? $hsts['include_subdomains'] : false;
        $preload = array_key_exists('preload', $hsts) ? $hsts['preload'] : false;
        if (!is_bool($hstsEnabled) || !is_int($maxAge) || $maxAge < 0 || $maxAge > 63072000
            || !is_bool($subdomains) || !is_bool($preload)
            || ($preload && (!$hstsEnabled || !$subdomains || $maxAge < 31536000))) {
            throw new InvalidArgumentException('HSTS configuration is invalid.');
        }
        $this->hstsEnabled = $hstsEnabled;
        $this->hsts = 'max-age=' . $maxAge
            . ($subdomains ? '; includeSubDomains' : '') . ($preload ? '; preload' : '');
    }

    public function enabled(): bool { return $this->enabled; }
    public function cspEnabled(): bool { return $this->enabled && $this->csp !== null; }
    public function hstsEnabled(): bool { return $this->enabled && $this->hstsEnabled; }

    /** Header replacement is authoritative only for configured policy fields. */
    public function decorate(Request $request, Response $response, bool $api = false): Response
    {
        if (!$this->enabled) return $response;
        if ($this->hstsEnabled) {
            $response = $request->scheme() === 'https'
                ? $response->withHeader('Strict-Transport-Security', $this->hsts)
                : $response->withoutHeader('Strict-Transport-Security');
        }
        $existingReferrer = $response->header('Referrer-Policy');
        if ($this->referrerPolicy !== null && ($existingReferrer === null
            || strcasecmp(trim($existingReferrer), 'no-referrer') !== 0)) {
            $response = $response->withHeader('Referrer-Policy', $this->referrerPolicy);
        }
        if ($this->nosniff) $response = $response->withHeader('X-Content-Type-Options', 'nosniff');

        if ($this->isHtml($response, $api)) {
            if ($this->csp !== null) $response = $response->withHeader('Content-Security-Policy', $this->csp);
            if ($this->frameOptions !== null) {
                $response = $response->withHeader('X-Frame-Options', $this->frameOptions);
            } elseif ($this->hasFrameAncestors) {
                // A controller's legacy frame header may contradict the
                // configured frame-ancestors directive on this HTML response.
                $response = $response->withoutHeader('X-Frame-Options');
            }
        }
        return $response;
    }

    private function isHtml(Response $response, bool $api): bool
    {
        if ($response->status() >= 300 && $response->status() < 400) return false;
        if (in_array($response->status(), [204, 205, 304], true)) return false;
        $disposition = $response->header('Content-Disposition');
        if ($disposition !== null && preg_match('/\A\s*attachment(?:\s*;|\s*\z)/i', $disposition) === 1) {
            return false;
        }
        $type = $response->header('Content-Type');
        if ($type === null) return !$api && !$response->hasDeferredBody();
        $type = strtolower(trim(explode(';', $type, 2)[0]));
        return in_array($type, ['text/html', 'application/xhtml+xml'], true);
    }

    /** @param array<array-key,mixed> $settings @param list<string> $allowed */
    private static function keys(array $settings, array $allowed): bool
    {
        return array_diff(array_keys($settings), $allowed) === [];
    }

    /** @return list<string> */
    private static function referrerValues(): array
    {
        return ['no-referrer', 'no-referrer-when-downgrade', 'origin', 'origin-when-cross-origin',
            'same-origin', 'strict-origin', 'strict-origin-when-cross-origin', 'unsafe-url'];
    }
}
