<?php

declare(strict_types=1);

namespace App\Config;

use App\Changes\ChangeAction;
use App\Changes\ChangeApplyException;
use App\Changes\ChangePlan;
use App\Changes\ChangePlanMetadata;
use App\Changes\ChangeResult;
use App\Contributions\ContributionOwner;
use App\Foundation\Environment;
use Closure;
use JsonException;
use LogicException;
use Throwable;
use WeakMap;

/**
 * Caches only the base Config/*.php layer, before Package providers contribute
 * live namespaced defaults. Cached values can contain credentials and remain
 * private runtime data; no value is returned by status or error messages.
 */
final class ConfigCache
{
    private const FORMAT_VERSION = 1;
    private const MAX_BYTES = 8388608;
    private const FILENAME = 'Config.json';
    private const LOCK_FILENAME = 'Config.lock';

    private readonly string $basePath;

    /** @var WeakMap<ChangePlan,array{directory:string,identity:string,artifact:?string}> */
    private WeakMap $planned;

    public function __construct(string $basePath)
    {
        $physical = realpath($basePath);
        if ($physical === false || !is_dir($physical)) {
            throw new ConfigurationException('Configuration cache application root is unavailable.');
        }
        $this->basePath = rtrim(str_replace('\\', '/', $physical), '/');
        $this->planned = new WeakMap();
    }

    /**
     * Inspect source and artifact fingerprints without evaluating Config PHP,
     * reading cached values, or creating private Storage directories.
     */
    public function planBuild(string $configDirectory, Environment $environment): ChangePlan
    {
        try {
            $identity = $this->identity($configDirectory, $environment);
        } catch (LogicException $exception) {
            throw new ConfigurationException($exception->getMessage(), 0, $exception);
        }
        $directory = realpath($configDirectory);
        if ($directory === false) {
            throw new ConfigurationException('Configuration cache source directory is unavailable.');
        }
        $before = $this->artifactFingerprint();
        $sourceHash = hash('sha256', self::encode($identity['sources']));
        $owner = new ContributionOwner('framework', 'SqueHub');
        $subject = 'Storage/Cache/Framework/' . self::FILENAME;
        $action = new ChangeAction($before === null ? 'create' : 'modify', $subject,
            $owner, $before, null, $before === null ? 'low' : 'review',
            'Publish private base configuration cache.', 'configuration');
        // The full identity includes effective environment values and remains
        // private to this instance. Only source-file hashes enter plan output.
        $plan = new ChangePlan('config:cache', 'Config', $owner, [$action], [], [],
            ['Config#sources' => $sourceHash, $subject => $before],
            new ChangePlanMetadata('configuration', $sourceHash,
                ['sensitive_persistence'], [], ['file_checksum', 'path_presence']));
        $this->planned[$plan] = [
            'directory' => str_replace('\\', '/', $directory),
            'identity' => $identity['fingerprint'], 'artifact' => $before,
        ];
        return $plan;
    }

    /**
     * Apply only a plan produced by this cache instance. The source and
     * artifact are checked before evaluation and again under the publish lock
     * so a changed preview cannot silently overwrite another cache build.
     *
     * @return array{fingerprint:string,created_at:int,files:int,change:ChangeResult}
     */
    public function applyBuild(ChangePlan $plan, string $configDirectory,
        Environment $environment): array
    {
        $expected = $this->planned[$plan] ?? null;
        $directory = realpath($configDirectory);
        if ($expected === null || $plan->operation !== 'config:cache' || $plan->hasConflicts()
            || $directory === false
            || str_replace('\\', '/', $directory) !== $expected['directory']) {
            throw new ConfigurationException('Configuration cache plan is unavailable or stale.');
        }
        $guard = function () use ($configDirectory, $environment, $expected): void {
            try {
                $identity = $this->identity($configDirectory, $environment);
            } catch (LogicException $exception) {
                throw new ConfigurationException('Configuration cache plan is stale.', 0, $exception);
            }
            if ($identity['fingerprint'] !== $expected['identity']
                || $this->artifactFingerprint() !== $expected['artifact']) {
                throw new ConfigurationException('Configuration cache plan is stale.');
            }
        };
        $guard();
        $report = $this->buildInternal($configDirectory, $environment, $guard);
        try {
            $published = $this->read();
            if ($published === null || $published['fingerprint'] !== $report['fingerprint']) {
                throw new ConfigurationException('Configuration cache publication could not be verified.');
            }
        } catch (Throwable $exception) {
            // The rename may already be visible. Report the observed publish
            // as partial rather than claiming a rollback or verified result.
            throw new ChangeApplyException(new ChangeResult($plan, $plan->actions,
                null, [], false, 'Storage/Cache/Framework/' . self::FILENAME), $exception);
        }
        return $report + ['change' => new ChangeResult($plan, $plan->actions, null, [], true)];
    }

