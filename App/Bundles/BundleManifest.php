<?php

declare(strict_types=1);

namespace App\Bundles;

use JsonException;

/**
 * Validated, versioned source inventory. Checksums prove bytes match this
 * manifest; an unsigned manifest does not authenticate its publisher.
 */
final readonly class BundleManifest
{
    public const MAX_FILES = 10000;
    public const MAX_FILE_BYTES = 16777216;
    public const MAX_TOTAL_BYTES = 268435456;
    public const MAX_MANIFEST_BYTES = 4194304;

    /** @param array<string,mixed> $data */
    private function __construct(private array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $keys = ['format', 'framework', 'created_at', 'project', 'php', 'composer_sha256',
            'source_roots', 'activation', 'directories', 'files'];
        if (array_keys($data) !== $keys || $data['format'] !== 1
            || $data['framework'] !== 'squehub-v2'
            || !is_string($data['created_at'])
            || preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/D', $data['created_at']) !== 1
            || !is_string($data['project']) || strlen($data['project']) > 128
            || preg_match('/\A[a-z0-9][a-z0-9._-]*(?:\/[a-z0-9][a-z0-9._-]*)?\z/D', $data['project']) !== 1
            || !is_string($data['php']) || $data['php'] === '' || strlen($data['php']) > 128
            || preg_match('/[\x00-\x1F\x7F]/', $data['php']) === 1
            || !self::hash($data['composer_sha256'])
            || !is_array($data['source_roots']) || !array_is_list($data['source_roots'])
            || !is_array($data['activation']) || array_keys($data['activation']) !== ['packages', 'kits']
            || !is_array($data['directories']) || !array_is_list($data['directories'])
            || !is_array($data['files']) || !array_is_list($data['files'])
            || count($data['files']) > self::MAX_FILES || count($data['directories']) > self::MAX_FILES * 2) {
            throw new BundleException('Bundle manifest is invalid or unsupported.');
        }
        foreach (['packages', 'kits'] as $kind) {
            $items = $data['activation'][$kind];
            if (!is_array($items) || !array_is_list($items) || count($items) > 2048) {
                throw new BundleException('Bundle activation summary is invalid.');
            }
            $previous = null;
            foreach ($items as $item) {
                if (!is_array($item) || array_keys($item) !== ['name', 'enabled']
                    || !is_string($item['name']) || strlen($item['name']) > 128
                    || preg_match('/\A[A-Z][A-Za-z0-9_]*\z/D', $item['name']) !== 1
                    || !is_bool($item['enabled'])
                    || ($previous !== null && strcmp($previous, $item['name']) >= 0)) {
                    throw new BundleException('Bundle activation summary is invalid.');
                }
                $previous = $item['name'];
            }
        }
        $seenRoots = [];
        foreach ($data['source_roots'] as $root) {
            if (!is_string($root) || !in_array($root, ProjectBundle::SOURCE_ROOTS, true)
                || isset($seenRoots[$root])) {
                throw new BundleException('Bundle source root list is invalid.');
            }
            $seenRoots[$root] = true;
        }
        if ($data['source_roots'] !== array_values(array_intersect(ProjectBundle::SOURCE_ROOTS, $data['source_roots']))) {
            throw new BundleException('Bundle source root order is invalid.');
        }
        if (!isset($seenRoots['Project'], $seenRoots['composer.json'])
            || !in_array('Project', $data['directories'], true)) {
            throw new BundleException('Bundle required project source is missing.');
        }
        $seen = [];
        $previous = null;
        foreach ($data['directories'] as $directory) {
            if (!is_string($directory)) { throw new BundleException('Bundle directory is invalid.'); }
            BundlePath::requireRelative($directory);
            if (BundlePath::excluded($directory)) {
                throw new BundleException('Bundle contains an excluded directory.');
            }
            self::requireSourceRoot($directory, $seenRoots);
            self::addPath($directory, $seen);
            if ($previous !== null && strcmp($previous, $directory) >= 0) {
                throw new BundleException('Bundle directory order is invalid.');
            }
            $previous = $directory;
        }
        $previous = null;
        $total = 0;
        foreach ($data['files'] as $file) {
            if (!is_array($file) || array_keys($file) !== ['path', 'size', 'sha256']
                || !is_string($file['path']) || !is_int($file['size'])
                || $file['size'] < 0 || $file['size'] > self::MAX_FILE_BYTES
                || !self::hash($file['sha256'])) {
                throw new BundleException('Bundle file metadata is invalid.');
            }
            BundlePath::requireRelative($file['path']);
            if ($file['path'] === 'composer.json' && $file['size'] > 1048576) {
                throw new BundleException('Bundle Composer metadata exceeds the size limit.');
            }
            if (BundlePath::excluded($file['path'])) {
                throw new BundleException('Bundle contains an excluded file.');
            }
            self::requireSourceRoot($file['path'], $seenRoots);
            self::addPath($file['path'], $seen);
            if ($previous !== null && strcmp($previous, $file['path']) >= 0) {
                throw new BundleException('Bundle file order is invalid.');
            }
            $previous = $file['path'];
            $total += $file['size'];
            if ($total > self::MAX_TOTAL_BYTES) {
                throw new BundleException('Bundle expanded size exceeds the limit.');
            }
        }
        if (!in_array('composer.json', array_column($data['files'], 'path'), true)) {
            throw new BundleException('Bundle Composer metadata is missing.');
        }
        foreach ($data['files'] as $file) {
            if ($file['path'] === 'composer.json' && $file['sha256'] !== $data['composer_sha256']) {
                throw new BundleException('Bundle Composer checksum is inconsistent.');
            }
            $parts = explode('/', $file['path']);
            array_pop($parts);
            $prefix = '';
            foreach ($parts as $part) {
                $prefix = ltrim($prefix . '/' . $part, '/');
                if (!isset($seen[BundlePath::collisionKey($prefix)])
                    || $seen[BundlePath::collisionKey($prefix)] !== $prefix) {
                    throw new BundleException('Bundle file parent is missing or conflicts.');
                }
            }
        }
        return new self($data);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->data; }

    /** @return list<array{path:string,size:int,sha256:string}> */
    public function files(): array { return $this->data['files']; }

    /** @return list<string> */
    public function directories(): array { return $this->data['directories']; }

    public function project(): string { return $this->data['project']; }

    public function phpConstraint(): string { return $this->data['php']; }

    public function fingerprint(): string
    {
        try {
            return hash('sha256', json_encode($this->data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (JsonException $exception) {
            throw new BundleException('Bundle manifest cannot be encoded.', 0, $exception);
        }
    }

    private static function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** @param array<string,true> $roots */
    private static function requireSourceRoot(string $path, array $roots): void
    {
        if ($path === 'Database' && (isset($roots['Database/Migrations'])
            || isset($roots['Database/Seeders']) || isset($roots['Database/Factories']))) {
            return;
        }
        if ($path === 'public' && isset($roots['public/assets'])) { return; }
        if (($path === 'public/assets' || str_starts_with($path, 'public/assets/'))
            && isset($roots['public/assets'])) { return; }
        $top = explode('/', $path, 2)[0];
        if (isset($roots[$top])) { return; }
        if (str_starts_with($path, 'Database/') && isset($roots['Database/' . explode('/', $path, 3)[1]])) {
            return;
        }
        if (str_starts_with($path, 'public/assets/') && isset($roots['public/assets'])) { return; }
        throw new BundleException('Bundle path is outside declared source roots.');
    }

    /** @param array<string,string> $seen */
    private static function addPath(string $path, array &$seen): void
    {
        $key = BundlePath::collisionKey($path);
        if (isset($seen[$key])) {
            throw new BundleException('Bundle contains duplicate or case-colliding paths.');
        }
        $seen[$key] = $path;
    }
}
