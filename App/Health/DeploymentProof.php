<?php

declare(strict_types=1);

namespace App\Health;

use App\Foundation\Application;
use App\Foundation\UrlBasePath;
use App\Storage\StorageManager;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Profile-specific evidence that separates configuration, local reachability,
 * and explicit HTTP behavior. Ordinary inspection reads local metadata only;
 * database/Redis probes and web requests require deliberate options. No check
 * sends Mail, writes Storage, dispatches a job, or runs a migration.
 */
final class DeploymentProof
{
    private const PROFILES = ['shared-hosting', 'single-server', 'worker', 'multi-server'];

    /** @var null|\Closure(string,bool):array{status:int,content_type:string,safe_prefix:bool} */
    private ?\Closure $httpProbe;

    /** @param null|callable(string,bool):array{status:int,content_type:string,safe_prefix:bool} $httpProbe */
    public function __construct(private Application $application, ?callable $httpProbe = null)
    {
        $this->httpProbe = $httpProbe === null ? null : \Closure::fromCallable($httpProbe);
    }

    public function inspect(
        string $profile,
        bool $probeInfrastructure = false,
        ?string $webBaseUrl = null,
        string $assetPath = '/assets/default/favicon/site.webmanifest',
        ?DateTimeImmutable $at = null,
    ): DeploymentEvidence {
        if (!in_array($profile, self::PROFILES, true)) {
            throw new InvalidArgumentException('Deployment profile is unsupported.');
        }
        if ($webBaseUrl !== null && !in_array($profile, ['shared-hosting', 'single-server'], true)) {
            throw new InvalidArgumentException('HTTP proof is unavailable for this profile.');
        }
        if ($webBaseUrl !== null) {
            self::assertWebUrl($webBaseUrl);
            self::assertAssetPath($assetPath);
        }

        $core = new CoreHealthChecks($this->application);
        $config = $this->application->config();
        $runtime = $core->php()->status() === 'pass' && $core->extensions()->status() === 'pass';
        $environment = $core->application()->status() === 'pass'
            && $core->debug()->status() === 'pass';
        $public = is_file($this->application->basePath('public/index.php'))
            && !is_link($this->application->basePath('public/index.php'));
        $configuration = is_dir($this->application->basePath('Config'))
            && !is_link($this->application->basePath('Config'))
            && is_readable($this->application->basePath('Config'));
        $publicRoot = realpath($this->application->basePath('public'));
        $storagePath = $this->application->basePath('Storage');
        $storageRoot = realpath($storagePath);
        $privateStorage = !is_link($storagePath)
            && ($storageRoot === false || $publicRoot === false
                || (!str_starts_with(str_replace('\\', '/', $storageRoot) . '/',
                    rtrim(str_replace('\\', '/', $publicRoot), '/') . '/')
                    && $storageRoot !== $publicRoot));
        $basePathConfigured = false;
        try {
            $configuredMount = $config->get('http.base_path', '');
            if (!is_string($configuredMount)) {
                throw new InvalidArgumentException('HTTP URL base path must be a string.');
            }
            $mount = new UrlBasePath($configuredMount);
            $basePathConfigured = true;
            if ($webBaseUrl !== null) {
                $webPath = rtrim((string) parse_url($webBaseUrl, PHP_URL_PATH), '/');
                if ($webPath !== $mount->value()) {
                    throw new InvalidArgumentException('Deployment HTTP URL does not match the configured base path.');
                }
            }
        } catch (InvalidArgumentException $exception) {
            if ($webBaseUrl !== null) {
                throw $exception;
            }
        }
        $currentKey = $config->get('crypt.current');
        $keys = $config->get('crypt.keys', []);
        $keyConfigured = is_string($currentKey) && is_array($keys)
            && is_string($keys[$currentKey] ?? null) && $keys[$currentKey] !== '';
        $databaseName = $config->get('database.default');
        $databaseDriver = is_string($databaseName)
            ? $config->get('database.connections.' . $databaseName . '.driver') : null;
        $databaseConfigured = in_array($databaseDriver, ['sqlite', 'mysql'], true);
        $sessionConfigured = in_array($config->get('session.driver', 'native'),
            ['native', 'array', 'redis', 'auto'], true);
        $storageName = $config->get('storage.default', 'local');
        $storageDriver = is_string($storageName)
            ? $config->get('storage.drives.' . $storageName . '.driver') : null;
        $storageConfigured = in_array($storageDriver, ['local', 'array', 's3'], true);
        $storageInspection = $storageConfigured
            && $this->application->container()->has(StorageManager::class)
            ? $this->probe($core, 'storage') : null;
        $storageConfigured = $storageConfigured
            && ($storageDriver === 's3' || $storageInspection !== false);

        $checks = [
            'runtime' => self::check($runtime, $runtime, 'local_runtime_checked'),
            'environment' => self::check($environment, $environment, 'local_environment_checked'),
            'public_root' => self::check($public, $public, 'public_entry_inspected'),
            'configuration' => self::check($configuration, $configuration, 'config_directory_inspected'),
            'private_storage' => self::check($privateStorage, null, 'storage_outside_public_root'),
            'base_path' => self::check($basePathConfigured, null, 'url_mount_configuration_inspected'),
            'application_key' => self::check($keyConfigured, null, 'key_presence_only'),
            'database' => self::check($databaseConfigured,
                $probeInfrastructure ? $this->probe($core, 'database') : null,
                $probeInfrastructure ? 'read_only_select_probe' : 'not_probed'),
            'session' => self::check($sessionConfigured,
                null, $probeInfrastructure
                    ? ($this->probe($core, 'session') ? 'session_selection_inspected' : 'session_selection_failed')
                    : 'not_probed'),
            'storage' => self::check($storageConfigured,
                null, $probeInfrastructure
                    ? ($storageInspection === true ? 'storage_selection_inspected'
                        : ($storageInspection === false ? 'storage_selection_failed'
                            : 'storage_selection_unavailable'))
                    : 'not_probed'),
        ];

        if ($profile === 'single-server' || $profile === 'worker' || $profile === 'multi-server') {
            $queueName = $config->get('queue.default', 'sync');
            $queueDriver = is_string($queueName)
                ? $config->get('queue.connections.' . $queueName . '.driver') : null;
            // An auto setting may resolve to a local fallback. It cannot
            // establish a persistent worker backend without selected evidence.
            $persistent = in_array($queueDriver, ['database', 'redis'], true);
            $queueRequired = $profile !== 'single-server';
            $queueConfigured = $queueRequired ? $persistent
                : in_array($queueDriver, ['sync', 'database', 'redis', 'auto'], true);
            $checks['queue'] = self::check($queueConfigured,
                $probeInfrastructure && $persistent ? $this->probe($core, 'queue') : null,
                $queueDriver === 'sync' && !$queueRequired ? 'optional_sync_queue'
                    : ($probeInfrastructure ? 'backend_probe_not_worker_proof' : 'not_probed'));
        }

        if ($profile === 'worker' || $profile === 'multi-server') {
            $command = is_file($this->application->basePath('squehub'));
            $checks['worker_command'] = self::check($command, $command, 'command_file_inspected');
            $checks['scheduler_command'] = self::check($command, $command, 'command_file_inspected');
            $checks['worker_running'] = self::check(true, null, 'external_worker_evidence_required');
        }

        if ($profile === 'multi-server') {
            $checks['shared_session'] = self::check($sessionConfigured
                && $config->get('session.driver') === 'redis', null,
                'remote_nodes_unverified');
            $checks['shared_queue'] = self::check(isset($checks['queue'])
                && $checks['queue']['configured'], null, 'remote_nodes_unverified');
            $checks['shared_cache'] = self::check(in_array($config->get('cache.driver'),
                ['redis', 'memcached'], true), null, 'remote_nodes_unverified');
            $checks['shared_rate_limit'] = self::check($config->get('rateLimit.store') === 'redis',
                null, 'remote_nodes_unverified');
            $checks['shared_storage'] = self::check($storageDriver === 's3', null,
                'remote_nodes_unverified');
            $checks['key_consistency'] = self::check(true, null, 'remote_node_fingerprint_required');
            $checks['deployment_version'] = self::check(true, null, 'remote_node_evidence_required');
            $checks['scheduler_ownership'] = self::check(true, null, 'external_policy_required');
        }

        if ($webBaseUrl !== null) {
            $checks['web'] = $this->webProof($webBaseUrl, $assetPath);
        } elseif ($profile === 'shared-hosting' || $profile === 'single-server') {
            $checks['web'] = self::check(true, null, 'http_proof_not_requested');
        }

        return new DeploymentEvidence($profile,
            $at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')), $checks);
    }

    /** @return array{configured:bool,reachable:?bool,end_to_end_verified:?bool,reason:string} */
    private static function check(bool $configured, ?bool $reachable, string $reason,
        ?bool $endToEnd = null): array
    {
        return ['configured' => $configured, 'reachable' => $reachable,
            'end_to_end_verified' => $endToEnd, 'reason' => $reason];
    }

    private function probe(CoreHealthChecks $core, string $name): bool
    {
        try {
            $result = match ($name) {
                'database' => $core->database(), 'session' => $core->session(),
                'storage' => $core->storage(), 'queue' => $core->queue(),
                default => throw new InvalidArgumentException('Deployment probe is unsupported.'),
            };
            return $result->status() === 'pass';
        } catch (Throwable) {
            // Driver exceptions may carry DSNs or credentials; evidence only
            // records that the bounded probe did not establish reachability.
            return false;
        }
    }

    /**
     * Explicit HTTP verification checks ordinary public behavior without
     * following redirects or touching a write endpoint. It cannot prove that
     * every application route or another node works.
     *
     * @return array{configured:bool,reachable:?bool,end_to_end_verified:?bool,reason:string}
     */
    private function webProof(string $baseUrl, string $assetPath): array
    {
        $base = rtrim($baseUrl, '/');
        $root = $this->request($base . '/', false);
        $asset = $this->request($base . $assetPath, false);
        $missing = $this->request($base . '/squehub-phase21-missing', false);
        $api = $this->request($base . '/api/squehub-phase21-missing', true);
        $private = $this->request($base . '/.env', false);
        $cliSource = $this->request($base . '/squehub', false);
        $frameworkSource = $this->request($base . '/App/Core/View.php', false);
        $reachable = $root['status'] > 0;
        $verified = $root['status'] === 200 && $asset['status'] === 200
            && !str_starts_with(strtolower($asset['content_type']), 'text/html')
            && $missing['status'] === 404 && $api['status'] === 404
            && str_starts_with(strtolower($api['content_type']), 'application/json')
            && in_array($private['status'], [403, 404], true)
            && in_array($cliSource['status'], [403, 404], true)
            && in_array($frameworkSource['status'], [403, 404], true)
            && $root['safe_prefix'] && $asset['safe_prefix']
            && $missing['safe_prefix'] && $api['safe_prefix']
            && $private['safe_prefix'] && $cliSource['safe_prefix']
            && $frameworkSource['safe_prefix'];
        return self::check(true, $reachable, $verified ? 'bounded_http_paths_verified'
            : 'bounded_http_paths_unverified', $verified);
    }

    /** @return array{status:int,content_type:string,safe_prefix:bool} */
    private function request(string $url, bool $json): array
    {
        if ($this->httpProbe !== null) {
            try { return ($this->httpProbe)($url, $json); }
            catch (Throwable) { return ['status' => 0, 'content_type' => '', 'safe_prefix' => false]; }
        }
        $context = stream_context_create(['http' => [
            'method' => 'GET', 'timeout' => 3, 'follow_location' => 0,
            'ignore_errors' => true,
            'header' => $json ? "Accept: application/json\r\n" : "Accept: text/html\r\n",
        ]]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) return ['status' => 0, 'content_type' => '', 'safe_prefix' => false];
        try {
            // Inspect a bounded response prefix for obvious debug/secret leaks,
            // then discard it. This sample is not a full content audit.
            $prefix = @stream_get_contents($stream, 8192);
            $metadata = stream_get_meta_data($stream);
        } finally {
            fclose($stream);
        }
        $status = 0;
        $type = '';
        foreach (($metadata['wrapper_data'] ?? []) as $header) {
            if (!is_string($header)) continue;
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', $header, $match) === 1) {
                $status = (int) $match[1];
            } elseif (stripos($header, 'Content-Type:') === 0) {
                $type = trim(substr($header, strlen('Content-Type:')));
            }
        }
        return ['status' => $status, 'content_type' => $type,
            'safe_prefix' => is_string($prefix)
                && preg_match('/\b(?:APP_KEY|DB_PASSWORD|Stack trace|Fatal error|DEBUG MODE)\b/i', $prefix) !== 1];
    }

    private static function assertWebUrl(string $url): void
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            throw new InvalidArgumentException('Deployment HTTP URL is invalid.');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || str_contains($parts['path'] ?? '', '..')
            || preg_match('~%2f|%5c|%00~i', $parts['path'] ?? '') === 1) {
            throw new InvalidArgumentException('Deployment HTTP URL is invalid.');
        }
    }

    private static function assertAssetPath(string $path): void
    {
        if (!str_starts_with($path, '/assets/') || strlen($path) > 512
            || preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1
            || str_contains($path, '..') || str_contains($path, '?')
            || str_contains($path, '#') || preg_match('~%2f|%5c|%00~i', $path) === 1) {
            throw new InvalidArgumentException('Deployment asset path is invalid.');
        }
    }
}