    /**
     * A missing or stale artifact takes the ordinary Loader path. A corrupt
     * active artifact is an operational error until config:clear removes it.
     */
    public function load(string $configDirectory, Environment $environment, Repository $repository): bool
    {
        $artifact = $this->read();
        if ($artifact === null) {
            return false;
        }
        try {
            $identity = $this->identity($configDirectory, $environment);
        } catch (LogicException) {
            // A newly dynamic config source cannot use an old cache, but the
            // ordinary PHP Loader remains available for that application.
            return false;
        }
        if ($artifact['fingerprint'] !== $identity['fingerprint']) {
            return false;
        }
        foreach ($artifact['values'] as $name => $values) {
            $repository->set($name, $values);
        }
        return true;
    }

    /**
     * Evaluate Config once into a fresh Repository. Taking source/environment
     * fingerprints both before and after rejects a concurrent edit rather than
     * publishing values under an identity they never represented.
     *
     * @return array{fingerprint:string,created_at:int,files:int}
     */
    public function build(string $configDirectory, Environment $environment): array
    {
        return $this->buildInternal($configDirectory, $environment);
    }

    /** @return array{fingerprint:string,created_at:int,files:int} */
    private function buildInternal(string $configDirectory, Environment $environment,
        ?Closure $beforePublish = null): array
    {
        try {
            $before = $this->identity($configDirectory, $environment);
        } catch (LogicException $exception) {
            throw new ConfigurationException($exception->getMessage(), 0, $exception);
        }
        $repository = new Repository();
        (new Loader())->load($configDirectory, $environment, $repository);
        try {
            $after = $this->identity($configDirectory, $environment);
        } catch (LogicException $exception) {
            throw new ConfigurationException($exception->getMessage(), 0, $exception);
        }
        if ($before !== $after) {
            throw new ConfigurationException('Configuration changed while its cache was being built.');
        }
        $values = $repository->all();
        self::validateValues($values);
        $createdAt = time();
        $body = [
            'version' => self::FORMAT_VERSION,
            'build' => $after['build'],
            'sources' => $after['sources'],
            'environment' => $after['environment'],
            'fingerprint' => $after['fingerprint'],
            'created_at' => $createdAt,
            'values' => $values,
        ];
        $body['checksum'] = hash('sha256', self::encode($body));
        $json = self::encode($body) . "\n";
        if (strlen($json) > self::MAX_BYTES) {
            throw new ConfigurationException('Configuration cache exceeds its size limit.');
        }
        $this->publish($json, $beforePublish);
        return ['fingerprint' => $after['fingerprint'], 'created_at' => $createdAt,
            'files' => count($after['sources'])];
    }

    /** Remove only the framework-owned config artifact, never application data cache. */
    public function clear(): bool
    {
        $root = $this->root(false);
        if ($root === null) {
            return false;
        }
        return $this->withLock($root, function () use ($root): bool {
            $path = $root . '/' . self::FILENAME;
            $this->assertFileEntry($path);
            if (!file_exists($path)) {
                return false;
            }
            if (!@unlink($path)) {
                throw new ConfigurationException('Configuration cache could not be cleared.');
            }
            return true;
        });
    }

