<?php

declare(strict_types=1);

namespace App\Support;

use App\Config\Repository;
use App\Http\Request;

/** Redacts configured credentials from diagnostic text without exposing config. */
final class SecretRedactor
{
    public function __construct(private Repository $config)
    {
    }

    /**
     * The caller may add sensitive context values; longer secrets are replaced
     * first so overlapping values cannot leave a meaningful suffix behind.
     * Trace arguments and request bodies are never examined or serialized.
     *
     * @param list<string> $additional
     */
    public function redact(string $text, ?Request $request = null, array $additional = []): string
    {
        $secrets = $additional;
        foreach (['database.password', 'mail.password'] as $key) {
            $secrets[] = $this->config->get($key);
        }
        $cryptKeys = $this->config->get('crypt.keys', []);
        if (is_array($cryptKeys)) {
            foreach ($cryptKeys as $key) $secrets[] = $key;
        }
        $mailTransports = $this->config->get('mail.transports', []);
        if (is_array($mailTransports)) {
            foreach ($mailTransports as $transport) {
                if (is_array($transport)) {
                    $secrets[] = $transport['password'] ?? null;
                    $secrets[] = $transport['username'] ?? null;
                    $secrets[] = $transport['api_key'] ?? null;
                }
            }
        }
        // S3 credentials belong to the selected Storage configuration, not
        // the Mail/Redis secret sets. A provider failure can quote them even
        // when no request supplied an Authorization header.
        $storageDrives = $this->config->get('storage.drives', []);
        if (is_array($storageDrives)) {
            foreach ($storageDrives as $drive) {
                if (!is_array($drive)) continue;
                $secrets[] = $drive['access_key'] ?? null;
                $secrets[] = $drive['secret_key'] ?? null;
                $secrets[] = $drive['session_token'] ?? null;
            }
        }
        $secrets[] = $this->config->get('cache.memcached.password');
        // OIDC client secrets are configured per named provider. Keep them
        // out of exception pages and structured logs even in debug mode.
        $oauthProviders = $this->config->get('oauth.providers', []);
        if (is_array($oauthProviders)) {
            foreach ($oauthProviders as $provider) {
                if (is_array($provider)) $secrets[] = $provider['client_secret'] ?? null;
            }
        }
        // Webhook peers use their own signing credentials, independent of the
        // application Crypt key. Never print either active or rotation keys.
        foreach (['webhooks.endpoints', 'webhooks.sources'] as $path) {
            $peers = $this->config->get($path, []);
            if (!is_array($peers)) continue;
            foreach ($peers as $peer) {
                if (!is_array($peer)) continue;
                $secrets[] = $peer['secret'] ?? null;
                if (is_array($peer['previous_secrets'] ?? null)) {
                    foreach ($peer['previous_secrets'] as $previous) {
                        if (is_array($previous)) $secrets[] = $previous['secret'] ?? null;
                        else $secrets[] = $previous;
                    }
                }
            }
        }
        $connections = $this->config->get('database.connections', []);
        if (is_array($connections)) {
            foreach ($connections as $connection) {
                if (is_array($connection)) $secrets[] = $connection['password'] ?? null;
            }
        }
        // Redis URLs can contain ACL credentials. Include the complete URL and
        // decoded components so provider errors and debug text are redacted
        // without printing or retaining connection metadata in diagnostics.
        $redisConnections = $this->config->get('redis.connections', []);
        if (is_array($redisConnections)) {
            foreach ($redisConnections as $connection) {
                if (!is_array($connection)) continue;
                $secrets[] = $connection['password'] ?? null;
                $secrets[] = $connection['username'] ?? null;
                $url = $connection['url'] ?? null;
                if (!is_string($url) || $url === '') continue;
                $secrets[] = $url;
                try { $parts = parse_url($url); } catch (\ValueError) { $parts = false; }
                if (!is_array($parts)) continue;
                if (isset($parts['user'])) $secrets[] = rawurldecode($parts['user']);
                if (isset($parts['pass'])) $secrets[] = rawurldecode($parts['pass']);
            }
        }
        foreach ($_ENV as $key => $value) {
            if (SensitiveKey::matches((string) $key)) $secrets[] = $value;
        }
        if ($request !== null) {
            $secrets[] = $request->header('Authorization');
            $secrets[] = $request->bearerToken();
            $secrets[] = $request->header('SqueHub-Webhook-Signature');
        }
        // Even a short configured credential must not appear in a debug page
        // or log. It may mask more surrounding text, which is safer than leak.
        $secrets = array_values(array_unique(array_filter($secrets,
            static fn (mixed $value): bool => is_string($value) && $value !== '')));
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));
        $redacted = str_replace($secrets, '[REDACTED]', $text);
        // Distinctive credential formats can appear in a free-form message
        // before they were supplied as structured context. Six-digit TOTP
        // alone has no safe global pattern, so callers must use sensitive keys.
        $redacted = preg_replace([
            '/sqh_pat_[A-Za-z0-9_-]{8,}/',
            '/shs1\.[A-Za-z0-9_-]{20,256}/',
            '/(?<![A-Fa-f0-9])(?:[A-Fa-f0-9]{4}-){7}[A-Fa-f0-9]{4}(?![A-Fa-f0-9])/',
            '~otpauth://\S+~i',
        ], '[REDACTED]', $redacted) ?? '[REDACTED]';
        return preg_replace('~sqh_signature=[^&#\s<>"\']+~i', 'sqh_signature=[REDACTED]', $redacted)
            ?? '[REDACTED]';
    }
}