    /**
     * Studio/Diagnostics can inspect provenance without receiving config or
     * environment values. An invalid artifact is reported, never decoded into
     * a public response.
     *
     * @return array{active:bool,status:string,created_at:?int,files:int}
     */
    public function status(string $configDirectory, Environment $environment): array
    {
        try {
            $artifact = $this->read();
            if ($artifact === null) {
                return ['active' => false, 'status' => 'missing', 'created_at' => null,
                    'files' => 0];
            }
            $identity = $this->identity($configDirectory, $environment);
            $current = $artifact['fingerprint'] === $identity['fingerprint'];
            return ['active' => $current, 'status' => $current ? 'current' : 'stale',
                'created_at' => $artifact['created_at'], 'files' => count($artifact['sources'])];
        } catch (LogicException) {
            return ['active' => false, 'status' => 'unsupported', 'created_at' => null,
                'files' => 0];
        } catch (ConfigurationException) {
            return ['active' => false, 'status' => 'corrupt', 'created_at' => null,
                'files' => 0];
        }
    }

    /**
     * @return array{build:string,sources:list<array{file:string,sha256:string}>,environment:string,fingerprint:string}
     */
    private function identity(string $directory, Environment $environment): array
    {
        if (!is_dir($directory) || !is_readable($directory)) {
            throw new ConfigurationException('Application config directory is missing or unreadable.');
        }
        $physical = realpath($directory);
        $normalized = $physical === false ? false : str_replace('\\', '/', $physical);
        if ($normalized === false || dirname($normalized) !== $this->basePath
            || !in_array(basename($normalized), ['Config', 'config'], true)
            || is_link($directory)) {
            throw new ConfigurationException('Configuration cache source directory is unsafe.');
        }
        $files = glob(rtrim($directory, '/\\') . '/*.php');
        if ($files === false || count($files) > 1024) {
            throw new ConfigurationException('Application config directory could not be scanned.');
        }
        sort($files, SORT_STRING);
        $sources = [];
        $keys = [];
        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $name = $filename === 'OAuth' ? 'oauth' : lcfirst($filename);
            if ($name === 'debug' || preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) !== 1) {
                continue;
            }
            if (is_link($file) || !is_file($file) || !is_readable($file)) {
                throw new ConfigurationException('Configuration source is unavailable.');
            }
            $source = @file_get_contents($file);
            if (!is_string($source)) {
                throw new ConfigurationException('Configuration source is unavailable.');
            }
            $sources[] = ['file' => basename($file), 'sha256' => hash('sha256', $source)];
            foreach (self::environmentKeys($source) as $key) {
                $keys[$key] = true;
            }
        }
        ksort($keys, SORT_STRING);
        $effective = [];
        foreach (array_keys($keys) as $key) {
            $value = $environment->get($key);
            if ($value !== null && !is_scalar($value)) {
                throw new ConfigurationException('Configuration environment value is invalid.');
            }
            $effective[$key] = $value;
        }
        $dotenv = $this->basePath . '/.env';
        if (file_exists($dotenv) || is_link($dotenv)) {
            if (!is_file($dotenv) || !is_readable($dotenv)) {
                throw new ConfigurationException('Application environment file is unavailable.');
            }
            $dotenvHash = @hash_file('sha256', $dotenv);
            if (!is_string($dotenvHash)) {
                throw new ConfigurationException('Application environment file is unavailable.');
            }
        } else {
            $dotenvHash = 'absent';
        }
        $environmentHash = hash('sha256', self::encode([$dotenvHash, $effective]));
        $build = $this->buildIdentity();
        return ['build' => $build, 'sources' => $sources, 'environment' => $environmentHash,
            'fingerprint' => hash('sha256', self::encode([$build, $sources, $environmentHash]))];
    }

    /**
     * Only literal Environment keys have a deterministic cache dependency.
     * Direct superglobal reads and dynamic keys cannot be proven fresh, so
     * config:cache rejects them while ordinary uncached loading still works.
     *
     * @return list<string>
     */
    private static function environmentKeys(string $source): array
    {
        $tokens = token_get_all($source);
        $keys = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_VARIABLE
                && in_array($token[1], ['$_ENV', '$_SERVER'], true)) {
                throw new LogicException('Configuration cache cannot track direct environment globals.');
            }
            if (is_array($token) && $token[0] === T_STRING
                && strtolower($token[1]) === 'getenv') {
                $next = self::nextToken($tokens, $i + 1);
                if ($next === '(') {
                    throw new LogicException('Configuration cache cannot track direct environment reads.');
                }
            }
            if (!is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== '$environment') {
                continue;
            }
            $operator = self::nextToken($tokens, $i + 1, $j);
            if ($operator !== '->') {
                continue;
            }
            $method = self::nextToken($tokens, $j + 1, $j);
            if (!in_array($method, ['get', 'boolean'], true)
                || self::nextToken($tokens, $j + 1, $j) !== '(') {
                continue;
            }
            $literal = self::nextToken($tokens, $j + 1);
            if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING
                || preg_match('/\A[\'\"]([A-Z][A-Z0-9_]*)[\'\"]\z/D', $literal[1], $matches) !== 1) {
                throw new LogicException('Configuration cache requires literal environment keys.');
            }
            $keys[$matches[1]] = true;
        }
        return array_keys($keys);
    }

    /** @param array<int, array{int,string,int}|string> $tokens */
    private static function nextToken(array $tokens, int $offset, ?int &$index = null): array|string|null
    {
        for ($i = $offset, $count = count($tokens); $i < $count; ++$i) {
            $token = $tokens[$i];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $index = $i;
            return is_array($token) && $token[0] === T_STRING ? $token[1]
                : (is_array($token) && $token[0] === T_OBJECT_OPERATOR ? '->' : $token);
        }
        return null;
    }

    private function buildIdentity(): string
    {
        $files = [__FILE__, __DIR__ . '/Loader.php', __DIR__ . '/Repository.php',
            __DIR__ . '/../Foundation/Environment.php', $this->basePath . '/composer.json',
            $this->basePath . '/composer.lock'];
        $hashes = [];
        foreach ($files as $file) {
            $hash = is_file($file) ? @hash_file('sha256', $file) : 'absent';
            if (!is_string($hash)) {
                throw new ConfigurationException('Configuration cache build identity is unavailable.');
            }
            $hashes[] = $hash;
        }
        return hash('sha256', self::encode($hashes));
    }

    /** @param array<mixed> $values */
    private static function validateValues(array $values): void
    {
        foreach ($values as $name => $value) {
            if (!is_string($name) || !is_array($value)) {
                throw new ConfigurationException('Configuration cache requires array values.');
            }
        }
        try {
            $json = self::encode($values);
            if (json_decode($json, true, 64, JSON_THROW_ON_ERROR) !== $values) {
                throw new ConfigurationException('Configuration cache requires JSON-compatible values.');
            }
        } catch (JsonException $exception) {
            throw new ConfigurationException('Configuration cache requires JSON-compatible values.', 0, $exception);
        }
    }

    /** @param mixed $value */
    private static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION, 64);
        } catch (JsonException $exception) {
            throw new ConfigurationException('Configuration cache data could not be encoded.', 0, $exception);
        }
    }

    /** @return array{version:int,build:string,sources:array,environment:string,fingerprint:string,created_at:int,values:array,checksum:string}|null */
    private function read(): ?array
    {
        $root = $this->root(false);
        if ($root === null) {
            return null;
        }
        $path = $root . '/' . self::FILENAME;
        $this->assertFileEntry($path);
        if (!file_exists($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size < 2 || $size > self::MAX_BYTES) {
            throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.');
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            throw new ConfigurationException('Configuration cache artifact is unavailable. Run config:clear.');
        }
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.', 0, $exception);
        }
        if (!is_array($decoded) || array_keys($decoded) !== [
            'version', 'build', 'sources', 'environment', 'fingerprint',
            'created_at', 'values', 'checksum',
        ] || $decoded['version'] !== self::FORMAT_VERSION
            || !is_string($decoded['build']) || !is_array($decoded['sources'])
            || !is_string($decoded['environment']) || !is_string($decoded['fingerprint'])
            || !is_int($decoded['created_at']) || !is_array($decoded['values'])
            || !is_string($decoded['checksum'])
            || !self::digest($decoded['build']) || !self::digest($decoded['environment'])
            || !self::digest($decoded['fingerprint']) || !self::digest($decoded['checksum'])) {
            throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.');
        }
        self::validateSources($decoded['sources']);
        $checksum = $decoded['checksum'];
        unset($decoded['checksum']);
        if (!hash_equals($checksum, hash('sha256', self::encode($decoded)))) {
            throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.');
        }
        self::validateValues($decoded['values']);
        return $decoded + ['checksum' => $checksum];
    }

    private static function digest(string $value): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** @param array<mixed> $sources */
    private static function validateSources(array $sources): void
    {
        if (!array_is_list($sources) || count($sources) > 1024) {
            throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.');
        }
        $seen = [];
        foreach ($sources as $source) {
            if (!is_array($source) || array_keys($source) !== ['file', 'sha256']
                || !is_string($source['file'])
                || preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\.php\z/D', $source['file']) !== 1
                || !is_string($source['sha256']) || !self::digest($source['sha256'])
                || isset($seen[$source['file']])) {
                throw new ConfigurationException('Configuration cache artifact is invalid. Run config:clear.');
            }
            $seen[$source['file']] = true;
        }
    }

    /** Hash the existing private artifact without reading cached values. */
    private function artifactFingerprint(): ?string
    {
        $root = $this->root(false);
        if ($root === null) {
            return null;
        }
        $path = $root . '/' . self::FILENAME;
        $this->assertFileEntry($path);
        if (!file_exists($path)) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new ConfigurationException('Configuration cache artifact cannot be reviewed.');
        }
        $hash = @hash_file('sha256', $path);
        if (!is_string($hash)) {
            throw new ConfigurationException('Configuration cache artifact cannot be fingerprinted.');
        }
        return $hash;
    }

    private function publish(string $json, ?Closure $beforePublish = null): void
    {
        $root = $this->root(true);
        if ($root === null) {
            throw new ConfigurationException('Configuration cache directory is unavailable.');
        }
        $this->withLock($root, function () use ($root, $json, $beforePublish): void {
            $path = $root . '/' . self::FILENAME;
            $this->assertFileEntry($path);
            $beforePublish?->__invoke();
            $temporary = @tempnam($root, '.Config-');
            if ($temporary === false) {
                throw new ConfigurationException('Configuration cache temporary file is unavailable.');
            }
            try {
                @chmod($temporary, 0600);
                $handle = @fopen($temporary, 'wb');
                if ($handle === false) {
                    throw new ConfigurationException('Configuration cache temporary file is unavailable.');
                }
                try {
                    for ($offset = 0, $length = strlen($json); $offset < $length;) {
                        $written = @fwrite($handle, substr($json, $offset));
                        if ($written === false || $written === 0) {
                            throw new ConfigurationException('Configuration cache could not be written.');
                        }
                        $offset += $written;
                    }
                    if (!@fflush($handle)) {
                        throw new ConfigurationException('Configuration cache could not be flushed.');
                    }
                } finally {
                    fclose($handle);
                }
                $this->assertFileEntry($path);
                if (!@rename($temporary, $path)) {
                    throw new ConfigurationException('Configuration cache could not be activated.');
                }
            } finally {
                if (is_file($temporary)) {
                    @unlink($temporary);
                }
            }
        });
    }

    /** @return string|null Private root; reading never creates directories. */
    private function root(bool $create): ?string
    {
        $current = $this->basePath;
        foreach (['Storage', 'Cache', 'Framework'] as $segment) {
            $next = $current . '/' . $segment;
            if (is_link($next)) {
                throw new ConfigurationException('Configuration cache path is unsafe.');
            }
            if (!file_exists($next)) {
                if (!$create) {
                    return null;
                }
                if (!@mkdir($next, 0700) && !is_dir($next)) {
                    throw new ConfigurationException('Configuration cache directory cannot be created.');
                }
            }
            $resolved = realpath($next);
            if ($resolved === false || !is_dir($resolved)
                || str_replace('\\', '/', $resolved) !== $next) {
                throw new ConfigurationException('Configuration cache path is unsafe.');
            }
            $current = $next;
        }
        return $current;
    }

    private function assertFileEntry(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new ConfigurationException('Configuration cache path is unsafe.');
        }
    }

    private function withLock(string $root, callable $callback): mixed
    {
        $path = $root . '/' . self::LOCK_FILENAME;
        $this->assertFileEntry($path);
        $handle = @fopen($path, 'c+b');
        if ($handle === false) {
            throw new ConfigurationException('Configuration cache lock is unavailable.');
        }
        try {
            if (!@flock($handle, LOCK_EX)) {
                throw new ConfigurationException('Configuration cache lock is unavailable.');
            }
            $this->root(false);
            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
